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

				foreach ( $rows as $row ) {
					CommissionRepo::update(
						(int) $row['id'],
						[
							'status' => 'approved',
						]
					);
					do_action( 'partner_program_commission_approved', (int) $row['id'] );
					++$count;
				}

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
