<?php
/**
 * Payout / payout-items repository.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

defined( 'ABSPATH' ) || exit;

final class PayoutRepo {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pp_payouts';
	}

	public static function items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pp_payout_items';
	}

	public static function create( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			[
				'status'     => 'queued',
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

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function add_item( int $payout_id, int $commission_id, int $amount_cents ): void {
		global $wpdb;
		$wpdb->insert(
			self::items_table(),
			[
				'payout_id'     => $payout_id,
				'commission_id' => $commission_id,
				'amount_cents'  => $amount_cents,
			]
		);
	}

	public static function items_for( int $payout_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::items_table() . ' WHERE payout_id = %d', $payout_id ), ARRAY_A ) ?: [];
	}

	public static function search( array $args = [] ): array {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			[
				'affiliate_id' => 0,
				'status'       => '',
				'per_page'     => 50,
				'page'         => 1,
			]
		);
		$where  = '1=1';
		$params = [];
		if ( $args['affiliate_id'] ) {
			$where   .= ' AND affiliate_id = %d';
			$params[] = (int) $args['affiliate_id'];
		}
		if ( $args['status'] ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		$limit   = max( 1, (int) $args['per_page'] );
		$sql     = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
	}

	public static function count( array $args = [] ): int {
		global $wpdb;
		$args   = wp_parse_args( $args, [ 'affiliate_id' => 0, 'status' => '' ] );
		$where  = '1=1';
		$params = [];
		if ( $args['affiliate_id'] ) {
			$where   .= ' AND affiliate_id = %d';
			$params[] = (int) $args['affiliate_id'];
		}
		if ( $args['status'] ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_var( $sql ) );
	}
}
