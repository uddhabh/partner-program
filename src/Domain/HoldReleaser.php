<?php
/**
 * Moves due 'pending' commissions to 'approved' once the hold expires.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

defined( 'ABSPATH' ) || exit;

final class HoldReleaser {

	private const BATCH_SIZE = 500;

	/**
	 * Move due 'pending' commissions to 'approved'.
	 *
	 * Holds an advisory lock for the duration so concurrent wp-cron runs
	 * (loopback + WP-CLI, two web nodes, etc.) can't double-approve and
	 * fire the `commission_approved` action twice per row. Processes in
	 * batches with a LIMIT so a backlog doesn't load the whole table into
	 * memory or run past the cron timeout.
	 */
	public static function release_due(): int {
		global $wpdb;

		$lock_name = 'pp_release_holds';
		$got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 1 ) );
		if ( 1 !== $got_lock ) {
			return 0;
		}

		$count = 0;
		try {
			$now = current_time( 'mysql', true );
			while ( true ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT c.id, c.affiliate_id FROM ' . CommissionRepo::table() . ' c '
						. 'INNER JOIN ' . AffiliateRepo::table() . ' a ON a.id = c.affiliate_id '
						. "WHERE c.status = 'pending' "
						. 'AND c.hold_release_at IS NOT NULL '
						. 'AND c.hold_release_at <= %s '
						. "AND a.status = 'approved' "
						. 'LIMIT %d',
						$now,
						self::BATCH_SIZE
					),
					ARRAY_A
				) ?: [];

				if ( ! $rows ) {
					break;
				}

				// Bulk-update in one statement so a silent DB error on a
				// single row can't leave it at status=pending and cause the
				// outer while(true) to re-fetch the same batch forever.
				$ids          = array_map( static fn ( array $r ): int => (int) $r['id'], $rows );
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$updated      = (int) $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						'UPDATE ' . CommissionRepo::table() . " SET status = 'approved', updated_at = %s WHERE id IN ({$placeholders}) AND status = 'pending'",
						array_merge( [ current_time( 'mysql', true ) ], $ids )
					)
				);

				// Fire per-row action only for rows that were actually changed.
				// rows_affected == $updated, but we don't know which specific
				// IDs were skipped (concurrent update), so fire for all IDs
				// and let listeners re-check status if it matters to them.
				foreach ( $ids as $id ) {
					do_action( 'partner_program_commission_approved', $id );
				}
				$count += $updated;

				if ( count( $rows ) < self::BATCH_SIZE ) {
					break;
				}
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		return $count;
	}
}
