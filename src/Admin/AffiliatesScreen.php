<?php
/**
 * Admin affiliates list.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Money;

defined( 'ABSPATH' ) || exit;

final class AffiliatesScreen {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		self::handle_actions();

		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$rows = AffiliateRepo::search( [ 'status' => $status, 'search' => $search, 'page' => $page, 'per_page' => 50 ] );

		echo '<div class="wrap"><h1>' . esc_html__( 'Affiliates', 'partner-program' ) . '</h1>';

		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Updated.', 'partner-program' ) . '</p></div>';
		}

		echo '<form method="get"><input type="hidden" name="page" value="partner-program-affiliates" />';
		echo '<select name="status">';
		foreach ( [ '' => __( 'All statuses', 'partner-program' ), 'pending' => 'Pending', 'approved' => 'Approved', 'suspended' => 'Suspended', 'rejected' => 'Rejected' ] as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $status, $val, false ), esc_html( $label ) );
		}
		echo '</select> ';
		printf( '<input type="search" name="s" value="%s" placeholder="%s" /> ', esc_attr( $search ), esc_attr__( 'Search by code or email', 'partner-program' ) );
		echo get_submit_button( __( 'Filter', 'partner-program' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>ID</th><th>' . esc_html__( 'User', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Code', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Tier', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Pending', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Approved', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Paid', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Actions', 'partner-program' ) . '</th>'
			. '</tr></thead><tbody>';

		// One grouped query for the whole page instead of N×3 (pending /
		// approved / paid) round-trips.
		$ids  = array_map( static fn ( array $r ): int => (int) $r['id'], $rows );
		$sums = CommissionRepo::sums_for_affiliates( $ids );

		foreach ( $rows as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$totals    = $sums[ (int) $row['id'] ] ?? [ 'pending' => 0, 'approved' => 0, 'paid' => 0 ];
			$pending   = $totals['pending'];
			$approved  = $totals['approved'];
			$paid      = $totals['paid'];
			$nonce     = wp_create_nonce( 'pp_affiliate_action_' . $row['id'] );

			echo '<tr>';
			echo '<td>#' . (int) $row['id'] . '</td>';
			echo '<td>' . esc_html( $user ? $user->user_email : '—' ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['referral_code'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			$tier_key   = isset( $row['current_tier_key'] ) ? (string) $row['current_tier_key'] : '';
			$tier_label = '—';
			if ( '' !== $tier_key ) {
				$tier       = TierResolver::tier_for_key( $tier_key );
				$tier_label = $tier && ! empty( $tier['label'] ) ? (string) $tier['label'] : $tier_key;
			}
			echo '<td>' . esc_html( $tier_label ) . '</td>';
			echo '<td>' . esc_html( Money::format( $pending ) ) . '</td>';
			echo '<td>' . esc_html( Money::format( $approved ) ) . '</td>';
			echo '<td>' . esc_html( Money::format( $paid ) ) . '</td>';
			echo '<td>';
			// Link back to this same screen rather than admin-post.php — we
			// don't register an admin_post_pp_affiliate_* hook, the action
			// is handled inline by handle_actions() during render().
			$base = admin_url( 'admin.php?page=partner-program-affiliates' );
			$mk   = static fn( string $action, string $label ) => sprintf(
				'<a href="%s" style="margin-right:6px;">%s</a>',
				esc_url( wp_nonce_url(
					add_query_arg( [ 'action' => 'pp_affiliate_' . $action, 'id' => (int) $row['id'] ], $base ),
					'pp_affiliate_action_' . $row['id']
				) ),
				esc_html( $label )
			);
			if ( 'approved' !== $row['status'] ) {
				echo $mk( 'approve', __( 'Approve', 'partner-program' ) );
			}
			if ( 'suspended' !== $row['status'] ) {
				echo $mk( 'suspend', __( 'Suspend', 'partner-program' ) );
			}
			echo '</td>';
			echo '</tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No affiliates found.', 'partner-program' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function handle_actions(): void {
		if ( empty( $_GET['action'] ) || empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id = (int) $_GET['id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'pp_affiliate_action_' . $id );
		$action  = sanitize_key( (string) $_GET['action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$handled = false;
		switch ( $action ) {
			case 'pp_affiliate_approve':
				AffiliateRepo::update( $id, [ 'status' => 'approved' ] );
				do_action( 'partner_program_affiliate_approved', $id );
				$handled = true;
				break;
			case 'pp_affiliate_suspend':
				AffiliateRepo::update( $id, [ 'status' => 'suspended' ] );
				do_action( 'partner_program_affiliate_suspended', $id );
				$handled = true;
				break;
		}
		if ( $handled ) {
			// Redirect to drop the action+nonce from the URL so a browser
			// refresh doesn't re-fire the action while the nonce is still
			// valid.
			wp_safe_redirect( add_query_arg( 'done', 1, admin_url( 'admin.php?page=partner-program-affiliates' ) ) );
			exit;
		}
	}
}
