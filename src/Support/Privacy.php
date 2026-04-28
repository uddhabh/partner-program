<?php
/**
 * GDPR exporter / eraser hooks for affiliate data.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\AgreementRepo;
use PartnerProgram\Domain\ApplicationRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\PayoutRepo;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	public const GROUP_ID = 'partner-program';

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters[ self::GROUP_ID ] = [
			'exporter_friendly_name' => __( 'Partner Program', 'partner-program' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers[ self::GROUP_ID ] = [
			'eraser_friendly_name' => __( 'Partner Program', 'partner-program' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * Bundles every partner-program record we hold for the visitor's email
	 * into the WordPress personal-data export. We resolve the affiliate
	 * by user_id and applications by submitted email, so visitors who
	 * applied but were never approved are still covered.
	 *
	 * Files referenced by applications are surfaced by attachment id, not
	 * downloaded inline — actual export of bytes is the admin's call via
	 * the private-uploads proxy.
	 */
	public static function export( string $email_address, int $page = 1 ): array {
		$out  = [];
		$user = get_user_by( 'email', $email_address );
		$aff  = $user ? AffiliateRepo::find_by_user( (int) $user->ID ) : null;

		if ( $aff ) {
			$out[] = [
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Partner Program — Affiliate', 'partner-program' ),
				'item_id'     => 'affiliate-' . (int) $aff['id'],
				'data'        => [
					[ 'name' => __( 'Affiliate ID', 'partner-program' ),  'value' => (int) $aff['id'] ],
					[ 'name' => __( 'Referral code', 'partner-program' ), 'value' => (string) $aff['referral_code'] ],
					[ 'name' => __( 'Status', 'partner-program' ),        'value' => (string) $aff['status'] ],
					[ 'name' => __( 'Payout method', 'partner-program' ), 'value' => (string) ( $aff['payout_method'] ?? '' ) ],
					[ 'name' => __( 'Created', 'partner-program' ),       'value' => (string) $aff['created_at'] ],
				],
			];
		}

		// Applications by submitted email — these can exist before/without an
		// approved affiliate row.
		global $wpdb;
		$apps = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, status, submitted_data, uploaded_ids, created_at, reviewed_at FROM '
					. ApplicationRepo::table() . ' WHERE email = %s ORDER BY created_at ASC',
				$email_address
			),
			ARRAY_A
		) ?: [];
		foreach ( $apps as $a ) {
			$submitted = json_decode( (string) ( $a['submitted_data'] ?? '' ), true );
			$uploads   = json_decode( (string) ( $a['uploaded_ids'] ?? '' ), true );
			$rows      = [
				[ 'name' => __( 'Application ID', 'partner-program' ), 'value' => (int) $a['id'] ],
				[ 'name' => __( 'Status', 'partner-program' ),         'value' => (string) $a['status'] ],
				[ 'name' => __( 'Submitted', 'partner-program' ),      'value' => (string) $a['created_at'] ],
				[ 'name' => __( 'Reviewed', 'partner-program' ),       'value' => (string) ( $a['reviewed_at'] ?? '' ) ],
			];
			if ( is_array( $submitted ) ) {
				foreach ( $submitted as $k => $v ) {
					$rows[] = [
						'name'  => self::humanize( (string) $k ),
						'value' => is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v ),
					];
				}
			}
			if ( is_array( $uploads ) && $uploads ) {
				$rows[] = [
					'name'  => __( 'Uploaded file IDs', 'partner-program' ),
					'value' => implode( ', ', array_map( 'intval', $uploads ) ),
				];
			}
			$out[] = [
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Partner Program — Applications', 'partner-program' ),
				'item_id'     => 'application-' . (int) $a['id'],
				'data'        => $rows,
			];
		}

		if ( $aff ) {
			$commissions = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, order_id, status, amount_cents, currency, created_at FROM '
						. CommissionRepo::table() . ' WHERE affiliate_id = %d ORDER BY created_at ASC',
					(int) $aff['id']
				),
				ARRAY_A
			) ?: [];
			foreach ( $commissions as $c ) {
				$out[] = [
					'group_id'    => self::GROUP_ID,
					'group_label' => __( 'Partner Program — Commissions', 'partner-program' ),
					'item_id'     => 'commission-' . (int) $c['id'],
					'data'        => [
						[ 'name' => __( 'Commission ID', 'partner-program' ), 'value' => (int) $c['id'] ],
						[ 'name' => __( 'Order ID', 'partner-program' ),      'value' => (int) ( $c['order_id'] ?? 0 ) ],
						[ 'name' => __( 'Status', 'partner-program' ),        'value' => (string) $c['status'] ],
						[ 'name' => __( 'Amount', 'partner-program' ),        'value' => Money::format( (int) $c['amount_cents'], (string) $c['currency'] ) ],
						[ 'name' => __( 'Created', 'partner-program' ),       'value' => (string) $c['created_at'] ],
					],
				];
			}

			$payouts = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, period_start, period_end, status, total_amount_cents, currency, paid_at FROM '
						. PayoutRepo::table() . ' WHERE affiliate_id = %d ORDER BY created_at ASC',
					(int) $aff['id']
				),
				ARRAY_A
			) ?: [];
			foreach ( $payouts as $p ) {
				$out[] = [
					'group_id'    => self::GROUP_ID,
					'group_label' => __( 'Partner Program — Payouts', 'partner-program' ),
					'item_id'     => 'payout-' . (int) $p['id'],
					'data'        => [
						[ 'name' => __( 'Payout ID', 'partner-program' ), 'value' => (int) $p['id'] ],
						[ 'name' => __( 'Period', 'partner-program' ),    'value' => trim( (string) ( $p['period_start'] ?? '' ) . ' → ' . (string) ( $p['period_end'] ?? '' ) ) ],
						[ 'name' => __( 'Status', 'partner-program' ),    'value' => (string) $p['status'] ],
						[ 'name' => __( 'Amount', 'partner-program' ),    'value' => Money::format( (int) $p['total_amount_cents'], (string) $p['currency'] ) ],
						[ 'name' => __( 'Paid at', 'partner-program' ),   'value' => (string) ( $p['paid_at'] ?? '' ) ],
					],
				];
			}

			// Compliance agreement acceptances. The legal record of which
			// version was accepted when stays even after an erasure, but
			// the per-acceptance ip_hash is wiped in erase().
			$acceptances = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT acc.id, acc.accepted_at, ag.version, ag.summary FROM '
						. AgreementRepo::acceptances_table() . ' acc '
						. 'LEFT JOIN ' . AgreementRepo::table() . ' ag ON ag.id = acc.agreement_id '
						. 'WHERE acc.affiliate_id = %d ORDER BY acc.accepted_at ASC',
					(int) $aff['id']
				),
				ARRAY_A
			) ?: [];
			foreach ( $acceptances as $row ) {
				$out[] = [
					'group_id'    => self::GROUP_ID,
					'group_label' => __( 'Partner Program — Agreement acceptances', 'partner-program' ),
					'item_id'     => 'agreement-acceptance-' . (int) $row['id'],
					'data'        => [
						[ 'name' => __( 'Agreement version', 'partner-program' ), 'value' => (int) ( $row['version'] ?? 0 ) ],
						[ 'name' => __( 'Summary', 'partner-program' ),           'value' => (string) ( $row['summary'] ?? '' ) ],
						[ 'name' => __( 'Accepted at', 'partner-program' ),       'value' => (string) $row['accepted_at'] ],
					],
				];
			}

			// Visits are pseudonymous (ip_hash, not raw IP) but still
			// include them so the export is complete. Returned in
			// aggregate to keep export size reasonable for prolific
			// referrers.
			$visits_table = $wpdb->prefix . 'pp_visits';
			$visit_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$visits_table} WHERE affiliate_id = %d", (int) $aff['id'] ) );
			if ( $visit_count > 0 ) {
				$first = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$visits_table} WHERE affiliate_id = %d", (int) $aff['id'] ) );
				$last  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM {$visits_table} WHERE affiliate_id = %d", (int) $aff['id'] ) );
				$out[] = [
					'group_id'    => self::GROUP_ID,
					'group_label' => __( 'Partner Program — Referral visits', 'partner-program' ),
					'item_id'     => 'visits-summary',
					'data'        => [
						[ 'name' => __( 'Total visits', 'partner-program' ), 'value' => $visit_count ],
						[ 'name' => __( 'First seen', 'partner-program' ),   'value' => $first ],
						[ 'name' => __( 'Last seen', 'partner-program' ),    'value' => $last ],
						[ 'name' => __( 'Note', 'partner-program' ),         'value' => __( 'IP addresses are stored as one-way hashes.', 'partner-program' ) ],
					],
				];
			}
		}

		return [ 'data' => $out, 'done' => true ];
	}

	/**
	 * Erases payout PII, application submitted_data, attached uploads, and
	 * the affiliate's payout_method. Commission/payout *amounts* are kept
	 * (financial records, accounting compliance) but the linkable PII is
	 * removed from the affiliate row that ties them together.
	 */
	public static function erase( string $email_address, int $page = 1 ): array {
		$messages = [];
		$removed  = 0;
		$retained = 0;

		$user = get_user_by( 'email', $email_address );
		$aff  = $user ? AffiliateRepo::find_by_user( (int) $user->ID ) : null;

		global $wpdb;

		// Wipe affiliate payout PII.
		if ( $aff ) {
			AffiliateRepo::update(
				(int) $aff['id'],
				[
					'payout_method'  => null,
					'payout_details' => null,
					'notes'          => null,
				]
			);
			++$removed;

			// Keep the legal record that this affiliate accepted version N
			// of the agreement at time T, but wipe the per-acceptance
			// ip_hash since it links the acceptance back to the user.
			$wpdb->update(
				AgreementRepo::acceptances_table(),
				[ 'ip_hash' => null ],
				[ 'affiliate_id' => (int) $aff['id'] ]
			);

			$paid_total = CommissionRepo::sum_for_affiliate( (int) $aff['id'], 'paid' );
			if ( $paid_total > 0 ) {
				++$retained;
				$messages[] = __( 'Historical commission and payout records retained for accounting compliance.', 'partner-program' );
			}
		}

		// Application submitted_data + uploads (covers applications submitted
		// before the affiliate was approved, or applications without a user).
		$apps = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, uploaded_ids FROM ' . ApplicationRepo::table() . ' WHERE email = %s',
				$email_address
			),
			ARRAY_A
		) ?: [];
		foreach ( $apps as $a ) {
			$uploads = json_decode( (string) ( $a['uploaded_ids'] ?? '' ), true );
			if ( is_array( $uploads ) ) {
				foreach ( $uploads as $attachment_id ) {
					$attachment_id = (int) $attachment_id;
					if ( $attachment_id <= 0 ) {
						continue;
					}
					// wp_delete_attachment can return false on filesystem
					// failure (S3 hiccup, missing perms). Surface that as
					// retained-not-removed so the admin can retry.
					if ( false === wp_delete_attachment( $attachment_id, true ) ) {
						++$retained;
						$messages[] = sprintf(
							/* translators: %d: attachment ID. */
							__( 'Could not delete uploaded attachment #%d; retry the eraser or remove it manually.', 'partner-program' ),
							$attachment_id
						);
					}
				}
			}
			ApplicationRepo::update(
				(int) $a['id'],
				[
					'submitted_data' => '{}',
					'uploaded_ids'   => null,
				]
			);
			++$removed;
		}

		if ( ! $messages ) {
			$messages[] = __( 'Removed payout details, application submissions, and uploaded files.', 'partner-program' );
		}

		return [
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		];
	}

	private static function humanize( string $key ): string {
		return ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
	}
}
