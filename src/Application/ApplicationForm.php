<?php
/**
 * Public application form: shortcode + block + submission handler.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Application;

use PartnerProgram\Domain\ApplicationRepo;
use PartnerProgram\Support\SettingsRepo;
use PartnerProgram\Support\Template;
use PartnerProgram\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class ApplicationForm {

	public const NONCE_ACTION = 'partner_program_apply';
	public const NONCE_FIELD  = '_pp_apply_nonce';

	public function register(): void {
		add_shortcode( 'partner_program_application', [ $this, 'render_shortcode' ] );
		add_action( 'admin_post_nopriv_partner_program_apply', [ $this, 'handle_submission' ] );
		add_action( 'admin_post_partner_program_apply', [ $this, 'handle_submission' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets(): void {
		// Always register so that render_shortcode() can enqueue the handle
		// regardless of whether the shortcode lives on a singular template,
		// an archive widget, a block-template part, or a REST-rendered page.
		wp_register_style( 'partner-program-components', PARTNER_PROGRAM_URL . 'assets/css/components.css', [], PARTNER_PROGRAM_VERSION );
		wp_register_style( 'partner-program-frontend', PARTNER_PROGRAM_URL . 'assets/css/frontend.css', [ 'partner-program-components' ], PARTNER_PROGRAM_VERSION );
	}

	public function render_shortcode( $atts = [] ): string {
		wp_enqueue_style( 'partner-program-frontend' );
		$settings = new SettingsRepo();
		$fields   = (array) $settings->get( 'application.fields', [] );
		$fields   = apply_filters( 'partner_program_application_fields', $fields );

		$flash = $this->consume_flash();

		$html = Template::render(
			'application/form.php',
			[
				'fields'   => $fields,
				'settings' => $settings,
				'flash'    => $flash,
				'action'   => esc_url( admin_url( 'admin-post.php' ) ),
				'nonce'    => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false ),
			]
		);

		// Collapse whitespace between adjacent tags. WP runs `wpautop`
		// on `the_content` (which expands shortcodes), and any newline
		// between two tags inside what wpautop sees as a paragraph turns
		// into a stray `<br />`. This is a known shortcode-output footgun
		// — by stripping inter-tag whitespace we leave wpautop nothing to
		// convert. Text content between tags is preserved (the regex only
		// matches a `>` followed by whitespace followed by `<`).
		return (string) preg_replace( '/>\s+</', '><', $html );
	}

	public function handle_submission(): void {
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$settings = new SettingsRepo();
		$fields   = (array) $settings->get( 'application.fields', [] );

		// Honeypot.
		if ( ! empty( $_POST['hp_website'] ) ) {
			$this->redirect_back( __( 'Submission rejected.', 'partner-program' ), 'error' );
		}

		$ip_hash = Tracker::ip_hash();
		// Simple rate-limit: 1 submission per IP per minute.
		$rl_key = 'pp_apply_rl_' . $ip_hash;
		if ( $ip_hash && get_transient( $rl_key ) ) {
			$this->redirect_back( __( 'Please wait before submitting again.', 'partner-program' ), 'error' );
		}
		set_transient( $rl_key, 1, MINUTE_IN_SECONDS );

		$data         = [];
		$uploaded_ids = [];
		$errors       = [];

		foreach ( $fields as $field ) {
			$key      = (string) ( $field['key'] ?? '' );
			$type     = (string) ( $field['type'] ?? 'text' );
			$required = ! empty( $field['required'] );
			$label    = (string) ( $field['label'] ?? $key );

			if ( '' === $key ) {
				continue;
			}

			if ( 'file' === $type ) {
				if ( ! empty( $_FILES[ $key ]['name'] ) ) {
					$attachment_id = $this->handle_upload( $key );
					if ( $attachment_id ) {
						$uploaded_ids[ $key ] = $attachment_id;
					} elseif ( $required ) {
						$errors[] = sprintf( __( 'Could not upload %s.', 'partner-program' ), $label );
					}
				} elseif ( $required ) {
					$errors[] = sprintf( __( '%s is required.', 'partner-program' ), $label );
				}
				continue;
			}

			if ( 'checkbox' === $type ) {
				$value = ! empty( $_POST[ $key ] );
				if ( $required && ! $value ) {
					$errors[] = sprintf( __( '%s is required.', 'partner-program' ), $label );
				}
				$data[ $key ] = $value ? 1 : 0;
				continue;
			}

			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			if ( is_array( $raw ) ) {
				$value = array_map( 'sanitize_text_field', $raw );
			} elseif ( 'textarea' === $type ) {
				$value = sanitize_textarea_field( (string) $raw );
			} elseif ( 'email' === $type ) {
				$value = sanitize_email( (string) $raw );
			} else {
				$value = sanitize_text_field( (string) $raw );
			}

			if ( $required && ( '' === $value || ( is_array( $value ) && ! $value ) ) ) {
				$errors[] = sprintf( __( '%s is required.', 'partner-program' ), $label );
			}
			if ( 'email' === $type && $value && ! is_email( $value ) ) {
				$errors[] = sprintf( __( '%s must be a valid email.', 'partner-program' ), $label );
			}

			$data[ $key ] = $value;
		}

		$email = (string) ( $data['email'] ?? '' );
		if ( ! $email ) {
			$errors[] = __( 'Email is required.', 'partner-program' );
		}

		if ( $errors ) {
			$this->redirect_back( implode( ' ', $errors ), 'error' );
		}

		$application_id = ApplicationRepo::create(
			[
				'email'          => $email,
				'submitted_data' => wp_json_encode( $data ) ?: '{}',
				'uploaded_ids'   => $uploaded_ids ? wp_json_encode( $uploaded_ids ) : null,
				'ip_hash'        => $ip_hash,
			]
		);

		do_action( 'partner_program_application_submitted', $application_id, $data );

		$this->redirect_back(
			__( 'Thanks for applying. We will review your application and email you when you are approved.', 'partner-program' ),
			'success'
		);
	}

	private function handle_upload( string $key ): ?int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$allowed = [
			'pdf'  => 'application/pdf',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
		];

		$original = isset( $_FILES[ $key ]['name'] ) ? (string) $_FILES[ $key ]['name'] : '';

		$attachment_id = PrivateUploads::with_private_dir(
			static function ( callable $rename ) use ( $key, $allowed ) {
				return media_handle_upload(
					$key,
					0,
					[],
					[
						'mimes'                    => $allowed,
						'test_form'                => false,
						'unique_filename_callback' => $rename,
					]
				);
			}
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return null;
		}

		PrivateUploads::mark_private( (int) $attachment_id, $original );
		return (int) $attachment_id;
	}

	private function redirect_back( string $message, string $type = 'success' ): void {
		set_transient( 'pp_apply_flash_' . $this->flash_key(), [ 'message' => $message, 'type' => $type ], 60 );
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? add_query_arg( [ 'pp_apply' => $type ], $ref ) : home_url( '/' ) );
		exit;
	}

	/**
	 * Build a stable per-visitor key for the application flash transient.
	 *
	 * Anonymous visitors don't have a WordPress session token, so falling
	 * back to wp_get_session_token() returns "" and every anonymous applicant
	 * shares the same transient — the next visitor's form would render with
	 * the previous applicant's flash message. We mix in a hash of the IP and
	 * user-agent for those visitors. Logged-in users keep the session-token
	 * key (rotates on logout, immune to UA changes).
	 */
	private function flash_key(): string {
		$session = wp_get_session_token();
		if ( '' !== $session ) {
			return 'sess:' . $session;
		}
		$ip = Tracker::ip_hash();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( '' !== $ip || '' !== $ua ) {
			return 'anon:' . substr( hash( 'sha256', $ip . '|' . $ua ), 0, 24 );
		}
		return 'anon:fallback';
	}

	private function consume_flash(): ?array {
		$key = 'pp_apply_flash_' . $this->flash_key();
		$val = get_transient( $key );
		if ( $val ) {
			delete_transient( $key );
			return is_array( $val ) ? $val : null;
		}
		if ( isset( $_GET['pp_apply'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = sanitize_text_field( wp_unslash( (string) $_GET['pp_apply'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return [
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => 'success' === $type
					? __( 'Application received.', 'partner-program' )
					: __( 'There was a problem with your submission.', 'partner-program' ),
			];
		}
		return null;
	}
}
