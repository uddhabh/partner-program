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
use PartnerProgram\Support\Encryption;
use PartnerProgram\Support\Money;

defined( 'ABSPATH' ) || exit;

final class DashboardScreen {

	public static function render(): void {
		global $wpdb;

		if ( ! Encryption::is_available() ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Encryption unavailable.', 'partner-program' ) . '</strong> '
				. esc_html__( 'The PHP sodium extension is not loaded on this server, so partners cannot save payout details. Ask your host to enable the sodium extension (bundled with PHP 7.2+).', 'partner-program' )
				. '</p></div>';
		}
		$total_affiliates = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AffiliateRepo::table() );
		$active           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AffiliateRepo::table() . " WHERE status = 'approved'" );
		$pending_apps     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $wpdb->prefix . "pp_applications WHERE status = 'pending'" );

		$pending_cents  = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_cents),0) FROM " . CommissionRepo::table() . " WHERE status = 'pending'" );
		$approved_cents = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_cents),0) FROM " . CommissionRepo::table() . " WHERE status = 'approved'" );
		$paid_cents     = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_cents),0) FROM " . CommissionRepo::table() . " WHERE status = 'paid'" );

		$queued_payouts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . PayoutRepo::table() . " WHERE status = 'queued'" );

		echo '<div class="wrap"><h1>' . esc_html__( 'Partner Program', 'partner-program' ) . '</h1>';
		echo '<div class="pp-grid">';
		self::card( __( 'Active partners', 'partner-program' ), (string) $active, sprintf( __( '%d total', 'partner-program' ), $total_affiliates ) );
		self::card( __( 'Pending applications', 'partner-program' ), (string) $pending_apps, '' );
		self::card( __( 'Pending commissions', 'partner-program' ), Money::format( $pending_cents ), __( 'In hold period', 'partner-program' ) );
		self::card( __( 'Approved (unpaid)', 'partner-program' ), Money::format( $approved_cents ), __( 'Ready for next payout batch', 'partner-program' ) );
		self::card( __( 'Paid', 'partner-program' ), Money::format( $paid_cents ), __( 'Lifetime', 'partner-program' ) );
		self::card( __( 'Queued payouts', 'partner-program' ), (string) $queued_payouts, '' );
		echo '</div>';

		echo '<p style="margin-top:1.5em;"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=partner-program-payouts' ) ) . '">' . esc_html__( 'Generate payout batch', 'partner-program' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=partner-program-settings' ) ) . '">' . esc_html__( 'Settings', 'partner-program' ) . '</a></p>';

		echo '</div>';
	}

	private static function card( string $title, string $value, string $sub ): void {
		echo '<div class="pp-card"><div class="pp-card-title">' . esc_html( $title ) . '</div>';
		echo '<div class="pp-card-value">' . esc_html( $value ) . '</div>';
		if ( $sub ) {
			echo '<div class="pp-card-sub">' . esc_html( $sub ) . '</div>';
		}
		echo '</div>';
	}
}
