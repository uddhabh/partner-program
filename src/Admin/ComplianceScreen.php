<?php
/**
 * Compliance admin tools - prohibited-term scanner.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Compliance\ProhibitedTermsScanner;
use PartnerProgram\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class ComplianceScreen {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		$result = null;
		$flagged_id = 0;
		if ( ! empty( $_POST['scan'] ) ) {
			check_admin_referer( 'pp_compliance_scan' );
			$text   = (string) wp_unslash( $_POST['scan_text'] ?? '' );
			$result = ProhibitedTermsScanner::scan( $text );
		}
		if ( ! empty( $_POST['flag_violation'] ) ) {
			check_admin_referer( 'pp_compliance_flag' );
			$flagged_id = (int) ( $_POST['affiliate_id'] ?? 0 );
			$reason     = sanitize_textarea_field( wp_unslash( (string) ( $_POST['reason'] ?? '' ) ) );
			if ( $flagged_id ) {
				\PartnerProgram\Compliance\ViolationManager::flag( $flagged_id, $reason );
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Compliance', 'partner-program' ) . '</h1>';

		echo '<h2>' . esc_html__( 'Scan promotional content', 'partner-program' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'pp_compliance_scan' );
		echo '<input type="hidden" name="scan" value="1" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pp-scan-text">' . esc_html__( 'Content', 'partner-program' ) . '</label></th>';
		echo '<td><textarea id="pp-scan-text" name="scan_text" rows="6" class="large-text" placeholder="' . esc_attr__( 'Paste promotional text or page HTML here...', 'partner-program' ) . '"></textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Scan for prohibited terms', 'partner-program' ) );
		echo '</form>';

		if ( $result ) {
			if ( $result['matches'] ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Matches found:', 'partner-program' ) . '</strong> ' . esc_html( implode( ', ', $result['matches'] ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'No prohibited terms detected.', 'partner-program' ) . '</p></div>';
			}
		}

		echo '<h2>' . esc_html__( 'Flag a violation', 'partner-program' ) . '</h2>';
		if ( $flagged_id ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Affiliate #%d flagged.', 'partner-program' ), $flagged_id ) . '</p></div>';
		}
		echo '<form method="post">';
		wp_nonce_field( 'pp_compliance_flag' );
		echo '<input type="hidden" name="flag_violation" value="1" />';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Affiliate ID', 'partner-program' ) . '</th><td><input type="number" name="affiliate_id" required /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Reason', 'partner-program' ) . '</th><td><textarea name="reason" rows="3" cols="60"></textarea></td></tr>';
		echo '</table>';
		submit_button( __( 'Flag violation and apply penalty', 'partner-program' ), 'delete' );
		echo '</form>';

		echo '</div>';
	}
}
