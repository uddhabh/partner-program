<?php
/**
 * Tier resolver - sums prior month's approved commission BASE for each affiliate
 * (gross sales attributed) and locks current_tier_key to the matching tier.
 *
 * Tiers are matched by stable `key` (string) rather than positional index so
 * that admins reordering tiers in settings doesn't silently shift every
 * affiliate's currently-assigned tier.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class TierResolver {

	/**
	 * Tiers, as stored. Tiers are normalized (unique keys, sorted by `min`
	 * ASC) at save / import time via TierResolver::normalize(); the read
	 * path trusts that contract.
	 *
	 * @return array<int, array{key:string,min:float,max:?float,rate:float,label?:string}>
	 */
	public static function tiers(): array {
		$raw = ( new SettingsRepo() )->get( 'tiers', [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Coerce raw tier rows from the settings form (or an import file) into
	 * the canonical stored shape: rate-required, key auto-generated and
	 * deduped, sorted by min ASC.
	 *
	 * @param array<int, mixed> $rows
	 * @return array<int, array{key:string,label:string,min:float,max:?float,rate:float}>
	 */
	public static function normalize( array $rows ): array {
		$out  = [];
		$used = [];
		foreach ( array_values( $rows ) as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rate = isset( $row['rate'] ) && '' !== $row['rate'] ? (float) $row['rate'] : null;
			if ( null === $rate ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$key   = sanitize_title( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key ) {
				$key = sanitize_title( $label );
			}
			if ( '' === $key ) {
				$key = 'tier-' . ( $i + 1 );
			}
			$base = $key;
			$n    = 2;
			while ( in_array( $key, $used, true ) ) {
				$key = $base . '-' . $n;
				++$n;
			}
			$used[] = $key;

			$out[] = [
				'key'   => $key,
				'label' => $label,
				'min'   => isset( $row['min'] ) && '' !== $row['min'] ? (float) $row['min'] : 0.0,
				'max'   => isset( $row['max'] ) && '' !== $row['max'] ? (float) $row['max'] : null,
				'rate'  => $rate,
			];
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return (float) ( $a['min'] ?? 0 ) <=> (float) ( $b['min'] ?? 0 );
			}
		);

		return $out;
	}

	public static function tier_for_key( string $key ): ?array {
		if ( '' === $key ) {
			return null;
		}
		foreach ( self::tiers() as $t ) {
			if ( (string) ( $t['key'] ?? '' ) === $key ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * Highest tier whose `min` ≤ sales-in-dollars. Tiers are sorted ASC by
	 * min in `tiers()`, so the last match is the tightest fit. The `max`
	 * field is now informational only — using inclusive `[min, max]` would
	 * leak fractional sales between consecutive tiers (e.g. $4999.50 with
	 * 0–4999 / 5000–14999 / 15000+ matches neither tier1 nor tier2).
	 */
	public static function tier_key_for_sales_cents( int $sales_cents ): ?string {
		$dollars = $sales_cents / 100;
		$picked  = null;
		foreach ( self::tiers() as $t ) {
			$min = (float) ( $t['min'] ?? 0 );
			if ( $dollars >= $min ) {
				$picked = (string) ( $t['key'] ?? '' );
			}
		}
		return $picked ?: null;
	}

	public static function recalculate_all(): void {
		global $wpdb;

		$lock_name = 'pp_recalculate_tiers';
		$got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 1 ) );
		if ( 1 !== $got_lock ) {
			return;
		}

		try {
			$rows = $wpdb->get_results( 'SELECT id FROM ' . AffiliateRepo::table() . " WHERE status = 'approved'", ARRAY_A ) ?: [];
			foreach ( $rows as $row ) {
				self::recalculate_for( (int) $row['id'] );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	public static function recalculate_for( int $affiliate_id ): void {
		$tz = wp_timezone();

		$now        = new \DateTimeImmutable( 'now', $tz );
		$prev_start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
		$prev_end   = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		global $wpdb;
		$sales_cents = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(base_amount_cents),0) FROM ' . CommissionRepo::table() . " WHERE affiliate_id = %d AND status IN ('approved','paid') AND created_at >= %s AND created_at < %s",
				$affiliate_id,
				$prev_start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				$prev_end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )
			)
		);

		$tier_key = self::tier_key_for_sales_cents( $sales_cents );
		AffiliateRepo::update( $affiliate_id, [ 'current_tier_key' => $tier_key ] );

		do_action( 'partner_program_tier_recalculated', $affiliate_id, $tier_key, $sales_cents );
	}

	public static function progress_for_affiliate( int $affiliate_id ): array {
		$tz    = wp_timezone();
		$now   = new \DateTimeImmutable( 'now', $tz );
		$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		global $wpdb;
		$sales_cents = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(base_amount_cents),0) FROM ' . CommissionRepo::table() . " WHERE affiliate_id = %d AND status IN ('pending','approved','paid') AND created_at >= %s",
				$affiliate_id,
				$start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )
			)
		);

		$tiers       = self::tiers();
		$current_key = self::tier_key_for_sales_cents( $sales_cents );

		$current_idx = null;
		foreach ( $tiers as $i => $t ) {
			if ( (string) ( $t['key'] ?? '' ) === (string) $current_key ) {
				$current_idx = $i;
				break;
			}
		}

		// `tiers()` already sorts by min, so the next tier is the next index.
		$current = null !== $current_idx ? $tiers[ $current_idx ] : null;
		$next    = null !== $current_idx && isset( $tiers[ $current_idx + 1 ] ) ? $tiers[ $current_idx + 1 ] : null;

		return [
			'current_sales_cents' => $sales_cents,
			'current_tier_key'    => $current_key,
			'current_tier'        => $current,
			'next_tier'           => $next,
		];
	}
}
