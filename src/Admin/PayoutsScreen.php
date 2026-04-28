<?php
/**
 * Admin payouts screen with batch generation and CSV export.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\PayoutRepo;
use PartnerProgram\Payouts\PayoutManager;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Money;

defined( 'ABSPATH' ) || exit;

final class PayoutsScreen {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		self::handle_actions();

		$rows       = PayoutRepo::search( [ 'per_page' => 100 ] );
		$affiliates = AffiliateRepo::find_many( array_map( static fn ( $r ): int => (int) $r['affiliate_id'], $rows ) );
		cache_users( array_values( array_filter( array_map( static fn ( $a ): int => (int) ( $a['user_id'] ?? 0 ), $affiliates ) ) ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Payouts', 'partner-program' ) . '</h1>';

		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Done.', 'partner-program' ) . '</p></div>';
		}

		echo '<form method="post" style="margin-bottom:1em;"><input type="hidden" name="pp_action" value="generate_batch" />';
		wp_nonce_field( 'pp_payout_batch' );
		echo '<label>' . esc_html__( 'Period (YYYY-MM, optional)', 'partner-program' ) . ' <input type="text" name="period" pattern="\d{4}-\d{2}" placeholder="' . esc_attr( gmdate( 'Y-m', strtotime( '-1 month' ) ) ) . '" /></label> ';
		echo get_submit_button( __( 'Generate payout batch', 'partner-program' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>ID</th><th>' . esc_html__( 'Affiliate', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Period', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Method', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Amount', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Reference', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Actions', 'partner-program' ) . '</th>'
			. '</tr></thead><tbody>';

		$batches = [];
		foreach ( $rows as $row ) {
			$aff  = $affiliates[ (int) $row['affiliate_id'] ] ?? null;
			$user = $aff ? get_userdata( (int) $aff['user_id'] ) : null;
			echo '<tr>';
			echo '<td>#' . (int) $row['id'] . '</td>';
			echo '<td>' . esc_html( $user ? $user->user_email : '#' . $row['affiliate_id'] ) . '</td>';
			echo '<td>' . esc_html( ( $row['period_start'] ?? '' ) . ' / ' . ( $row['period_end'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['method'] ) . '</td>';
			echo '<td>' . esc_html( Money::format( (int) $row['total_amount_cents'], (string) $row['currency'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['reference'] ) . '</td>';
			echo '<td>';
			if ( 'queued' === $row['status'] ) {
				$mark_paid_url = wp_nonce_url(
					add_query_arg( [ 'pp_action' => 'mark_paid', 'id' => (int) $row['id'] ], admin_url( 'admin.php?page=partner-program-payouts' ) ),
					'pp_payout_action_' . $row['id']
				);
				echo '<a href="' . esc_url( $mark_paid_url ) . '">' . esc_html__( 'Mark paid', 'partner-program' ) . '</a> | ';
				$revert_url = wp_nonce_url(
					add_query_arg( [ 'pp_action' => 'revert', 'id' => (int) $row['id'] ], admin_url( 'admin.php?page=partner-program-payouts' ) ),
					'pp_payout_action_' . $row['id']
				);
				echo '<a href="' . esc_url( $revert_url ) . '">' . esc_html__( 'Revert', 'partner-program' ) . '</a>';
			}
			echo '</td></tr>';

			if ( $row['csv_batch_id'] ) {
				$batches[ $row['csv_batch_id'] ] = true;
			}
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No payouts yet.', 'partner-program' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $batches ) {
			echo '<h2>' . esc_html__( 'CSV exports', 'partner-program' ) . '</h2><ul>';
			foreach ( array_keys( $batches ) as $batch ) {
				$url = wp_nonce_url(
					add_query_arg( [ 'pp_action' => 'export_csv', 'batch' => urlencode( (string) $batch ) ], admin_url( 'admin.php?page=partner-program-payouts' ) ),
					'pp_payout_export_' . $batch
				);
				echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( (string) $batch ) . '</a></li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	private static function handle_actions(): void {
		$pp_action = isset( $_REQUEST['pp_action'] ) ? sanitize_key( (string) $_REQUEST['pp_action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $pp_action ) {
			return;
		}

		if ( 'generate_batch' === $pp_action ) {
			check_admin_referer( 'pp_payout_batch' );
			$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['period'] ) ) : '';
			$result = PayoutManager::generate_batch( $period ?: null );
			wp_safe_redirect( add_query_arg( 'done', $result['count'] ?? 0, admin_url( 'admin.php?page=partner-program-payouts' ) ) );
			exit;
		}

		$id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'mark_paid' === $pp_action && $id ) {
			check_admin_referer( 'pp_payout_action_' . $id );
			PayoutManager::mark_paid( $id );
			wp_safe_redirect( add_query_arg( 'done', 1, admin_url( 'admin.php?page=partner-program-payouts' ) ) );
			exit;
		}

		if ( 'revert' === $pp_action && $id ) {
			check_admin_referer( 'pp_payout_action_' . $id );
			PayoutManager::revert( $id );
			wp_safe_redirect( add_query_arg( 'done', 1, admin_url( 'admin.php?page=partner-program-payouts' ) ) );
			exit;
		}

		if ( 'export_csv' === $pp_action ) {
			$batch = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['batch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'pp_payout_export_' . $batch );
			PayoutManager::stream_csv_for_batch( $batch );
			exit;
		}
	}
}
