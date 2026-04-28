<?php
/**
 * Activation / deactivation handler.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Core;

use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Encryption;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		Installer::install();
		Capabilities::register_role();
		Capabilities::grant_admin_caps();
		Encryption::ensure_key();
		self::ensure_pages();
		self::schedule_crons();
		update_option( 'partner_program_db_version', PARTNER_PROGRAM_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		self::clear_crons();
		flush_rewrite_rules();
	}

	private static function ensure_pages(): void {
		$pages = [
			'partner_program_portal_page_id'      => [
				'slug'    => 'partner-portal',
				'title'   => __( 'Partner Portal', 'partner-program' ),
				'content' => '<!-- wp:shortcode -->[partner_program_portal]<!-- /wp:shortcode -->',
			],
			'partner_program_application_page_id' => [
				'slug'    => 'partner-application',
				'title'   => __( 'Partner Application', 'partner-program' ),
				'content' => '<!-- wp:shortcode -->[partner_program_application]<!-- /wp:shortcode -->',
			],
			'partner_program_login_page_id'       => [
				'slug'    => 'partner-login',
				'title'   => __( 'Partner Login', 'partner-program' ),
				'content' => '<!-- wp:shortcode -->[partner_program_login]<!-- /wp:shortcode -->',
			],
		];

		foreach ( $pages as $option_key => $page ) {
			$existing_id = (int) get_option( $option_key );
			// Only short-circuit when the prior page actually still exists
			// AND is published. If a site admin trashes / deletes the page,
			// the option is left dangling and we'd otherwise leave them
			// without a portal/application/login URL until they manually
			// re-create the page. Treat trashed/draft/private as "missing"
			// and recreate cleanly, clearing the stale option first.
			if ( $existing_id ) {
				$existing = get_post( $existing_id );
				if ( $existing && 'publish' === $existing->post_status ) {
					continue;
				}
				delete_option( $option_key );
			}

			$found = get_page_by_path( $page['slug'] );
			if ( $found && 'publish' === $found->post_status ) {
				update_option( $option_key, (int) $found->ID );
				continue;
			}

			$post_id = wp_insert_post(
				[
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => $page['content'],
				]
			);
			if ( ! is_wp_error( $post_id ) ) {
				update_option( $option_key, (int) $post_id );
			}
		}
	}

	public static function schedule_crons(): void {
		if ( ! wp_next_scheduled( 'partner_program_release_holds' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'partner_program_release_holds' );
		}
		if ( ! wp_next_scheduled( 'partner_program_recalculate_tiers' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'partner_program_recalculate_tiers' );
		}
		if ( ! wp_next_scheduled( 'partner_program_prune_logs' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'partner_program_prune_logs' );
		}
	}

	private static function clear_crons(): void {
		wp_clear_scheduled_hook( 'partner_program_release_holds' );
		wp_clear_scheduled_hook( 'partner_program_recalculate_tiers' );
		wp_clear_scheduled_hook( 'partner_program_prune_logs' );
	}
}
