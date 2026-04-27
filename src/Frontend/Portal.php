<?php
/**
 * Partner Portal frontend (shortcode + block).
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Frontend;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\AgreementRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\PayoutRepo;
use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;
use PartnerProgram\Support\Template;

defined( 'ABSPATH' ) || exit;

final class Portal {

	public function register(): void {
		add_shortcode( 'partner_program_portal', [ $this, 'render_portal' ] );
		add_shortcode( 'partner_program_login', [ $this, 'render_login' ] );

		add_action( 'admin_post_nopriv_pp_portal_login', [ $this, 'handle_login' ] );
		add_action( 'admin_post_pp_portal_login', [ $this, 'handle_login' ] );
		add_action( 'admin_post_pp_portal_logout', [ $this, 'handle_logout' ] );
		add_action( 'admin_post_pp_portal_save_payout', [ $this, 'handle_save_payout_method' ] );
		add_action( 'admin_post_pp_portal_accept_agreement', [ $this, 'handle_accept_agreement' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		wp_register_style( 'partner-program-portal', PARTNER_PROGRAM_URL . 'assets/css/portal.css', [], PARTNER_PROGRAM_VERSION );
	}

	public function render_login(): string {
		if ( is_user_logged_in() ) {
			$portal_id = (int) get_option( 'partner_program_portal_page_id' );
			$url       = $portal_id ? get_permalink( $portal_id ) : home_url( '/partner-portal/' );
			return '<p>' . sprintf(
				wp_kses_post( __( 'You are logged in. <a href="%s">Go to the partner portal</a>.', 'partner-program' ) ),
				esc_url( $url )
			) . '</p>';
		}
		wp_enqueue_style( 'partner-program-portal' );
		return Template::render( 'portal/login.php', [
			'action' => esc_url( admin_url( 'admin-post.php' ) ),
			'nonce'  => wp_nonce_field( 'pp_portal_login', '_pp_login_nonce', true, false ),
			'error'  => isset( $_GET['login_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['login_error'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		] );
	}

	public function render_portal(): string {
		wp_enqueue_style( 'partner-program-portal' );
		$settings = new SettingsRepo();

		if ( ! is_user_logged_in() ) {
			$login_id = (int) get_option( 'partner_program_login_page_id' );
			$url      = $login_id ? get_permalink( $login_id ) : wp_login_url( get_permalink() );
			return '<p>' . sprintf( wp_kses_post( __( 'Please <a href="%s">log in</a> to access the partner portal.', 'partner-program' ) ), esc_url( $url ) ) . '</p>';
		}

		$user_id   = get_current_user_id();
		$affiliate = AffiliateRepo::find_by_user( $user_id );

		if ( ! $affiliate ) {
			return '<p>' . esc_html__( 'No partner account is linked to your user.', 'partner-program' ) . '</p>';
		}

		if ( 'approved' !== $affiliate['status'] ) {
			return '<p>' . esc_html__( 'Your partner account is not active.', 'partner-program' ) . '</p>';
		}

		$current_agr = AgreementRepo::current();
		$needs_accept = $current_agr && (int) $affiliate['agreement_version_accepted'] !== (int) $current_agr['id'];
		if ( $needs_accept ) {
			return Template::render(
				'portal/accept-agreement.php',
				[
					'agreement' => $current_agr,
					'action'    => esc_url( admin_url( 'admin-post.php' ) ),
					'nonce'     => wp_nonce_field( 'pp_accept_agreement', '_pp_agreement_nonce', true, false ),
				]
			);
		}

		$tab     = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = [ 'overview', 'links', 'materials', 'compliance', 'commissions', 'payouts' ];
		if ( ! in_array( $tab, $allowed, true ) ) {
			$tab = 'overview';
		}

		$ctx = $this->build_context( $affiliate, $settings );
		$ctx['active_tab']    = $tab;
		$ctx['portal_url']    = get_permalink();
		$ctx['logout_url']    = wp_nonce_url( add_query_arg( [ 'action' => 'pp_portal_logout' ], admin_url( 'admin-post.php' ) ), 'pp_portal_logout' );
		$ctx['settings_arr']  = $settings->all();

		return Template::render( 'portal/wrapper.php', $ctx );
	}

	public function handle_login(): void {
		check_admin_referer( 'pp_portal_login', '_pp_login_nonce' );
		$creds = [
			'user_login'    => isset( $_POST['log'] ) ? sanitize_user( wp_unslash( (string) $_POST['log'] ) ) : '',
			'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '',
			'remember'      => ! empty( $_POST['rememberme'] ),
		];
		$user = wp_signon( $creds, is_ssl() );
		$ref  = wp_get_referer() ?: home_url( '/' );
		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( add_query_arg( [ 'login_error' => 'invalid' ], $ref ) );
			exit;
		}
		$portal_id = (int) get_option( 'partner_program_portal_page_id' );
		wp_safe_redirect( $portal_id ? get_permalink( $portal_id ) : $ref );
		exit;
	}

	public function handle_logout(): void {
		check_admin_referer( 'pp_portal_logout' );
		wp_logout();
		$login_id = (int) get_option( 'partner_program_login_page_id' );
		wp_safe_redirect( $login_id ? get_permalink( $login_id ) : home_url( '/' ) );
		exit;
	}

	public function handle_save_payout_method(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		check_admin_referer( 'pp_save_payout', '_pp_save_payout_nonce' );

		$affiliate = AffiliateRepo::find_by_user( get_current_user_id() );
		if ( ! $affiliate ) {
			wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
			exit;
		}

		$method  = isset( $_POST['payout_method'] ) ? sanitize_key( wp_unslash( (string) $_POST['payout_method'] ) ) : '';
		$details = isset( $_POST['payout_details'] ) && is_array( $_POST['payout_details'] )
			? array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) $_POST['payout_details'] ) )
			: [];

		$settings = new SettingsRepo();
		$enabled  = (array) $settings->get( 'hold_payouts.enabled_methods', [] );
		if ( '' === $method || ! in_array( $method, $enabled, true ) ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'tab' => 'payouts', 'saved' => 0, 'pp_error' => 'invalid_method' ],
					wp_get_referer() ?: home_url( '/' )
				)
			);
			exit;
		}

		AffiliateRepo::update(
			(int) $affiliate['id'],
			[
				'payout_method'  => $method,
				'payout_details' => AffiliateRepo::encrypt_payout_details( $details ),
			]
		);
		wp_safe_redirect( add_query_arg( [ 'tab' => 'payouts', 'saved' => 1 ], wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}

	public function handle_accept_agreement(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		check_admin_referer( 'pp_accept_agreement', '_pp_agreement_nonce' );
		$affiliate = AffiliateRepo::find_by_user( get_current_user_id() );
		if ( ! $affiliate ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$current = AgreementRepo::current();
		if ( $current ) {
			AffiliateRepo::update( (int) $affiliate['id'], [ 'agreement_version_accepted' => (int) $current['id'] ] );
			AgreementRepo::record_acceptance( (int) $affiliate['id'], (int) $current['id'], \PartnerProgram\Tracking\Tracker::ip_hash() );
		}
		wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
		exit;
	}

	private function build_context( array $affiliate, SettingsRepo $settings ): array {
		$affiliate_id = (int) $affiliate['id'];
		$pending = CommissionRepo::sum_for_affiliate( $affiliate_id, 'pending' );
		$approved = CommissionRepo::sum_for_affiliate( $affiliate_id, 'approved' );
		$paid     = CommissionRepo::sum_for_affiliate( $affiliate_id, 'paid' );

		$tier_progress = TierResolver::progress_for_affiliate( $affiliate_id );

		$prefix      = (string) $settings->get( 'customer_coupon.prefix', 'PARTNER-' );
		$coupon_code = strtoupper( $prefix . $affiliate['referral_code'] );
		$ref_param   = (string) $settings->get( 'tracking.param', 'ref' );
		$site_url    = home_url( '/' );
		$ref_link    = add_query_arg( [ $ref_param => $affiliate['referral_code'] ], $site_url );

		$commissions = CommissionRepo::search( [ 'affiliate_id' => $affiliate_id, 'per_page' => 50 ] );
		$payouts     = PayoutRepo::search( [ 'affiliate_id' => $affiliate_id, 'per_page' => 50 ] );

		$materials = get_posts(
			[
				'post_type'      => 'pp_material',
				'posts_per_page' => 50,
				'post_status'    => 'publish',
			]
		);

		return [
			'affiliate'     => $affiliate,
			'user'          => wp_get_current_user(),
			'pending_cents' => $pending,
			'approved_cents'=> $approved,
			'paid_cents'    => $paid,
			'tier_progress' => $tier_progress,
			'tiers'         => TierResolver::tiers(),
			'coupon_code'   => $coupon_code,
			'ref_link'      => $ref_link,
			'ref_param'     => $ref_param,
			'commissions'   => $commissions,
			'payouts'       => $payouts,
			'materials'     => $materials,
			'agreement'     => AgreementRepo::current(),
			'settings'      => $settings,
			'enabled_methods' => (array) $settings->get( 'hold_payouts.enabled_methods', [] ),
			'min_threshold_cents' => Money::to_cents( (float) $settings->get( 'hold_payouts.min_threshold', 100 ) ),
		];
	}
}
