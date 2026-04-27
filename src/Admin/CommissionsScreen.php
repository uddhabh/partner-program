<?php
/**
 * Admin commissions list with bulk actions.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Money;

defined( 'ABSPATH' ) || exit;

final class CommissionsScreen {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		self::handle_bulk();
		self::handle_manual_adjustment();

		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = CommissionRepo::search( [ 'status' => $status, 'per_page' => 100 ] );

		echo '<div class="wrap"><h1>' . esc_html__( 'Commissions', 'partner-program' ) . '</h1>';

		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Updated.', 'partner-program' ) . '</p></div>';
		}

		echo '<form method="get"><input type="hidden" name="page" value="partner-program-commissions" /><select name="status">';
		foreach ( [ '' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid', 'rejected' => 'Rejected', 'clawback' => 'Clawback' ] as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $status, $val, false ), esc_html( $label ) );
		}
		echo '</select> ' . get_submit_button( __( 'Filter', 'partner-program' ), 'secondary', 'submit', false ) . '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=partner-program-commissions' ) ) . '">';
		wp_nonce_field( 'pp_bulk_commissions' );
		echo '<select name="bulk_action">';
		echo '<option value="">' . esc_html__( '— Bulk action —', 'partner-program' ) . '</option>';
		echo '<option value="approve">' . esc_html__( 'Approve', 'partner-program' ) . '</option>';
		echo '<option value="reject">' . esc_html__( 'Reject', 'partner-program' ) . '</option>';
		echo '<option value="clawback">' . esc_html__( 'Mark clawback', 'partner-program' ) . '</option>';
		echo '</select> ' . get_submit_button( __( 'Apply', 'partner-program' ), 'secondary', 'apply_bulk', false );

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<td><input type="checkbox" id="cb-select-all" onclick="document.querySelectorAll(\'input[name=\\\'ids[]\\\']\').forEach(c=>c.checked=this.checked)" /></td>'
			. '<th>ID</th><th>' . esc_html__( 'Affiliate', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Order', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Source', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Base', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Rate %', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Amount', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Releases', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Created', 'partner-program' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$aff = AffiliateRepo::find( (int) $row['affiliate_id'] );
			$user = $aff ? get_userdata( (int) $aff['user_id'] ) : null;
			echo '<tr>';
			echo '<td><input type="checkbox" name="ids[]" value="' . (int) $row['id'] . '" /></td>';
			echo '<td>#' . (int) $row['id'] . '</td>';
			echo '<td>' . esc_html( $user ? $user->user_email : '#' . $row['affiliate_id'] ) . '</td>';
			$order_id = isset( $row['order_id'] ) && '' !== $row['order_id'] && null !== $row['order_id'] ? (int) $row['order_id'] : 0;
			if ( $order_id > 0 ) {
				echo '<td><a href="' . esc_url( get_edit_post_link( $order_id ) ?: '#' ) . '">#' . $order_id . '</a></td>';
			} else {
				echo '<td>—</td>';
			}
			echo '<td>' . esc_html( (string) $row['source'] ) . ( $row['coupon_used'] ? ' ★' : '' ) . '</td>';
			echo '<td>' . esc_html( Money::format( (int) $row['base_amount_cents'], (string) $row['currency'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['rate'] ) . '</td>';
			echo '<td>' . esc_html( Money::format( (int) $row['amount_cents'], (string) $row['currency'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['hold_release_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '</tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="11">' . esc_html__( 'None.', 'partner-program' ) . '</td></tr>';
		}
		echo '</tbody></table></form>';

		echo '<h2>' . esc_html__( 'Manual adjustment', 'partner-program' ) . '</h2>';
		echo '<form method="post"><input type="hidden" name="manual_adjustment" value="1" />';
		wp_nonce_field( 'pp_manual_adjustment' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Affiliate ID', 'partner-program' ) . '</th><td><input type="number" name="affiliate_id" required /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Amount (e.g. 25 or -10)', 'partner-program' ) . '</th><td><input type="number" step="0.01" name="amount" required /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Notes', 'partner-program' ) . '</th><td><input type="text" name="notes" class="regular-text" /></td></tr>';
		echo '</table>';
		submit_button( __( 'Add manual adjustment', 'partner-program' ) );
		echo '</form>';

		echo '</div>';
	}

	private static function handle_bulk(): void {
		if ( empty( $_POST['bulk_action'] ) || empty( $_POST['ids'] ) ) {
			return;
		}
		check_admin_referer( 'pp_bulk_commissions' );
		$action = sanitize_key( (string) $_POST['bulk_action'] );
		$ids    = array_map( 'intval', (array) $_POST['ids'] );
		$map    = [ 'approve' => 'approved', 'reject' => 'rejected', 'clawback' => 'clawback' ];
		if ( ! isset( $map[ $action ] ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			CommissionRepo::update( $id, [ 'status' => $map[ $action ] ] );
		}
		wp_safe_redirect( add_query_arg( 'done', 1, admin_url( 'admin.php?page=partner-program-commissions' ) ) );
		exit;
	}

	private static function handle_manual_adjustment(): void {
		if ( empty( $_POST['manual_adjustment'] ) ) {
			return;
		}
		check_admin_referer( 'pp_manual_adjustment' );
		$affiliate_id = (int) ( $_POST['affiliate_id'] ?? 0 );
		$amount       = (float) ( $_POST['amount'] ?? 0 );
		$notes        = sanitize_text_field( wp_unslash( (string) ( $_POST['notes'] ?? '' ) ) );
		if ( ! $affiliate_id || 0.0 === $amount ) {
			return;
		}
		CommissionRepo::create(
			[
				'affiliate_id'      => $affiliate_id,
				// NULL keeps each adjustment independent under the unique
				// (order_id) index; real orders are still 1-row-per-order.
				'order_id'          => null,
				'base_amount_cents' => 0,
				'rate'              => 0,
				'amount_cents'      => Money::to_cents( $amount ),
				'currency'          => get_woocommerce_currency() ?: 'USD',
				'status'            => 'approved',
				'source'            => 'adjustment',
				'notes'             => $notes ?: 'Manual adjustment',
			]
		);
		wp_safe_redirect( add_query_arg( 'done', 1, admin_url( 'admin.php?page=partner-program-commissions' ) ) );
		exit;
	}
}
