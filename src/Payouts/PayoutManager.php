<?php
/**
 * Payout batch generator + CSV exporter + status transitions.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Payouts;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\PayoutRepo;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class PayoutManager {

	public function register(): void {}

	/**
	 * Generate one queued payout per affiliate whose approved-and-unpaid total ≥ threshold.
	 *
	 * Concurrency model:
	 *   1. We acquire a MySQL advisory lock (`GET_LOCK`) so two parallel
	 *      callers serialize at the DB level — no two batches see the
	 *      same approved-unclaimed commissions.
	 *   2. Each affiliate's claim is `UPDATE … WHERE payout_id IS NULL`,
	 *      and we use `rows_affected` plus a re-SELECT of the rows we
	 *      actually own to populate `pp_payout_items` and the payout
	 *      total. So even if the lock acquisition somehow fails, the
	 *      claim-by-update is still safe — never more than one payout
	 *      can claim a given commission.
	 *   3. We wrap each affiliate's claim in a transaction so a partial
	 *      failure rolls back instead of leaving a payout row pointing
	 *      at half-claimed commissions.
	 *
	 * @param string|null $period_yyyymm e.g. "2026-04". Null = use prior month.
	 * @return array{count:int, batch_id:string}
	 */
	public static function generate_batch( ?string $period_yyyymm = null ): array {
		global $wpdb;
		$settings  = new SettingsRepo();
		$threshold = Money::to_cents( (float) $settings->get( 'hold_payouts.min_threshold', 100 ) );
		$batch_id  = 'batch_' . gmdate( 'YmdHis' ) . '_' . substr( md5( wp_generate_password( 12, false, false ) ), 0, 6 );

		if ( $period_yyyymm && preg_match( '/^(\d{4})-(\d{2})$/', $period_yyyymm, $m ) ) {
			$start = sprintf( '%04d-%02d-01', (int) $m[1], (int) $m[2] );
			$end   = gmdate( 'Y-m-01', strtotime( $start . ' +1 month' ) );
		} else {
			$start = gmdate( 'Y-m-01', strtotime( '-1 month' ) );
			$end   = gmdate( 'Y-m-01' );
		}

		$lock_name = 'pp_generate_batch';
		$got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) );
		if ( 1 !== $got_lock ) {
			// Another caller holds the lock; bail rather than racing.
			return [ 'count' => 0, 'batch_id' => $batch_id ];
		}

		try {
			$rows = $wpdb->get_results(
				'SELECT affiliate_id, currency, COALESCE(SUM(amount_cents),0) as total FROM ' . CommissionRepo::table()
					. " WHERE status = 'approved' AND payout_id IS NULL GROUP BY affiliate_id, currency",
				ARRAY_A
			) ?: [];

			$count = 0;
			foreach ( $rows as $row ) {
				$affiliate_id = (int) $row['affiliate_id'];
				$preview      = (int) $row['total'];
				$currency     = (string) $row['currency'];
				if ( $preview < $threshold ) {
					continue;
				}

				$affiliate = AffiliateRepo::find( $affiliate_id );
				if ( ! $affiliate || 'approved' !== $affiliate['status'] ) {
					continue;
				}
				$method = (string) ( $affiliate['payout_method'] ?? '' );

				$wpdb->query( 'START TRANSACTION' );

				$payout_id = PayoutRepo::create(
					[
						'affiliate_id'       => $affiliate_id,
						'period_start'       => $start,
						'period_end'         => $end,
						'total_amount_cents' => 0,
						'currency'           => $currency,
						'method'             => $method,
						'status'             => 'queued',
						'csv_batch_id'       => $batch_id,
					]
				);
				if ( ! $payout_id ) {
					$wpdb->query( 'ROLLBACK' );
					continue;
				}

				// Atomic claim: only commissions still unclaimed and approved
				// get tagged with our payout_id. Returns the number of rows
				// we actually claimed; we never overwrite another batch.
				$claimed = (int) $wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . CommissionRepo::table() . " SET payout_id = %d, updated_at = %s "
							. "WHERE status = 'approved' AND payout_id IS NULL AND affiliate_id = %d AND currency = %s",
						$payout_id,
						current_time( 'mysql', true ),
						$affiliate_id,
						$currency
					)
				);

				if ( 0 === $claimed ) {
					// Another concurrent run grabbed everything between the
					// preview SELECT and our UPDATE. Drop the empty payout.
					$wpdb->delete( PayoutRepo::table(), [ 'id' => $payout_id ] );
					$wpdb->query( 'COMMIT' );
					continue;
				}

				// Re-read the rows we now own to compute the real total
				// and populate payout_items. SUM is computed from the rows
				// we actually claimed, not the pre-claim preview.
				$claimed_rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, amount_cents FROM ' . CommissionRepo::table() . ' WHERE payout_id = %d',
						$payout_id
					),
					ARRAY_A
				) ?: [];

				$real_total = 0;
				foreach ( $claimed_rows as $cr ) {
					$amt         = (int) $cr['amount_cents'];
					$real_total += $amt;
					PayoutRepo::add_item( $payout_id, (int) $cr['id'], $amt );
				}

				if ( $real_total < $threshold ) {
					// Below threshold after the real claim: release the
					// commissions and drop the payout row.
					$wpdb->query(
						$wpdb->prepare(
							'UPDATE ' . CommissionRepo::table() . ' SET payout_id = NULL, updated_at = %s WHERE payout_id = %d',
							current_time( 'mysql', true ),
							$payout_id
						)
					);
					$wpdb->delete( PayoutRepo::items_table(), [ 'payout_id' => $payout_id ] );
					$wpdb->delete( PayoutRepo::table(), [ 'id' => $payout_id ] );
					$wpdb->query( 'COMMIT' );
					continue;
				}

				PayoutRepo::update( $payout_id, [ 'total_amount_cents' => $real_total ] );
				$wpdb->query( 'COMMIT' );

				++$count;
				do_action( 'partner_program_payout_created', $payout_id );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		return [ 'count' => $count, 'batch_id' => $batch_id ];
	}

	public static function mark_paid( int $payout_id, ?string $reference = null ): void {
		$payout = PayoutRepo::find( $payout_id );
		if ( ! $payout || 'queued' !== $payout['status'] ) {
			return;
		}
		PayoutRepo::update(
			$payout_id,
			[
				'status'    => 'paid',
				'reference' => $reference,
				'paid_at'   => current_time( 'mysql', true ),
			]
		);
		$items = PayoutRepo::items_for( $payout_id );
		foreach ( $items as $item ) {
			CommissionRepo::update( (int) $item['commission_id'], [ 'status' => 'paid' ] );
		}
		do_action( 'partner_program_payout_paid', $payout_id );
	}

	public static function revert( int $payout_id ): void {
		$payout = PayoutRepo::find( $payout_id );
		if ( ! $payout || 'queued' !== $payout['status'] ) {
			return;
		}
		$items = PayoutRepo::items_for( $payout_id );
		foreach ( $items as $item ) {
			CommissionRepo::update( (int) $item['commission_id'], [ 'payout_id' => null ] );
		}
		PayoutRepo::update( $payout_id, [ 'status' => 'failed', 'notes' => 'Reverted by admin' ] );
		do_action( 'partner_program_payout_reverted', $payout_id );
	}

	public static function stream_csv_for_batch( string $batch_id ): void {
		global $wpdb;
		$payouts = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PayoutRepo::table() . ' WHERE csv_batch_id = %s ORDER BY method, affiliate_id', $batch_id
		), ARRAY_A ) ?: [];

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $batch_id ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'payout_id', 'affiliate_id', 'partner_email', 'partner_name', 'method', 'amount', 'currency', 'period_start', 'period_end', 'payout_account', 'payout_extra' ] );

		foreach ( $payouts as $p ) {
			$aff   = AffiliateRepo::find( (int) $p['affiliate_id'] );
			$user  = $aff ? get_userdata( (int) $aff['user_id'] ) : null;
			$details = $aff ? AffiliateRepo::decrypt_payout_details( $aff['payout_details'] ?? null ) : [];
			fputcsv( $out, [
				(int) $p['id'],
				(int) $p['affiliate_id'],
				$user ? $user->user_email : '',
				$user ? $user->display_name : '',
				(string) $p['method'],
				number_format( (int) $p['total_amount_cents'] / 100, 2, '.', '' ),
				(string) $p['currency'],
				(string) ( $p['period_start'] ?? '' ),
				(string) ( $p['period_end'] ?? '' ),
				(string) ( $details['account'] ?? $details['email'] ?? $details['handle'] ?? '' ),
				wp_json_encode( $details ) ?: '',
			] );
		}
		fclose( $out );
	}
}
