<?php
/**
 * Admin affiliates list + per-affiliate edit screen.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\AgreementRepo;
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

	/**
	 * Wire screen-specific hooks. Called once from the plugin bootstrap.
	 * Kept on this class (rather than AdminMenu) so the AJAX handler and
	 * the asset enqueue live next to the screen they belong to.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_pp_affiliate_user_search', [ self::class, 'ajax_user_search' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
	}

	public static function enqueue_assets( string $hook ): void {
		// Only load the autocomplete bundle on the affiliates "Add new" view —
		// the list and edit views don't need it. The hook for our menu page is
		// `partner-program_page_partner-program-affiliates`; rather than rely
		// on the exact suffix we just sniff the page slug + action.
		if ( false === strpos( $hook, 'partner-program-affiliates' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		if ( 'new' !== $action ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-autocomplete' );

		$nonce = wp_create_nonce( 'pp_affiliate_user_search' );
		// Inline so we don't ship a separate file for ~25 lines of JS. The
		// autocomplete source hits admin-ajax.php with the search term and
		// receives [{ label, value, user_id }] — `value` is what lands in the
		// input on selection (we use the email so the existing-user lookup
		// in handle_new_submit() resolves it without further round-trips).
		$script = sprintf(
			'jQuery(function($){
				var $field = $("#pp-affiliate-existing-user");
				if (!$field.length || !$.fn.autocomplete) return;
				$field.autocomplete({
					minLength: 2,
					delay: 200,
					source: function(request, response){
						$.getJSON(ajaxurl, {
							action: "pp_affiliate_user_search",
							_ajax_nonce: %s,
							q: request.term
						}).done(function(data){
							response(Array.isArray(data) ? data : []);
						}).fail(function(){
							response([]);
						});
					}
				});
			});',
			wp_json_encode( $nonce )
		);
		wp_add_inline_script( 'jquery-ui-autocomplete', $script );

		// jQuery UI ships no CSS with WP core — drop in just enough to make the
		// suggestion list look at home next to native admin form controls.
		$css = '.ui-autocomplete{position:absolute;z-index:100000;list-style:none;padding:4px 0;margin:2px 0 0;background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 2px rgba(0,0,0,.05);max-height:280px;overflow-y:auto;font-size:13px;}'
			. '.ui-autocomplete .ui-menu-item{padding:6px 10px;cursor:pointer;}'
			. '.ui-autocomplete .ui-menu-item:hover,.ui-autocomplete .ui-state-active,.ui-autocomplete .ui-state-focus{background:#2271b1;color:#fff;}';
		wp_add_inline_style( 'partner-program-admin', $css );
	}

	/**
	 * AJAX endpoint backing the existing-user picker on the "Add affiliate"
	 * screen. Returns up to 10 matching users as
	 * `[ { label: "Display Name (email)", value: "email" }, ... ]` — the
	 * server-side handler in handle_new_submit() already accepts either an
	 * email or a username, so emitting the email is enough.
	 *
	 * Filters out users who already have an affiliate row, since picking
	 * one would just trip the duplicate guard anyway.
	 */
	public static function ajax_user_search(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_send_json( [], 403 );
		}
		check_ajax_referer( 'pp_affiliate_user_search' );

		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$q = trim( $q );
		if ( strlen( $q ) < 2 ) {
			wp_send_json( [] );
		}

		// `search` with leading+trailing wildcards covers user_login,
		// user_email, user_url, user_nicename, display_name out of the box.
		$users = get_users(
			[
				'search'         => '*' . $q . '*',
				'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
				'number'         => 25,
				'orderby'        => 'user_login',
				'order'          => 'ASC',
				'fields'         => [ 'ID', 'user_login', 'user_email', 'display_name' ],
			]
		);

		$out = [];
		foreach ( $users as $u ) {
			if ( null !== AffiliateRepo::find_by_user( (int) $u->ID ) ) {
				continue;
			}
			$display = $u->display_name !== '' ? $u->display_name : $u->user_login;
			$out[]   = [
				'label'   => sprintf( '%s (%s)', $display, $u->user_email ),
				'value'   => $u->user_email,
				'user_id' => (int) $u->ID,
			];
			if ( count( $out ) >= 10 ) {
				break;
			}
		}

		wp_send_json( $out );
	}

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

		if ( 'new' === $action ) {
			self::handle_new_submit();
			self::render_new();
			return;
		}

		self::handle_actions();
		self::render_list();
	}

	private static function render_list(): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page    = 50;
		$total_items = AffiliateRepo::count( [ 'status' => $status, 'search' => $search ] );
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
		$rows        = AffiliateRepo::search( [ 'status' => $status, 'search' => $search, 'page' => $page, 'per_page' => $per_page ] );

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Affiliates', 'partner-program' ) . '</h1> ';
		printf(
			'<a href="%s" class="page-title-action">%s</a><hr class="wp-header-end" />',
			esc_url( add_query_arg( [ 'page' => 'partner-program-affiliates', 'action' => 'new' ], admin_url( 'admin.php' ) ) ),
			esc_html__( 'Add new', 'partner-program' )
		);

		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Changes saved.', 'partner-program' ) . '</p></div>';
		}
		if ( isset( $_GET['created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Affiliate created.', 'partner-program' ) . '</p></div>';
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
		echo '</tbody></table>';

		if ( $total_pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				[
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'total'     => $total_pages,
					'current'   => $page,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				]
			);
			echo '</div></div>';
		}

		echo '</div>';
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

	/**
	 * POST handler for the "Add affiliate" form. Looks up or creates the
	 * underlying WP user, ensures the partner role, inserts the affiliate
	 * row, optionally records an admin-side agreement acceptance, and
	 * fires `partner_program_affiliate_approved` exactly once when the
	 * new row lands in the approved state — so the email layer and any
	 * third-party listeners behave identically to the application-form
	 * path.
	 */
	private static function handle_new_submit(): void {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET' ) ) {
			return;
		}
		if ( empty( $_POST['pp_affiliate_new'] ) ) {
			return;
		}
		check_admin_referer( 'pp_affiliate_new', '_pp_affiliate_new_nonce' );

		$errors = [];

		$user_mode = isset( $_POST['user_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['user_mode'] ) ) : 'existing';
		if ( ! in_array( $user_mode, [ 'existing', 'new' ], true ) ) {
			$user_mode = 'existing';
		}

		$status       = isset( $_POST['status'] ) ? sanitize_key( (string) wp_unslash( $_POST['status'] ) ) : 'approved';
		$valid_status = [ 'pending', 'approved', 'suspended', 'rejected' ];
		if ( ! in_array( $status, $valid_status, true ) ) {
			$errors[] = __( 'Please choose a valid status.', 'partner-program' );
		}

		$code_raw = isset( $_POST['referral_code'] ) ? (string) wp_unslash( $_POST['referral_code'] ) : '';
		$code     = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $code_raw ) ?? '' );
		$code     = substr( $code, 0, 64 );
		if ( '' !== $code && null !== AffiliateRepo::find_by_code( $code ) ) {
			$errors[] = __( 'That referral code is already taken.', 'partner-program' );
		}

		$rate_raw   = isset( $_POST['default_commission_rate'] ) ? trim( (string) wp_unslash( $_POST['default_commission_rate'] ) ) : '';
		$rate_value = null;
		if ( '' !== $rate_raw ) {
			if ( ! is_numeric( $rate_raw ) || (float) $rate_raw < 0 || (float) $rate_raw > 100 ) {
				$errors[] = __( 'Commission rate must be a number between 0 and 100.', 'partner-program' );
			} else {
				$rate_value = number_format( (float) $rate_raw, 4, '.', '' );
			}
		}

		$notes              = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '';
		$accept_agreement   = ! empty( $_POST['accept_agreement'] );
		$send_welcome_email = ! empty( $_POST['send_welcome_email'] );

		$user_id = 0;
		$created_user = false;

		if ( 'existing' === $user_mode ) {
			$lookup = isset( $_POST['existing_user'] ) ? trim( (string) wp_unslash( $_POST['existing_user'] ) ) : '';
			if ( '' === $lookup ) {
				$errors[] = __( 'Enter the email or username of an existing user.', 'partner-program' );
			} else {
				$found = is_email( $lookup ) ? get_user_by( 'email', $lookup ) : get_user_by( 'login', $lookup );
				if ( ! $found ) {
					$errors[] = __( 'No WordPress user found with that email or username.', 'partner-program' );
				} else {
					$user_id = (int) $found->ID;
				}
			}
		} else {
			$email     = isset( $_POST['new_user_email'] ) ? sanitize_email( (string) wp_unslash( $_POST['new_user_email'] ) ) : '';
			$display   = isset( $_POST['new_user_display_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['new_user_display_name'] ) ) : '';
			if ( '' === $email || ! is_email( $email ) ) {
				$errors[] = __( 'Enter a valid email address for the new user.', 'partner-program' );
			} elseif ( email_exists( $email ) ) {
				$errors[] = __( 'A WordPress user with that email already exists. Switch to "Link existing user" instead.', 'partner-program' );
			}

			if ( ! $errors ) {
				$base_login = sanitize_user( current( explode( '@', $email ) ), true );
				$login      = $base_login;
				$i          = 1;
				while ( username_exists( $login ) ) {
					$login = $base_login . $i++;
					if ( $i > 50 ) {
						$errors[] = __( 'Could not generate a unique username for this email.', 'partner-program' );
						break;
					}
				}
				if ( ! $errors ) {
					$new_id = wp_insert_user(
						[
							'user_login'   => $login,
							'user_email'   => $email,
							'user_pass'    => wp_generate_password( 24 ),
							'display_name' => '' !== $display ? $display : $login,
							'role'         => Capabilities::ROLE_PARTNER,
						]
					);
					if ( is_wp_error( $new_id ) ) {
						$errors[] = $new_id->get_error_message();
					} else {
						$user_id      = (int) $new_id;
						$created_user = true;
						// Always send the password-set email for newly
						// created users — admin never sees or sets the
						// password directly.
						wp_new_user_notification( $user_id, null, 'both' );
					}
				}
			}
		}

		if ( ! $errors && $user_id > 0 && null !== AffiliateRepo::find_by_user( $user_id ) ) {
			$errors[] = __( 'That user already has an affiliate record. Edit it from the list instead.', 'partner-program' );
		}

		if ( $errors ) {
			set_transient(
				'pp_affiliate_new_errors_' . get_current_user_id(),
				$errors,
				60
			);
			set_transient(
				'pp_affiliate_new_input_' . get_current_user_id(),
				[
					'user_mode'             => $user_mode,
					'existing_user'         => isset( $_POST['existing_user'] ) ? (string) wp_unslash( $_POST['existing_user'] ) : '',
					'new_user_email'        => isset( $_POST['new_user_email'] ) ? (string) wp_unslash( $_POST['new_user_email'] ) : '',
					'new_user_display_name' => isset( $_POST['new_user_display_name'] ) ? (string) wp_unslash( $_POST['new_user_display_name'] ) : '',
					'referral_code'         => $code_raw,
					'status'                => $status,
					'default_commission_rate' => $rate_raw,
					'notes'                 => $notes,
					'accept_agreement'      => $accept_agreement,
					'send_welcome_email'    => $send_welcome_email,
				],
				60
			);
			wp_safe_redirect( self::new_url() );
			exit;
		}

		$user = get_userdata( $user_id );
		if ( $user && ! in_array( Capabilities::ROLE_PARTNER, (array) $user->roles, true ) ) {
			$user->add_role( Capabilities::ROLE_PARTNER );
		}

		if ( '' === $code ) {
			$hint = $user ? ( $user->display_name ?: $user->user_login ) : '';
			$code = AffiliateRepo::generate_unique_code( $hint );
		}

		$current_agr = AgreementRepo::current();

		$affiliate_id = AffiliateRepo::create(
			[
				'user_id'                    => $user_id,
				'status'                     => $status,
				'referral_code'              => $code,
				'default_commission_rate'    => $rate_value,
				'notes'                      => $notes,
				'agreement_version_accepted' => ( $accept_agreement && $current_agr ) ? (int) $current_agr['id'] : null,
			]
		);

		if ( ! $affiliate_id ) {
			set_transient(
				'pp_affiliate_new_errors_' . get_current_user_id(),
				[ __( 'Failed to create the affiliate record.', 'partner-program' ) ],
				60
			);
			wp_safe_redirect( self::new_url() );
			exit;
		}

		if ( $accept_agreement && $current_agr ) {
			AgreementRepo::record_acceptance( $affiliate_id, (int) $current_agr['id'], null );
		}

		( new Logger() )->log(
			sprintf( 'Affiliate #%d created by admin.', $affiliate_id ),
			'affiliates',
			'info',
			$affiliate_id,
			'affiliate',
			[
				'source'              => 'admin',
				'user_id'             => $user_id,
				'created_wp_user'     => $created_user,
				'status'              => $status,
				'agreement_accepted'  => (bool) ( $accept_agreement && $current_agr ),
			]
		);

		// Fire the standard approval event so downstream listeners (email
		// layer, integrations) treat this identically to a form approval.
		// Skipped for non-approved statuses — the partner isn't live yet.
		if ( 'approved' === $status && $send_welcome_email ) {
			do_action( 'partner_program_affiliate_approved', $affiliate_id );
		}

		wp_safe_redirect( add_query_arg( 'created', 1, admin_url( 'admin.php?page=partner-program-affiliates' ) ) );
		exit;
	}

	private static function render_new(): void {
		$errors_key = 'pp_affiliate_new_errors_' . get_current_user_id();
		$input_key  = 'pp_affiliate_new_input_' . get_current_user_id();
		$errors     = get_transient( $errors_key );
		$input      = get_transient( $input_key );
		if ( $errors ) {
			delete_transient( $errors_key );
		}
		if ( $input ) {
			delete_transient( $input_key );
		}
		$input = is_array( $input ) ? $input : [];

		$user_mode          = (string) ( $input['user_mode'] ?? 'existing' );
		$existing_user      = (string) ( $input['existing_user'] ?? '' );
		$new_email          = (string) ( $input['new_user_email'] ?? '' );
		$new_display        = (string) ( $input['new_user_display_name'] ?? '' );
		$code_value         = (string) ( $input['referral_code'] ?? '' );
		$status_value       = (string) ( $input['status'] ?? 'approved' );
		$rate_value         = (string) ( $input['default_commission_rate'] ?? '' );
		$notes_value        = (string) ( $input['notes'] ?? '' );
		$accept_agreement   = array_key_exists( 'accept_agreement', $input ) ? (bool) $input['accept_agreement'] : false;
		$send_welcome_email = array_key_exists( 'send_welcome_email', $input ) ? (bool) $input['send_welcome_email'] : true;

		$current_agr = AgreementRepo::current();

		echo '<div class="wrap"><h1>';
		echo esc_html__( 'Add affiliate', 'partner-program' );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=partner-program-affiliates' ) ) . '" class="page-title-action">' . esc_html__( 'Back to list', 'partner-program' ) . '</a></h1>';

		if ( is_array( $errors ) && $errors ) {
			echo '<div class="notice notice-error"><ul style="margin:0.5em 0 0.5em 1.5em;list-style:disc;">';
			foreach ( $errors as $err ) {
				echo '<li>' . esc_html( (string) $err ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<form method="post" action="' . esc_url( self::new_url() ) . '">';
		wp_nonce_field( 'pp_affiliate_new', '_pp_affiliate_new_nonce' );
		echo '<input type="hidden" name="pp_affiliate_new" value="1" />';

		echo '<h2>' . esc_html__( 'WordPress user', 'partner-program' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Source', 'partner-program' ) . '</th><td><fieldset>';
		printf(
			'<label><input type="radio" name="user_mode" value="existing" %s /> %s</label><br />',
			checked( $user_mode, 'existing', false ),
			esc_html__( 'Link an existing WordPress user', 'partner-program' )
		);
		printf(
			'<label><input type="radio" name="user_mode" value="new" %s /> %s</label>',
			checked( $user_mode, 'new', false ),
			esc_html__( 'Create a new WordPress user', 'partner-program' )
		);
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-existing-user">' . esc_html__( 'Existing user (email or username)', 'partner-program' ) . '</label></th><td>';
		printf(
			'<input type="text" id="pp-affiliate-existing-user" name="existing_user" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $existing_user )
		);
		echo '<p class="description">' . esc_html__( 'Used when "Link an existing WordPress user" is selected.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-new-email">' . esc_html__( 'New user email', 'partner-program' ) . '</label></th><td>';
		printf(
			'<input type="email" id="pp-affiliate-new-email" name="new_user_email" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $new_email )
		);
		echo '<p class="description">' . esc_html__( 'A WordPress account is created and a "set your password" email is sent.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-new-display">' . esc_html__( 'New user display name', 'partner-program' ) . '</label></th><td>';
		printf(
			'<input type="text" id="pp-affiliate-new-display" name="new_user_display_name" value="%s" class="regular-text" />',
			esc_attr( $new_display )
		);
		echo '<p class="description">' . esc_html__( 'Optional. Defaults to the username derived from the email.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Affiliate', 'partner-program' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="pp-affiliate-status">' . esc_html__( 'Status', 'partner-program' ) . '</label></th><td>';
		echo '<select id="pp-affiliate-status" name="status">';
		foreach ( [ 'approved', 'pending', 'suspended', 'rejected' ] as $s ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $s ),
				selected( $status_value, $s, false )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Defaults to "approved" — the partner can sign in and use the portal immediately.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-code">' . esc_html__( 'Referral code', 'partner-program' ) . '</label></th><td>';
		printf(
			'<input type="text" id="pp-affiliate-code" name="referral_code" value="%s" class="regular-text" maxlength="64" pattern="[A-Za-z0-9_-]+" />',
			esc_attr( $code_value )
		);
		echo '<p class="description">' . esc_html__( 'Letters, numbers, underscore, dash. Leave blank to auto-generate from the user name.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-rate">' . esc_html__( 'Custom commission rate (%)', 'partner-program' ) . '</label></th><td>';
		printf(
			'<input type="number" id="pp-affiliate-rate" name="default_commission_rate" value="%s" step="0.0001" min="0" max="100" class="small-text" /> %%',
			esc_attr( $rate_value )
		);
		echo '<p class="description">' . esc_html__( 'Optional override. Leave blank to fall back to the tier rate.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pp-affiliate-notes">' . esc_html__( 'Internal notes', 'partner-program' ) . '</label></th><td>';
		printf(
			'<textarea id="pp-affiliate-notes" name="notes" rows="4" class="large-text">%s</textarea>',
			esc_textarea( $notes_value )
		);
		echo '<p class="description">' . esc_html__( 'Not shown to the partner.', 'partner-program' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Compliance agreement', 'partner-program' ) . '</th><td>';
		if ( $current_agr ) {
			printf(
				'<label><input type="checkbox" name="accept_agreement" value="1" %s /> %s</label>',
				checked( $accept_agreement, true, false ),
				esc_html(
					sprintf(
						/* translators: %d: agreement version */
						__( 'Mark current agreement (v%d) as accepted on the partner\'s behalf.', 'partner-program' ),
						(int) $current_agr['version']
					)
				)
			);
			echo '<p class="description">' . esc_html__( 'Leave unchecked to require the partner to accept it on first portal login. The acceptance is logged with source "admin" for the audit trail.', 'partner-program' ) . '</p>';
		} else {
			echo '<em>' . esc_html__( 'No compliance agreement has been published yet.', 'partner-program' ) . '</em>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Welcome email', 'partner-program' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="send_welcome_email" value="1" %s /> %s</label>',
			checked( $send_welcome_email, true, false ),
			esc_html__( 'Send the standard approval / welcome email when the affiliate is created in "approved" status.', 'partner-program' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p class="submit">';
		submit_button( __( 'Create affiliate', 'partner-program' ), 'primary', 'submit', false );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=partner-program-affiliates' ) ) . '" class="button button-secondary">' . esc_html__( 'Cancel', 'partner-program' ) . '</a></p>';
		echo '</form></div>';
	}

	private static function new_url(): string {
		return add_query_arg(
			[ 'page' => 'partner-program-affiliates', 'action' => 'new' ],
			admin_url( 'admin.php' )
		);
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
