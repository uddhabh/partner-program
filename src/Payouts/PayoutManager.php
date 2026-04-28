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
use PartnerProgram\Support\Logger;
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

		// When the caller supplied an explicit YYYY-MM, scope the candidate
		// commissions to that month so reruns of an old period don't sweep
		// up commissions that arrived after the original batch. Without
		// --period the historical behaviour is preserved (claim every
		// approved + unclaimed commission, label the batch with last month).
		$scoped_to_period = (bool) ( $period_yyyymm && preg_match( '/^(\d{4})-(\d{2})$/', $period_yyyymm, $m ) );
		if ( $scoped_to_period ) {
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
			return [ 'count' => 0, 'batch_id' => $batch_id, 'skipped_no_method' => 0 ];
		}

		$skipped_no_method = 0;
		try {
			$base_sql = 'SELECT affiliate_id, currency, COALESCE(SUM(amount_cents),0) as total FROM ' . CommissionRepo::table()
				. " WHERE status = 'approved' AND payout_id IS NULL";
			if ( $scoped_to_period ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						$base_sql . ' AND created_at >= %s AND created_at < %s GROUP BY affiliate_id, currency',
						$start,
						$end
					),
					ARRAY_A
				) ?: [];
			} else {
				$rows = $wpdb->get_results( $base_sql . ' GROUP BY affiliate_id, currency', ARRAY_A ) ?: [];
			}

			$count  = 0;
			$logger = new Logger();
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
				if ( '' === $method ) {
					// Don't generate a payout the partner can't be paid via;
					// log so admins can chase the partner to fill out their
					// payout method, then leave the commissions unclaimed
					// for the next batch.
					$logger->warn(
						sprintf( 'Skipped affiliate #%d in batch %s: no payout method set.', $affiliate_id, $batch_id ),
						'payouts',
						[ 'affiliate_id' => $affiliate_id, 'preview_cents' => $preview, 'batch_id' => $batch_id ]
					);
					++$skipped_no_method;
					continue;
				}

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

				$claim_sql = 'UPDATE ' . CommissionRepo::table() . " SET payout_id = %d, updated_at = %s "
					. "WHERE status = 'approved' AND payout_id IS NULL AND affiliate_id = %d AND currency = %s";
				$args      = [ $payout_id, current_time( 'mysql', true ), $affiliate_id, $currency ];
				if ( $scoped_to_period ) {
					$claim_sql .= ' AND created_at >= %s AND created_at < %s';
					$args[]     = $start;
					$args[]     = $end;
				}

				// Atomic claim: only commissions still unclaimed and approved
				// (and within --period if scoped) get tagged with our
				// payout_id. We never overwrite another batch.
				$claimed = (int) $wpdb->query( $wpdb->prepare( $claim_sql, ...$args ) );

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

		return [
			'count'             => $count,
			'batch_id'          => $batch_id,
			'skipped_no_method' => $skipped_no_method,
		];
	}

	/**
	 * Approved affiliates whose approved-and-unpaid commission balance is
	 * already at or above the payout threshold but who haven't picked a
	 * payout method yet. Surfaced on the admin dashboard so admins can
	 * chase the partner before the next batch runs.
	 *
	 * @return array<int, array{affiliate_id:int, email:string, balance_cents:int}>
	 */
	public static function affiliates_pending_payout_method(): array {
		global $wpdb;
		$settings  = new SettingsRepo();
		$threshold = Money::to_cents( (float) $settings->get( 'hold_payouts.min_threshold', 100 ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.id AS affiliate_id, a.user_id, a.payout_method, COALESCE(SUM(c.amount_cents),0) AS balance '
					. 'FROM ' . AffiliateRepo::table() . ' a '
					. 'LEFT JOIN ' . CommissionRepo::table() . " c ON c.affiliate_id = a.id AND c.status = 'approved' AND c.payout_id IS NULL "
					. "WHERE a.status = 'approved' "
					. 'GROUP BY a.id '
					. 'HAVING (a.payout_method IS NULL OR a.payout_method = %s) AND balance >= %d',
				'',
				$threshold
			),
			ARRAY_A
		) ?: [];

		$out = [];
		foreach ( $rows as $row ) {
			$user  = get_userdata( (int) $row['user_id'] );
			$out[] = [
				'affiliate_id'  => (int) $row['affiliate_id'],
				'email'         => $user ? $user->user_email : '',
				'balance_cents' => (int) $row['balance'],
			];
		}
		return $out;
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
				Money::to_fixed( (int) $p['total_amount_cents'] ),
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
