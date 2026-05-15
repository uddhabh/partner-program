<?php
/**
 * Compliance admin tools - prohibited-term scanner.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Compliance\ProhibitedTermsScanner;
use PartnerProgram\Compliance\ViolationManager;
use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class ComplianceScreen {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		$scan_result  = null;
		$flag_done    = 0;    // affiliate id after a completed flag
		$flag_error   = '';   // validation error message
		$flag_pending = null; // array with affiliate + reason awaiting confirmation

		if ( ! empty( $_POST['scan'] ) ) {
			check_admin_referer( 'pp_compliance_scan' );
			$text        = (string) wp_unslash( $_POST['scan_text'] ?? '' );
			$scan_result = ProhibitedTermsScanner::scan( $text );
		}

		if ( ! empty( $_POST['flag_violation'] ) ) {
			check_admin_referer( 'pp_compliance_flag' );
			$flag_id  = (int) ( $_POST['affiliate_id'] ?? 0 );
			$reason   = sanitize_textarea_field( wp_unslash( (string) ( $_POST['reason'] ?? '' ) ) );
			$confirmed = ! empty( $_POST['flag_confirmed'] );

			if ( $flag_id <= 0 ) {
				$flag_error = __( 'Please enter a valid affiliate ID.', 'partner-program' );
			} else {
				$affiliate = AffiliateRepo::find( $flag_id );
				if ( ! $affiliate ) {
					$flag_error = sprintf(
						/* translators: %d: affiliate id */
						__( 'Affiliate #%d does not exist.', 'partner-program' ),
						$flag_id
					);
				} elseif ( ! $confirmed ) {
					// First submission: show a confirmation step before acting.
					$flag_pending = [
						'affiliate' => $affiliate,
						'id'        => $flag_id,
						'reason'    => $reason,
					];
				} else {
					// Confirmed: execute the destructive action.
					ViolationManager::flag( $flag_id, $reason );
					$flag_done = $flag_id;
				}
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Compliance', 'partner-program' ) . '</h1>';

		// ── Scan section ───────────────────────────────────────────────────────

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

		if ( $scan_result ) {
			if ( $scan_result['matches'] ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Matches found:', 'partner-program' ) . '</strong> ' . esc_html( implode( ', ', $scan_result['matches'] ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'No prohibited terms detected.', 'partner-program' ) . '</p></div>';
			}
		}

		// ── Flag section ───────────────────────────────────────────────────────

		echo '<h2>' . esc_html__( 'Flag a violation', 'partner-program' ) . '</h2>';

		if ( $flag_done ) {
			$done_aff  = AffiliateRepo::find( $flag_done );
			$done_user = $done_aff ? get_userdata( (int) $done_aff['user_id'] ) : null;
			$done_label = $done_user ? $done_user->user_email : '#' . $flag_done;
			echo '<div class="notice notice-success is-dismissible"><p>'
				. sprintf(
					/* translators: %s: affiliate email or ID */
					esc_html__( 'Violation flagged and penalties applied to %s.', 'partner-program' ),
					esc_html( $done_label )
				)
				. '</p></div>';
		}

		if ( $flag_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $flag_error ) . '</p></div>';
		}

		if ( $flag_pending ) {
			// ── Confirmation step ──────────────────────────────────────────────
			$aff   = $flag_pending['affiliate'];
			$user  = get_userdata( (int) $aff['user_id'] );
			$email = $user ? $user->user_email : '—';

			echo '<div class="notice notice-warning" style="border-left-color:#d63638;">';
			echo '<p><strong>' . esc_html__( 'Confirm: flag this affiliate for a violation?', 'partner-program' ) . '</strong></p>';
			echo '<p>';
			printf(
				/* translators: 1: affiliate ID, 2: email, 3: status */
				esc_html__( 'Affiliate #%1$d &mdash; %2$s (status: %3$s)', 'partner-program' ),
				(int) $aff['id'],
				esc_html( $email ),
				esc_html( (string) $aff['status'] )
			);
			echo '</p>';
			if ( $flag_pending['reason'] ) {
				echo '<p><strong>' . esc_html__( 'Reason:', 'partner-program' ) . '</strong> ' . esc_html( $flag_pending['reason'] ) . '</p>';
			}
			echo '<p>' . esc_html__( 'This will: suspend the affiliate, reject all pending and approved commissions, and mark recently paid commissions as clawback. This cannot be undone automatically.', 'partner-program' ) . '</p>';

			echo '<form method="post" style="display:inline;">';
			wp_nonce_field( 'pp_compliance_flag' );
			echo '<input type="hidden" name="flag_violation" value="1" />';
			echo '<input type="hidden" name="flag_confirmed" value="1" />';
			printf( '<input type="hidden" name="affiliate_id" value="%d" />', (int) $aff['id'] );
			printf( '<input type="hidden" name="reason" value="%s" />', esc_attr( $flag_pending['reason'] ) );
			submit_button( __( 'Yes, flag and apply penalties', 'partner-program' ), 'delete', 'submit', false );
			echo '</form> ';

			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url( admin_url( 'admin.php?page=partner-program-compliance' ) ),
				esc_html__( 'Cancel', 'partner-program' )
			);
			echo '</div>';
		} else {
			// ── Flag entry form ────────────────────────────────────────────────
			echo '<form method="post">';
			wp_nonce_field( 'pp_compliance_flag' );
			echo '<input type="hidden" name="flag_violation" value="1" />';
			echo '<table class="form-table" role="presentation"><tbody>';
			echo '<tr><th scope="row"><label for="pp-flag-affiliate-id">' . esc_html__( 'Affiliate ID', 'partner-program' ) . '</label></th>';
			echo '<td><input type="number" id="pp-flag-affiliate-id" name="affiliate_id" min="1" required class="small-text" />';
			echo '<p class="description">' . esc_html__( 'You will be asked to confirm before any action is taken.', 'partner-program' ) . '</p>';
			echo '</td></tr>';
			echo '<tr><th scope="row"><label for="pp-flag-reason">' . esc_html__( 'Reason', 'partner-program' ) . '</label></th>';
			echo '<td><textarea id="pp-flag-reason" name="reason" rows="3" class="large-text"></textarea></td></tr>';
			echo '</tbody></table>';
			submit_button( __( 'Review and flag violation', 'partner-program' ), 'secondary' );
			echo '</form>';
		}

		echo '</div>';
	}
}
