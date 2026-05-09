<?php
/**
 * Admin dashboard quick-stats.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\PayoutRepo;
use PartnerProgram\Payouts\PayoutManager;
use PartnerProgram\Support\Encryption;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\Ui;

defined( 'ABSPATH' ) || exit;

final class DashboardScreen {

	public static function render(): void {
		global $wpdb;

		if ( ! Encryption::is_available() ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Encryption unavailable.', 'partner-program' ) . '</strong> '
				. esc_html__( 'The PHP sodium extension is not loaded on this server, so partners cannot save payout details. Ask your host to enable the sodium extension (bundled with PHP 7.2+).', 'partner-program' )
				. '</p></div>';
		}
		$total_affiliates = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AffiliateRepo::table() );
		$active           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AffiliateRepo::table() . " WHERE status = 'approved'" );
		$pending_apps     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $wpdb->prefix . "pp_applications WHERE status = 'pending'" );

		$pending_cents  = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_cents),0) FROM " . CommissionRepo::table() . " WHERE status = 'pending'" );
		$approved_cents = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_cents),0) FROM " . CommissionRepo::table() . " WHERE status = 'approved'" );
		$paid_cents     = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_cents),0) FROM " . CommissionRepo::table() . " WHERE status = 'paid'" );

		$queued_payouts    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . PayoutRepo::table() . " WHERE status = 'queued'" );
		$pending_method    = PayoutManager::affiliates_pending_payout_method();
		$pending_method_n  = count( $pending_method );

		echo '<div class="wrap"><h1>' . esc_html__( 'Partner Program', 'partner-program' ) . '</h1>';

		if ( $pending_method_n > 0 ) {
			echo '<div class="notice notice-warning is-dismissible"><p><strong>'
				. esc_html(
					sprintf(
						/* translators: %d: number of partners. */
						_n(
							'%d partner has earnings ready to pay out but no payout method on file.',
							'%d partners have earnings ready to pay out but no payout method on file.',
							$pending_method_n,
							'partner-program'
						),
						$pending_method_n
					)
				)
				. '</strong> '
				. esc_html__( "They'll be skipped on the next batch run.", 'partner-program' )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=partner-program-affiliates' ) ) . '">'
				. esc_html__( 'View partners', 'partner-program' )
				. '</a></p></div>';
		}

		Ui::stat_cards( [
			[
				'title' => __( 'Active partners', 'partner-program' ),
				'value' => (string) $active,
				/* translators: %d: total partner count. */
				'sub'   => sprintf( __( '%d total', 'partner-program' ), $total_affiliates ),
			],
			[ 'title' => __( 'Pending applications', 'partner-program' ), 'value' => (string) $pending_apps ],
			[ 'title' => __( 'Pending commissions', 'partner-program' ), 'value' => Money::format( $pending_cents ),  'sub' => __( 'In hold period', 'partner-program' ) ],
			[ 'title' => __( 'Approved (unpaid)', 'partner-program' ),   'value' => Money::format( $approved_cents ), 'sub' => __( 'Ready for next payout batch', 'partner-program' ) ],
			[ 'title' => __( 'Paid', 'partner-program' ),                'value' => Money::format( $paid_cents ),     'sub' => __( 'Lifetime', 'partner-program' ) ],
			[ 'title' => __( 'Queued payouts', 'partner-program' ),      'value' => (string) $queued_payouts ],
			[ 'title' => __( 'Pending payout setup', 'partner-program' ),'value' => (string) $pending_method_n,       'sub' => __( 'Approved partners with a balance but no method', 'partner-program' ) ],
		] );

		echo '<p class="pp-admin-cta"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=partner-program-payouts' ) ) . '">' . esc_html__( 'Generate payout batch', 'partner-program' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=partner-program-settings' ) ) . '">' . esc_html__( 'Settings', 'partner-program' ) . '</a></p>';

		echo '</div>';
	}
}
