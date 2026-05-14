<?php
/**
 * Admin actions for application approval / rejection.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Application;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\AgreementRepo;
use PartnerProgram\Domain\ApplicationRepo;
use PartnerProgram\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class ApplicationReview {

	public function register(): void {
		add_action( 'admin_post_partner_program_review_application', [ $this, 'handle_review' ] );
	}

	public function handle_review(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'partner-program' ) );
		}
		check_admin_referer( 'pp_review_application' );

		$application_id = isset( $_POST['application_id'] ) ? (int) $_POST['application_id'] : 0;
		$action         = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['decision'] ) ) : '';
		$notes          = isset( $_POST['review_notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['review_notes'] ) ) : '';

		$application = ApplicationRepo::find( $application_id );
		if ( ! $application ) {
			wp_safe_redirect( admin_url( 'admin.php?page=partner-program-applications' ) );
			exit;
		}

		$result = true;
		if ( 'approve' === $action ) {
			$result = $this->approve( $application, $notes );
		} elseif ( 'reject' === $action ) {
			ApplicationRepo::update(
				$application_id,
				[
					'status'       => 'rejected',
					'reviewer_id'  => get_current_user_id(),
					'review_notes' => $notes,
					'reviewed_at'  => current_time( 'mysql', true ),
				]
			);
			do_action( 'partner_program_application_rejected', $application_id, $notes );
		}

		$args = [ 'page' => 'partner-program-applications', 'id' => $application_id ];
		if ( is_wp_error( $result ) ) {
			$args['reviewed'] = 'error';
			$args['reason']   = $result->get_error_code() ?: 'unknown';
		} else {
			$args['reviewed'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return true|\WP_Error
	 */
	private function approve( array $application, string $notes ) {
		$data  = json_decode( (string) $application['submitted_data'], true ) ?: [];
		$email = (string) $application['email'];

		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'email_invalid', __( 'Application email is missing or invalid.', 'partner-program' ) );
		}

		$user_id = email_exists( $email );
		if ( ! $user_id ) {
			$base_login = sanitize_user( current( explode( '@', $email ) ), true );
			$login      = $base_login;
			$i          = 1;
			while ( username_exists( $login ) ) {
				$login = $base_login . $i++;
				if ( $i > 50 ) {
					return new \WP_Error( 'login_collision', __( 'Could not generate a unique username for this applicant.', 'partner-program' ) );
				}
			}
			$password = wp_generate_password( 16 );
			$user_id  = wp_insert_user(
				[
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => $password,
					'display_name' => (string) ( $data['full_name'] ?? $login ),
					'role'         => Capabilities::ROLE_PARTNER,
				]
			);
			if ( is_wp_error( $user_id ) ) {
				return new \WP_Error( 'user_create_failed', $user_id->get_error_message() );
			}
			wp_new_user_notification( (int) $user_id, null, 'both' );
		} else {
			$user = get_user_by( 'id', $user_id );
			if ( $user && ! in_array( Capabilities::ROLE_PARTNER, (array) $user->roles, true ) ) {
				$user->add_role( Capabilities::ROLE_PARTNER );
			}
		}

		$existing = AffiliateRepo::find_by_user( (int) $user_id );
		if ( $existing ) {
			$affiliate_id = (int) $existing['id'];
			AffiliateRepo::update(
				$affiliate_id,
				[
					'status' => 'approved',
				]
			);
		} else {
			$hint        = (string) ( $data['business_name'] ?? $data['full_name'] ?? '' );
			$code        = AffiliateRepo::generate_unique_code( $hint );
			$current_agr = AgreementRepo::current();
			$affiliate_id = AffiliateRepo::create(
				[
					'user_id'                    => (int) $user_id,
					'status'                     => 'approved',
					'referral_code'              => $code,
					'agreement_version_accepted' => $current_agr ? (int) $current_agr['id'] : null,
				]
			);
			if ( ! $affiliate_id ) {
				return new \WP_Error( 'affiliate_create_failed', __( 'Failed to create the affiliate record.', 'partner-program' ) );
			}
		}

		ApplicationRepo::update(
			(int) $application['id'],
			[
				'affiliate_id' => $affiliate_id,
				'status'       => 'approved',
				'reviewer_id'  => get_current_user_id(),
				'review_notes' => $notes,
				'reviewed_at'  => current_time( 'mysql', true ),
			]
		);

		do_action( 'partner_program_affiliate_approved', $affiliate_id );
		return true;
	}
}
