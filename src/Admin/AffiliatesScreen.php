<?php
/**
 * Admin affiliates list + per-affiliate edit screen.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Encryption;
use PartnerProgram\Support\Logger;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;
use PartnerProgram\Support\Ui;

defined( 'ABSPATH' ) || exit;

final class AffiliatesScreen {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( 'edit' === $action && $id > 0 ) {
			self::handle_edit_submit( $id );
			self::render_edit( $id );
			return;
		}

		self::handle_actions();
		self::render_list();
	}

	private static function render_list(): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$rows = AffiliateRepo::search( [ 'status' => $status, 'search' => $search, 'page' => $page, 'per_page' => 50 ] );

		echo '<div class="wrap"><h1>' . esc_html__( 'Affiliates', 'partner-program' ) . '</h1>';

		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Changes saved.', 'partner-program' ) . '</p></div>';
		}

		Ui::status_filter(
			'partner-program-affiliates',
			$status,
			[
				''          => __( 'All statuses', 'partner-program' ),
				'pending'   => __( 'Pending', 'partner-program' ),
				'approved'  => __( 'Approved', 'partner-program' ),
				'suspended' => __( 'Suspended', 'partner-program' ),
				'rejected'  => __( 'Rejected', 'partner-program' ),
			],
			[
				'name'        => 's',
				'value'       => $search,
				'placeholder' => __( 'Search by code or email', 'partner-program' ),
			]
		);

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>' . esc_html__( 'ID', 'partner-program' ) . '</th><th>' . esc_html__( 'User', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Code', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Tier', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Rate', 'partner-program' ) . '</th>'
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
			echo '<td>' . esc_html( self::format_effective_rate( $row ) ) . '</td>';
			echo '<td>' . esc_html( Money::format( $pending ) ) . '</td>';
			echo '<td>' . esc_html( Money::format( $approved ) ) . '</td>';
			echo '<td>' . esc_html( Money::format( $paid ) ) . '</td>';
			echo '<td class="pp-row-actions">';
			// Link back to this same screen rather than admin-post.php — we
			// don't register an admin_post_pp_affiliate_* hook, the action
			// is handled inline by handle_actions() during render().
			$base = admin_url( 'admin.php?page=partner-program-affiliates' );
			$mk   = static fn( string $action, string $label ) => sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url(
					add_query_arg( [ 'action' => 'pp_affiliate_' . $action, 'id' => (int) $row['id'] ], $base ),
					'pp_affiliate_action_' . $row['id']
				) ),
				esc_html( $label )
			);
			printf(
				'<a href="%s">%s</a> | ',
				esc_url( add_query_arg( [ 'action' => 'edit', 'id' => (int) $row['id'] ], $base ) ),
				esc_html__( 'Edit', 'partner-program' )
			);
			if ( 'approved' !== $row['status'] ) {
				echo $mk( 'approve', __( 'Approve', 'partner-program' ) ) . ' | ';
			}
			if ( 'suspended' !== $row['status'] ) {
				echo $mk( 'suspend', __( 'Suspend', 'partner-program' ) );
			}
			echo '</td>';
			echo '</tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="10">' . esc_html__( 'No affiliates found.', 'partner-program' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function handle_actions(): void {
		if ( empty( $_GET['action'] ) || empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id = (int) $_GET['id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( (string) $_GET['action'] );
		// 'edit' is a GET view handled by render_edit(); only the mutation
		// actions go through here and need a nonce check.
		if ( 'edit' === $action ) {
			return;
		}
		check_admin_referer( 'pp_affiliate_action_' . $id );
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

	/**
	 * POST handler for the edit form. Runs before render_edit() so the
	 * subsequent re-render reflects the saved state.
	 */
	private static function handle_edit_submit( int $id ): void {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET' ) ) {
			return;
		}
		if ( empty( $_POST['pp_affiliate_edit'] ) ) {
			return;
		}
		check_admin_referer( 'pp_affiliate_edit_' . $id, '_pp_affiliate_edit_nonce' );

		$existing = AffiliateRepo::find( $id );
		if ( ! $existing ) {
			return;
		}

		$errors  = [];
		$changes = [];
		$update  = [];

		// Status.
		$status        = isset( $_POST['status'] ) ? sanitize_key( (string) wp_unslash( $_POST['status'] ) ) : '';
		$valid_status  = [ 'pending', 'approved', 'suspended', 'rejected' ];
		if ( ! in_array( $status, $valid_status, true ) ) {
			$errors[] = __( 'Please choose a valid status.', 'partner-program' );
		} elseif ( (string) $existing['status'] !== $status ) {
			$update['status']      = $status;
			$changes['status']     = [ (string) $existing['status'], $status ];
		}

		// Referral code (unique).
		$code_raw = isset( $_POST['referral_code'] ) ? (string) wp_unslash( $_POST['referral_code'] ) : '';
		$code     = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $code_raw ) ?? '' );
		$code     = substr( $code, 0, 64 );
		if ( '' === $code ) {
			$errors[] = __( 'Referral code cannot be empty.', 'partner-program' );
		} elseif ( $code !== (string) $existing['referral_code'] ) {
			$dupe = AffiliateRepo::find_by_code( $code );
			if ( $dupe && (int) $dupe['id'] !== $id ) {
				$errors[] = __( 'That referral code is already taken.', 'partner-program' );
			} else {
				$update['referral_code']  = $code;
				$changes['referral_code'] = [ (string) $existing['referral_code'], $code ];
			}
		}

		// Custom commission rate (blank = clear override, fall back to tier).
		$rate_raw = isset( $_POST['default_commission_rate'] ) ? trim( (string) wp_unslash( $_POST['default_commission_rate'] ) ) : '';
		$old_rate = isset( $existing['default_commission_rate'] ) && '' !== $existing['default_commission_rate']
			? (string) $existing['default_commission_rate']
			: '';
		if ( '' === $rate_raw ) {
			if ( '' !== $old_rate ) {
				$update['default_commission_rate']  = null;
				$changes['default_commission_rate'] = [ $old_rate, '(tier default)' ];
			}
		} else {
			if ( ! is_numeric( $rate_raw ) ) {
				$errors[] = __( 'Commission rate must be a number between 0 and 100.', 'partner-program' );
			} else {
				$rate = (float) $rate_raw;
				if ( $rate < 0 || $rate > 100 ) {
					$errors[] = __( 'Commission rate must be between 0 and 100.', 'partner-program' );
				} elseif ( (float) $old_rate !== $rate ) {
					$update['default_commission_rate']  = number_format( $rate, 4, '.', '' );
					$changes['default_commission_rate'] = [ '' !== $old_rate ? $old_rate : '(tier default)', (string) $rate ];
				}
			}
		}

		// Notes (admin-internal).
		$notes     = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '';
		$old_notes = (string) ( $existing['notes'] ?? '' );
		if ( $notes !== $old_notes ) {
			$update['notes']  = $notes;
			$changes['notes'] = [ '(updated)', '(updated)' ];
		}

		// Payout method + details. Method must be one of the currently-enabled
		// methods (matches the portal-side validation).
		$settings      = new SettingsRepo();
		$enabled       = (array) $settings->get( 'hold_payouts.enabled_methods', [] );
		$method     = isset( $_POST['payout_method'] ) ? sanitize_key( (string) wp_unslash( $_POST['payout_method'] ) ) : '';
		$old_method = (string) ( $existing['payout_method'] ?? '' );
		if ( '' === $method ) {
			if ( '' !== $old_method ) {
				$update['payout_method']  = '';
				$changes['payout_method'] = [ $old_method, '(none)' ];
			}
		} elseif ( ! in_array( $method, $enabled, true ) ) {
			$errors[] = __( 'Selected payout method is not enabled in settings.', 'partner-program' );
		} elseif ( $method !== $old_method ) {
			$update['payout_method']  = $method;
			$changes['payout_method'] = [ '' !== $old_method ? $old_method : '(none)', $method ];
		}

		$details_in = isset( $_POST['payout_details'] ) && is_array( $_POST['payout_details'] )
			? array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) $_POST['payout_details'] ) )
			: null;
		// Only touch payout_details when the admin actually submitted the
		// fields (we don't blow them away just because someone saved an
		// unrelated change).
		if ( null !== $details_in ) {
			$old_details = AffiliateRepo::decrypt_payout_details( $existing['payout_details'] ?? null );
			if ( $details_in !== $old_details ) {
				if ( ! Encryption::is_available() ) {
					$errors[] = __( 'Payout details cannot be saved: libsodium is not available on this server.', 'partner-program' );
				} else {
					try {
						$update['payout_details']  = AffiliateRepo::encrypt_payout_details( $details_in );
						// Don't log the PII itself — just that it changed.
						$changes['payout_details'] = [ '(updated)', '(updated)' ];
					} catch ( \RuntimeException $e ) {
						$errors[] = $e->getMessage();
					}
				}
			}
		}
		if ( $errors ) {
			set_transient(
				'pp_affiliate_edit_errors_' . get_current_user_id() . '_' . $id,
				$errors,
				60
			);
			wp_safe_redirect( self::edit_url( $id ) );
			exit;
		}

		if ( $update ) {
			AffiliateRepo::update( $id, $update );
			( new Logger() )->log(
				sprintf( 'Affiliate #%d updated by admin.', $id ),
				'affiliates',
				'info',
				$id,
				'affiliate',
				[ 'changes' => $changes ]
			);
		}

		wp_safe_redirect( add_query_arg( 'saved', 1, self::edit_url( $id ) ) );
		exit;
	}

	private static function render_edit( int $id ): void {
		$affiliate = AffiliateRepo::find( $id );
		if ( ! $affiliate ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Affiliate not found.', 'partner-program' ) . '</h1>';
			printf(
				'<p><a href="%s">%s</a></p></div>',
				esc_url( admin_url( 'admin.php?page=partner-program-affiliates' ) ),
				esc_html__( '← Back to affiliates', 'partner-program' )
			);
			return;
		}

		$user        = get_userdata( (int) $affiliate['user_id'] );
		$details     = AffiliateRepo::decrypt_payout_details( $affiliate['payout_details'] ?? null );
		$settings    = new SettingsRepo();
		$methods     = (array) $settings->get( 'hold_payouts.enabled_methods', [] );
		$base_rate   = (float) $settings->get( 'commissions.base_rate', 15 );
		$tier_key    = (string) ( $affiliate['current_tier_key'] ?? '' );
		$tier        = '' !== $tier_key ? TierResolver::tier_for_key( $tier_key ) : null;
		$tier_label  = $tier && ! empty( $tier['label'] ) ? (string) $tier['label'] : ( '' !== $tier_key ? $tier_key : __( '(none)', 'partner-program' ) );
		$tier_rate   = $tier && isset( $tier['rate'] ) ? (float) $tier['rate'] : $base_rate;
		$rate_value  = isset( $affiliate['default_commission_rate'] ) && '' !== $affiliate['default_commission_rate']
			? rtrim( rtrim( (string) $affiliate['default_commission_rate'], '0' ), '.' )
			: '';
		$errors_key  = 'pp_affiliate_edit_errors_' . get_current_user_id() . '_' . $id;
		$errors      = get_transient( $errors_key );
		if ( $errors ) {
			delete_transient( $errors_key );
		}

		echo '<div class="wrap"><h1>';
		printf(
			/* translators: %d: affiliate id */
			esc_html__( 'Edit Affiliate #%d', 'partner-program' ),
			$id
		);
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=partner-program-affiliates' ) ) . '" class="page-title-action">' . esc_html__( 'Back to list', 'partner-program' ) . '</a></h1>';

		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Affiliate updated.', 'partner-program' ) . '</p></div>';
		}
		if ( is_array( $errors ) && $errors ) {
			echo '<div class="notice notice-error"><ul style="margin:0.5em 0 0.5em 1.5em;list-style:disc;">';
			foreach ( $errors as $err ) {
				echo '<li>' . esc_html( (string) $err ) . '</li>';
			}
			echo '</ul></div>';
		}

		$encryption_ok = Encryption::is_available();

		echo '<form method="post" action="' . esc_url( self::edit_url( $id ) ) . '">';
		wp_nonce_field( 'pp_affiliate_edit_' . $id, '_pp_affiliate_edit_nonce' );
		echo '<input type="hidden" name="pp_affiliate_edit" value="1" />';

		echo '<h2>' . esc_html__( 'Account', 'partner-program' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'User', 'partner-program' ) . '</th><td>';
		if ( $user ) {
			printf(
				'%s <code>(#%d)</code>',
				esc_html( $user->user_email ),
				(int) $user->ID
			);
		} else {
			echo '—';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-status">' . esc_html__( 'Status', 'partner-program' ) . '</label></th><td>';
		echo '<select id="pp-affiliate-status" name="status">';
		foreach ( [ 'pending', 'approved', 'suspended', 'rejected' ] as $s ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $s ),
				selected( (string) $affiliate['status'], $s, false )
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-code">' . esc_html__( 'Referral code', 'partner-program' ) . '</label></th><td>';
		printf(
			'<input type="text" id="pp-affiliate-code" name="referral_code" value="%s" class="regular-text" maxlength="64" pattern="[A-Za-z0-9_-]+" required />',
			esc_attr( (string) $affiliate['referral_code'] )
		);
		echo '<p class="description">' . esc_html__( 'Letters, numbers, underscore, dash. Changing this breaks any links/codes already shared.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Current tier', 'partner-program' ) . '</th><td>';
		printf(
			/* translators: 1: tier label, 2: tier rate */
			esc_html__( '%1$s — %2$s%%', 'partner-program' ),
			esc_html( $tier_label ),
			esc_html( (string) $tier_rate )
		);
		echo '<p class="description">' . esc_html__( 'Tier is recalculated automatically from the previous month\'s attributed sales. To force a recalculation, run: wp partner-program recalculate-tiers.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-rate">' . esc_html__( 'Custom commission rate (%)', 'partner-program' ) . '</label></th><td>';
		printf(
			'<input type="number" id="pp-affiliate-rate" name="default_commission_rate" value="%s" step="0.0001" min="0" max="100" class="small-text" /> %%',
			esc_attr( $rate_value )
		);
		echo '<p class="description">' . esc_html__( 'Overrides tier and base rate for this affiliate. Leave blank to fall back to the tier rate. Future commissions only — existing rows are not retro-rewritten.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-notes">' . esc_html__( 'Internal notes', 'partner-program' ) . '</label></th><td>';
		printf(
			'<textarea id="pp-affiliate-notes" name="notes" rows="4" class="large-text">%s</textarea>',
			esc_textarea( (string) ( $affiliate['notes'] ?? '' ) )
		);
		echo '<p class="description">' . esc_html__( 'Not shown to the partner.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Payout method', 'partner-program' ) . '</h2>';
		if ( ! $encryption_ok ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Secure storage (libsodium) is not available on this server. Payout details cannot be saved until it is enabled.', 'partner-program' ) . '</p></div>';
		}
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="pp-affiliate-method">' . esc_html__( 'Method', 'partner-program' ) . '</label></th><td>';
		echo '<select id="pp-affiliate-method" name="payout_method"' . ( $encryption_ok ? '' : ' disabled' ) . '>';
		printf( '<option value="" %s>%s</option>', selected( (string) ( $affiliate['payout_method'] ?? '' ), '', false ), esc_html__( '— None —', 'partner-program' ) );
		foreach ( $methods as $m ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $m ),
				selected( (string) ( $affiliate['payout_method'] ?? '' ), (string) $m, false ),
				esc_html( strtoupper( (string) $m ) )
			);
		}
		echo '</select>';
		echo '</td></tr>';

		$fields = [
			'account' => __( 'Account / handle / email', 'partner-program' ),
			'routing' => __( 'Routing / extra info', 'partner-program' ),
			'notes'   => __( 'Notes (e.g. legal name)', 'partner-program' ),
		];
		foreach ( $fields as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="pp-affiliate-detail-%1$s">%2$s</label></th><td>'
				. '<input type="text" id="pp-affiliate-detail-%1$s" name="payout_details[%1$s]" value="%3$s" class="regular-text"%4$s /></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) ( $details[ $key ] ?? '' ) ),
				$encryption_ok ? '' : ' disabled'
			);
		}
		echo '</tbody></table>';

		echo '<p class="submit">';
		submit_button( __( 'Save changes', 'partner-program' ), 'primary', 'submit', false );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=partner-program-affiliates' ) ) . '" class="button button-secondary">' . esc_html__( 'Cancel', 'partner-program' ) . '</a></p>';
		echo '</form></div>';
	}

	private static function edit_url( int $id ): string {
		return add_query_arg(
			[ 'page' => 'partner-program-affiliates', 'action' => 'edit', 'id' => $id ],
			admin_url( 'admin.php' )
		);
	}

	private static function format_effective_rate( array $row ): string {
		if ( isset( $row['default_commission_rate'] ) && '' !== $row['default_commission_rate'] ) {
			$r = rtrim( rtrim( (string) $row['default_commission_rate'], '0' ), '.' );
			return sprintf( '%s%% *', $r );
		}
		$tier_key = (string) ( $row['current_tier_key'] ?? '' );
		if ( '' !== $tier_key ) {
			$tier = TierResolver::tier_for_key( $tier_key );
			if ( $tier && isset( $tier['rate'] ) ) {
				return (string) $tier['rate'] . '%';
			}
		}
		$base = (float) ( new SettingsRepo() )->get( 'commissions.base_rate', 15 );
		return (string) $base . '%';
	}
}
