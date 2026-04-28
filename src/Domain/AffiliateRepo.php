<?php
/**
 * Affiliate read/write repository.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

use PartnerProgram\Support\Encryption;

defined( 'ABSPATH' ) || exit;

final class AffiliateRepo {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pp_affiliates';
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Bulk-load affiliates by id, returned indexed by id. Use this from
	 * list-table screens to avoid one query per row.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, mixed>>
	 */
	public static function find_many( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $i ): bool => $i > 0 ) ) );
		if ( ! $ids ) {
			return [];
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = 'SELECT * FROM ' . self::table() . " WHERE id IN ({$placeholders})";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, ...$ids ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = [];
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	public static function find_by_user( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $user_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function find_by_code( string $code ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE referral_code = %s', $code ), ARRAY_A );
		return $row ?: null;
	}

	public static function create( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			[
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			],
			$data
		);
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $data, [ 'id' => $id ] );
	}

	public static function generate_unique_code( ?string $hint = null ): string {
		$base = $hint ? sanitize_title( $hint ) : '';
		if ( '' === $base ) {
			$base = strtolower( wp_generate_password( 6, false, false ) );
		}
		$base = preg_replace( '/[^a-z0-9]/', '', strtolower( $base ) ) ?: 'p';
		$base = substr( $base, 0, 12 );
		$try  = $base;
		$i    = 1;
		// Cap deterministic suffixes so a pathological codebase state can't
		// stall a request indefinitely — fall back to a random suffix and
		// keep going. The caller's INSERT is still guarded by the
		// referral_code UNIQUE KEY, so a stale find_by_code() snapshot
		// can't actually duplicate-insert.
		while ( null !== self::find_by_code( $try ) ) {
			if ( $i > 50 ) {
				$try = $base . '-' . strtolower( wp_generate_password( 6, false, false ) );
				if ( null === self::find_by_code( $try ) ) {
					break;
				}
				continue;
			}
			$try = $base . $i;
			++$i;
		}
		return $try;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( array $args = [] ): array {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			[
				'status'   => '',
				'search'   => '',
				'per_page' => 50,
				'page'     => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			]
		);

		$where  = '1=1';
		$params = [];
		if ( $args['status'] ) {
			$where    .= ' AND status = %s';
			$params[]  = $args['status'];
		}
		if ( $args['search'] ) {
			$where   .= ' AND (referral_code LIKE %s OR user_id IN (SELECT ID FROM ' . $wpdb->users . " WHERE user_email LIKE %s OR display_name LIKE %s))";
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$orderby = in_array( $args['orderby'], [ 'id', 'created_at', 'status' ], true ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		$limit   = max( 1, (int) $args['per_page'] );

		$sql = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
	}

	public static function decrypt_payout_details( ?string $blob ): array {
		if ( ! $blob ) {
			return [];
		}
		$enc  = new Encryption();
		$json = $enc->decrypt( $blob );
		$decoded = $json ? json_decode( $json, true ) : null;
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @throws \RuntimeException when libsodium is not loaded — callers MUST
	 *         either gate on Encryption::is_available() up front or catch
	 *         and present a user-actionable error. Never fall back to a
	 *         "store unencrypted" path.
	 */
	public static function encrypt_payout_details( array $details ): string {
		$enc  = new Encryption();
		$json = wp_json_encode( $details ) ?: '';
		return $enc->encrypt( $json );
	}
}
