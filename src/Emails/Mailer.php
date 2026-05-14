<?php
/**
 * Central transactional mailer.
 *
 * Subscribes to lifecycle action hooks (`partner_program_*`) and dispatches
 * one configurable email per event. Subject/body templates are loaded
 * from {@see EventRegistry} with optional per-event overrides stored in
 * settings, then token-replaced and wrapped in
 * `templates/emails/wrapper.php` for HTML delivery.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Emails;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\ApplicationRepo;
use PartnerProgram\Domain\PayoutRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;
use PartnerProgram\Support\Template;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	public function register(): void {
		add_action( 'partner_program_application_submitted', [ $this, 'on_application_submitted' ], 10, 2 );
		add_action( 'partner_program_affiliate_approved',    [ $this, 'on_affiliate_approved' ] );
		add_action( 'partner_program_application_rejected',  [ $this, 'on_application_rejected' ], 10, 2 );
		add_action( 'partner_program_affiliate_suspended',   [ $this, 'on_affiliate_suspended' ] );
		add_action( 'partner_program_violation_flagged',     [ $this, 'on_violation_flagged' ], 10, 2 );
		add_action( 'partner_program_payout_paid',           [ $this, 'on_payout_paid' ] );
		add_action( 'partner_program_commission_approved',   [ $this, 'on_commission_approved' ] );
	}

	/* ------------------------------------------------------------------
	 * Public dispatcher
	 * ----------------------------------------------------------------*/

	/**
	 * Render and send one event email.
	 *
	 * @param string               $event     Event key from EventRegistry.
	 * @param string|string[]      $to        Recipient(s). Empty/invalid => no-op.
	 * @param array<string,mixed>  $tokens    Token replacements.
	 * @param array<string,mixed>  $context   Extra context exposed to filters.
	 * @return bool True if wp_mail returned true, false otherwise.
	 */
	public static function send( string $event, $to, array $tokens, array $context = [] ): bool {
		$definition = EventRegistry::get( $event );
		if ( ! $definition ) {
			return false;
		}

		$settings = new SettingsRepo();
		$config   = (array) $settings->get( 'emails.events.' . $event, [] );
		$enabled  = array_key_exists( 'enabled', $config ) ? (bool) $config['enabled'] : (bool) $definition['default_enabled'];

		/**
		 * Suppress sending of a specific event. Return false to silently drop.
		 */
		$enabled = (bool) apply_filters( 'partner_program_email_enabled', $enabled, $event, $context );
		if ( ! $enabled ) {
			return false;
		}

		$recipients = self::normalize_recipients( $to );
		/**
		 * Filter the recipient list. Return an empty array to skip the send.
		 *
		 * @param string[]            $recipients
		 * @param string              $event
		 * @param array<string,mixed> $context
		 */
		$recipients = (array) apply_filters( 'partner_program_email_recipients', $recipients, $event, $context );
		$recipients = self::normalize_recipients( $recipients );
		if ( ! $recipients ) {
			return false;
		}

		$subject_tpl = self::trim_or_default( (string) ( $config['subject'] ?? '' ), (string) $definition['subject'] );
		$body_tpl    = self::trim_or_default( (string) ( $config['body'] ?? '' ), (string) $definition['body'] );

		$subject = self::replace_tokens( $subject_tpl, $tokens );
		$subject = wp_specialchars_decode( wp_strip_all_tags( $subject ), ENT_QUOTES );

		$body_plain = self::replace_tokens( $body_tpl, $tokens );

		/** @var string $subject */
		$subject = (string) apply_filters( 'partner_program_email_subject', $subject, $event, $tokens, $context );
		/** @var string $body_plain */
		$body_plain = (string) apply_filters( 'partner_program_email_body', $body_plain, $event, $tokens, $context );

		$body_html = self::wrap_html( $body_plain, $tokens );

		$headers = self::build_headers( $settings );

		return (bool) wp_mail( $recipients, $subject, $body_html, $headers );
	}

	/* ------------------------------------------------------------------
	 * Lifecycle handlers
	 * ----------------------------------------------------------------*/

	public function on_application_submitted( int $application_id, array $data ): void {
		$settings = new SettingsRepo();
		$program  = self::program_name( $settings );

		$lines = [];
		foreach ( $data as $k => $v ) {
			$lines[] = $k . ': ' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
		}

		$tokens = [
			'{program_name}'    => $program,
			'{applicant_name}'  => (string) ( $data['full_name'] ?? '' ),
			'{applicant_email}' => (string) ( $data['email'] ?? '' ),
			'{application_id}'  => (string) $application_id,
			'{fields_dump}'     => implode( "\n", $lines ),
			'{review_url}'      => admin_url( 'admin.php?page=partner-program-applications&id=' . $application_id ),
		];

		$to = (string) $settings->get( 'general.support_email', get_option( 'admin_email' ) );
		self::send( 'application_received', $to, $tokens, [ 'application_id' => $application_id, 'data' => $data ] );
	}

	public function on_affiliate_approved( int $affiliate_id ): void {
		$affiliate = AffiliateRepo::find( $affiliate_id );
		if ( ! $affiliate ) {
			return;
		}
		$user = get_user_by( 'id', (int) $affiliate['user_id'] );
		if ( ! $user ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}'  => self::program_name( $settings ),
			'{partner_name}'  => $user->display_name,
			'{partner_email}' => $user->user_email,
			'{referral_code}' => (string) $affiliate['referral_code'],
			'{portal_url}'    => self::portal_url(),
			'{login_url}'     => self::login_url( $settings ),
		];

		self::send( 'application_approved', $user->user_email, $tokens, [ 'affiliate_id' => $affiliate_id ] );
	}

	public function on_application_rejected( int $application_id, string $notes ): void {
		$application = ApplicationRepo::find( $application_id );
		if ( ! $application ) {
			return;
		}
		$data     = json_decode( (string) ( $application['submitted_data'] ?? '' ), true ) ?: [];
		$email    = (string) ( $application['email'] ?? ( $data['email'] ?? '' ) );
		if ( '' === $email ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}'    => self::program_name( $settings ),
			'{applicant_name}'  => (string) ( $data['full_name'] ?? $email ),
			'{applicant_email}' => $email,
			'{review_notes}'    => $notes,
			'{support_email}'   => (string) $settings->get( 'general.support_email', get_option( 'admin_email' ) ),
		];

		self::send( 'application_rejected', $email, $tokens, [ 'application_id' => $application_id ] );
	}

	public function on_affiliate_suspended( int $affiliate_id ): void {
		$affiliate = AffiliateRepo::find( $affiliate_id );
		if ( ! $affiliate ) {
			return;
		}
		$user = get_user_by( 'id', (int) $affiliate['user_id'] );
		if ( ! $user ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}'  => self::program_name( $settings ),
			'{partner_name}'  => $user->display_name,
			'{partner_email}' => $user->user_email,
			'{support_email}' => (string) $settings->get( 'general.support_email', get_option( 'admin_email' ) ),
			'{portal_url}'    => self::portal_url(),
		];

		self::send( 'affiliate_suspended', $user->user_email, $tokens, [ 'affiliate_id' => $affiliate_id ] );
	}

	public function on_violation_flagged( int $affiliate_id, string $reason ): void {
		$affiliate = AffiliateRepo::find( $affiliate_id );
		$user      = $affiliate ? get_user_by( 'id', (int) $affiliate['user_id'] ) : null;
		$settings  = new SettingsRepo();

		$tokens = [
			'{program_name}'  => self::program_name( $settings ),
			'{affiliate_id}'  => (string) $affiliate_id,
			'{partner_name}'  => $user ? $user->display_name : '',
			'{partner_email}' => $user ? $user->user_email : '',
			'{reason}'        => $reason,
			'{affiliate_url}' => admin_url( 'admin.php?page=partner-program-affiliates&id=' . $affiliate_id ),
		];

		$to = (string) $settings->get( 'general.support_email', get_option( 'admin_email' ) );
		self::send( 'violation_flagged', $to, $tokens, [ 'affiliate_id' => $affiliate_id, 'reason' => $reason ] );
	}

	public function on_payout_paid( int $payout_id ): void {
		$payout = PayoutRepo::find( $payout_id );
		if ( ! $payout ) {
			return;
		}
		$affiliate = AffiliateRepo::find( (int) $payout['affiliate_id'] );
		if ( ! $affiliate ) {
			return;
		}
		$user = get_user_by( 'id', (int) $affiliate['user_id'] );
		if ( ! $user ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}' => self::program_name( $settings ),
			'{partner_name}' => $user->display_name,
			'{amount}'       => Money::format( (int) ( $payout['amount_cents'] ?? 0 ) ),
			'{method}'       => (string) ( $payout['method'] ?? '' ),
			'{reference}'    => (string) ( $payout['reference'] ?? '' ),
			'{portal_url}'   => self::portal_url(),
		];

		self::send( 'payout_paid', $user->user_email, $tokens, [ 'payout_id' => $payout_id ] );
	}

	public function on_commission_approved( int $commission_id ): void {
		$commission = CommissionRepo::find( $commission_id );
		if ( ! $commission ) {
			return;
		}
		$affiliate = AffiliateRepo::find( (int) $commission['affiliate_id'] );
		if ( ! $affiliate ) {
			return;
		}
		$user = get_user_by( 'id', (int) $affiliate['user_id'] );
		if ( ! $user ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}' => self::program_name( $settings ),
			'{partner_name}' => $user->display_name,
			'{amount}'       => Money::format( (int) ( $commission['amount_cents'] ?? 0 ) ),
			'{portal_url}'   => self::portal_url(),
		];

		self::send( 'commission_approved', $user->user_email, $tokens, [ 'commission_id' => $commission_id ] );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------*/

	/**
	 * @param array<string,mixed> $tokens
	 */
	private static function replace_tokens( string $template, array $tokens ): string {
		$search  = [];
		$replace = [];
		foreach ( $tokens as $k => $v ) {
			$search[]  = $k;
			$replace[] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
		}
		return str_replace( $search, $replace, $template );
	}

	/**
	 * Wrap a plain-text body in the branded HTML shell. Author-supplied
	 * HTML in the body is preserved (passed through wp_kses_post in the
	 * wrapper); plain newlines are converted via wpautop.
	 *
	 * @param array<string,mixed> $tokens
	 */
	private static function wrap_html( string $body, array $tokens ): string {
		$settings = new SettingsRepo();
		return Template::render(
			'emails/wrapper.php',
			[
				'body'         => $body,
				'tokens'       => $tokens,
				'program_name' => self::program_name( $settings ),
				'logo_url'     => (string) $settings->get( 'general.logo_url', '' ),
				'accent_color' => (string) $settings->get( 'general.accent_color', '#2563eb' ),
				'footer_text'  => self::footer_text( $settings ),
			]
		);
	}

	private static function build_headers( SettingsRepo $settings ): array {
		$from_name  = (string) $settings->get( 'emails.from_name', '' );
		$from_email = (string) $settings->get( 'emails.from_email', '' );

		if ( '' === $from_name ) {
			$from_name = self::program_name( $settings );
		}
		if ( '' === $from_email || ! is_email( $from_email ) ) {
			$from_email = (string) $settings->get( 'general.support_email', get_option( 'admin_email' ) );
		}

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
			$headers[] = 'Reply-To: ' . $from_email;
		}
		return $headers;
	}

	/**
	 * @param string|string[] $to
	 * @return string[]
	 */
	private static function normalize_recipients( $to ): array {
		$out = [];
		foreach ( (array) $to as $addr ) {
			$addr = is_string( $addr ) ? trim( $addr ) : '';
			if ( '' !== $addr && is_email( $addr ) ) {
				$out[] = $addr;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function trim_or_default( string $candidate, string $fallback ): string {
		$candidate = trim( $candidate );
		return '' === $candidate ? $fallback : $candidate;
	}

	private static function program_name( SettingsRepo $settings ): string {
		return (string) $settings->get( 'general.program_name', __( 'Partner Program', 'partner-program' ) );
	}

	private static function portal_url(): string {
		$portal_id = (int) get_option( 'partner_program_portal_page_id' );
		return $portal_id ? (string) get_permalink( $portal_id ) : home_url( '/partner-portal/' );
	}

	private static function login_url( SettingsRepo $settings ): string {
		$override = (string) $settings->get( 'general.login_url', '' );
		return '' !== $override ? $override : wp_login_url();
	}

	private static function footer_text( SettingsRepo $settings ): string {
		$default = sprintf(
			/* translators: %s: program name */
			__( 'You are receiving this email because of your involvement with the %s.', 'partner-program' ),
			self::program_name( $settings )
		);
		return (string) $settings->get( 'emails.footer_text', $default ) ?: $default;
	}
}
