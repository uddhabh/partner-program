<?php
/**
 * Admin applications list + review.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Application\PrivateUploads;
use PartnerProgram\Domain\ApplicationRepo;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\SettingsRepo;
use PartnerProgram\Support\Ui;

defined( 'ABSPATH' ) || exit;

final class ApplicationsScreen {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id ) {
			self::render_single( $id );
			return;
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = ApplicationRepo::search( [ 'status' => $status, 'per_page' => 50 ] );

		echo '<div class="wrap"><h1>' . esc_html__( 'Applications', 'partner-program' ) . '</h1>';
		Ui::status_filter(
			'partner-program-applications',
			$status,
			[
				''         => __( 'All', 'partner-program' ),
				'pending'  => __( 'Pending', 'partner-program' ),
				'approved' => __( 'Approved', 'partner-program' ),
				'rejected' => __( 'Rejected', 'partner-program' ),
			]
		);

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'partner-program' ) . '</th><th>' . esc_html__( 'Email', 'partner-program' ) . '</th><th>' . esc_html__( 'Status', 'partner-program' ) . '</th><th>' . esc_html__( 'Submitted', 'partner-program' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'partner-program' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>#%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td><a href="%5$s">%6$s</a></td></tr>',
				(int) $row['id'],
				esc_html( (string) $row['email'] ),
				esc_html( (string) $row['status'] ),
				esc_html( (string) $row['created_at'] ),
				esc_url( admin_url( 'admin.php?page=partner-program-applications&id=' . (int) $row['id'] ) ),
				esc_html__( 'Review', 'partner-program' )
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No applications.', 'partner-program' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_single( int $id ): void {
		$app = ApplicationRepo::find( $id );
		if ( ! $app ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Application not found.', 'partner-program' ) . '</h1></div>';
			return;
		}
		$data        = json_decode( (string) $app['submitted_data'], true ) ?: [];
		$uploads     = json_decode( (string) ( $app['uploaded_ids'] ?? '' ), true ) ?: [];
		$settings    = new SettingsRepo();
		$fields_def  = (array) $settings->get( 'application.fields', [] );
		$fields_by_k = [];
		foreach ( $fields_def as $f ) {
			$fk = isset( $f['key'] ) ? (string) $f['key'] : '';
			if ( '' !== $fk ) {
				$fields_by_k[ $fk ] = $f;
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Application', 'partner-program' ) . ' #' . (int) $app['id'] . '</h1>';
		if ( isset( $_GET['reviewed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = sanitize_key( (string) wp_unslash( $_GET['reviewed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'error' === $status ) {
				$reason = isset( $_GET['reason'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					? sanitize_key( (string) wp_unslash( $_GET['reason'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					: '';
				$detail = self::review_error_message( $reason );
				echo '<div class="notice notice-error is-dismissible"><p>'
					. esc_html__( 'Approval failed.', 'partner-program' )
					. ( '' !== $detail ? ' ' . esc_html( $detail ) : '' )
					. '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application updated.', 'partner-program' ) . '</p></div>';
			}
		}

		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Email', 'partner-program' ) . '</th><td>' . esc_html( (string) $app['email'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'partner-program' ) . '</th><td>' . esc_html( (string) $app['status'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Submitted', 'partner-program' ) . '</th><td>' . esc_html( (string) $app['created_at'] ) . '</td></tr>';
		foreach ( $data as $k => $v ) {
			$field = $fields_by_k[ (string) $k ] ?? [];
			$label = self::humanize_label( (string) $k, $field );
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . self::format_value( $v, $field ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		foreach ( $uploads as $k => $attachment_id ) {
			$field         = $fields_by_k[ (string) $k ] ?? [];
			$label         = self::humanize_label( (string) $k, $field );
			$attachment_id = (int) $attachment_id;
			// Always go through the auth-gated proxy. Direct attachment URLs
			// would (a) bypass the cap check and (b) for pre-1.2 uploads sit
			// in the public uploads tree.
			if ( $attachment_id > 0 && get_post( $attachment_id ) ) {
				$value = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( PrivateUploads::get_proxy_url( $attachment_id ) ),
					esc_html__( 'View upload', 'partner-program' )
				);
			} else {
				$value = esc_html__( 'Upload missing', 'partner-program' );
			}
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';

		if ( 'pending' === $app['status'] ) {
			echo '<h2>' . esc_html__( 'Review', 'partner-program' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="partner_program_review_application" />';
			echo '<input type="hidden" name="application_id" value="' . (int) $app['id' ] . '" />';
			wp_nonce_field( 'pp_review_application' );
			echo '<table class="form-table" role="presentation"><tbody>';
			echo '<tr><th scope="row"><label for="pp-review-notes">' . esc_html__( 'Notes', 'partner-program' ) . '</label></th>';
			echo '<td><textarea id="pp-review-notes" name="review_notes" rows="3" class="large-text"></textarea>';
			echo '<p class="description">' . esc_html__( 'Optional. Visible to admins only.', 'partner-program' ) . '</p></td></tr>';
			echo '</tbody></table>';
			echo '<p class="submit">';
			printf(
				'<button type="submit" name="decision" value="approve" class="button button-primary">%s</button> ',
				esc_html__( 'Approve', 'partner-program' )
			);
			printf(
				'<button type="submit" name="decision" value="reject" class="button button-secondary" onclick="return confirm(\'%s\')">%s</button>',
				esc_js( __( 'Reject this application?', 'partner-program' ) ),
				esc_html__( 'Reject', 'partner-program' )
			);
			echo '</p></form>';
		}

		echo '</div>';
	}

	/**
	 * Resolve a human-readable label for an applicant field row.
	 * Falls back to a humanized version of the key if no field definition matches
	 * (covers historical applications whose fields have since been renamed/removed).
	 *
	 * @param array<string, mixed> $field
	 */
	private static function humanize_label( string $key, array $field ): string {
		$label = isset( $field['label'] ) ? (string) $field['label'] : '';
		if ( '' !== $label ) {
			return $label;
		}
		return ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
	}

	/**
	 * Translate a WP_Error code surfaced by ApplicationReview::approve() into
	 * an admin-facing sentence. Unknown codes return "" so the caller falls
	 * back to the generic "Approval failed." headline.
	 */
	private static function review_error_message( string $reason ): string {
		switch ( $reason ) {
			case 'email_invalid':
				return __( 'The applicant email is missing or invalid.', 'partner-program' );
			case 'login_collision':
				return __( 'Could not generate a unique username for this applicant.', 'partner-program' );
			case 'user_create_failed':
				return __( 'Could not create a WordPress user for the applicant.', 'partner-program' );
			case 'affiliate_create_failed':
				return __( 'Could not create the affiliate record.', 'partner-program' );
			default:
				return '';
		}
	}

	/**
	 * Render an applicant field's value with light type-aware formatting.
	 * Returns escaped HTML.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $field
	 */
	private static function format_value( $value, array $field ): string {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		if ( 'checkbox' === $type ) {
			$truthy = ! empty( $value ) && '0' !== (string) $value;
			return esc_html( $truthy ? __( 'Yes', 'partner-program' ) : __( 'No', 'partner-program' ) );
		}

		if ( 'select' === $type && is_scalar( $value ) ) {
			$raw = (string) $value;
			foreach ( (array) ( $field['options'] ?? [] ) as $opt_key => $opt ) {
				if ( is_array( $opt ) ) {
					$opt_value = (string) ( $opt['value'] ?? $opt_key );
					$opt_label = (string) ( $opt['label'] ?? $opt_value );
				} else {
					$opt_value = (string) $opt;
					$opt_label = ucwords( str_replace( [ '_', '-' ], ' ', $opt_value ) );
				}
				if ( $opt_value === $raw ) {
					return esc_html( $opt_label );
				}
			}
			// Unknown option (e.g. removed from settings since submission): humanize.
			return esc_html( ucwords( str_replace( [ '_', '-' ], ' ', $raw ) ) );
		}

		if ( is_scalar( $value ) ) {
			return esc_html( (string) $value );
		}

		return esc_html( (string) wp_json_encode( $value ) );
	}
}
