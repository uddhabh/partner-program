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

	public function register(): void {
		// Hooks registered in Plugin::boot().
	}

	/**
	 * Tiers, normalized: always sorted by `min` ASC and every entry has a
	 * stable `key`. Auto-fills missing keys for old saved data so reads keep
	 * working even before the v1.1 migration backfills the option row.
	 *
	 * @return array<int, array{key:string,min:float,max:?float,rate:float,label?:string}>
	 */
	public static function tiers(): array {
		$settings = new SettingsRepo();
		$raw      = $settings->get( 'tiers', [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$used  = [];
		$out   = [];
		foreach ( array_values( $raw ) as $i => $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			if ( empty( $t['key'] ) ) {
				$base = sanitize_title( (string) ( $t['label'] ?? '' ) );
				if ( '' === $base ) {
					$base = 'tier-' . ( $i + 1 );
				}
				$key = $base;
				$n   = 2;
				while ( in_array( $key, $used, true ) ) {
					$key = $base . '-' . $n;
					++$n;
				}
				$t['key'] = $key;
			}
			$used[] = (string) $t['key'];
			$out[]  = $t;
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

	public static function tier_key_for_sales_cents( int $sales_cents ): ?string {
		$dollars = $sales_cents / 100;
		$picked  = null;
		foreach ( self::tiers() as $t ) {
			$min = (float) ( $t['min'] ?? 0 );
			$max = isset( $t['max'] ) && null !== $t['max'] && '' !== $t['max'] ? (float) $t['max'] : null;
			if ( $dollars >= $min && ( null === $max || $dollars <= $max ) ) {
				$picked = (string) ( $t['key'] ?? '' );
			}
		}
		return $picked ?: null;
	}

	public static function recalculate_all(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id FROM ' . AffiliateRepo::table() . " WHERE status = 'approved'", ARRAY_A ) ?: [];
		foreach ( $rows as $row ) {
			self::recalculate_for( (int) $row['id'] );
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
