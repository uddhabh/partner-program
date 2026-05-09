<?php
/**
 * Top-level admin menu.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		// CPT for marketing materials.
		add_action( 'init', [ $this, 'register_material_cpt' ] );
	}

	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'partner-program' ) ) {
			return;
		}
		wp_enqueue_style( 'partner-program-components', PARTNER_PROGRAM_URL . 'assets/css/components.css', [], PARTNER_PROGRAM_VERSION );
		wp_enqueue_style( 'partner-program-admin', PARTNER_PROGRAM_URL . 'assets/css/admin.css', [ 'partner-program-components' ], PARTNER_PROGRAM_VERSION );
	}

	public function add_menu(): void {
		$cap = Capabilities::CAP_MANAGE;

		add_menu_page(
			__( 'Partner Program', 'partner-program' ),
			__( 'Partner Program', 'partner-program' ),
			$cap,
			'partner-program',
			[ $this, 'render_dashboard' ],
			'dashicons-groups',
			56
		);

		add_submenu_page( 'partner-program', __( 'Dashboard', 'partner-program' ), __( 'Dashboard', 'partner-program' ), $cap, 'partner-program', [ $this, 'render_dashboard' ] );
		add_submenu_page( 'partner-program', __( 'Affiliates', 'partner-program' ), __( 'Affiliates', 'partner-program' ), $cap, 'partner-program-affiliates', [ AffiliatesScreen::class, 'render' ] );
		add_submenu_page( 'partner-program', __( 'Applications', 'partner-program' ), __( 'Applications', 'partner-program' ), $cap, 'partner-program-applications', [ ApplicationsScreen::class, 'render' ] );
		add_submenu_page( 'partner-program', __( 'Commissions', 'partner-program' ), __( 'Commissions', 'partner-program' ), $cap, 'partner-program-commissions', [ CommissionsScreen::class, 'render' ] );
		add_submenu_page( 'partner-program', __( 'Payouts', 'partner-program' ), __( 'Payouts', 'partner-program' ), $cap, 'partner-program-payouts', [ PayoutsScreen::class, 'render' ] );
		add_submenu_page( 'partner-program', __( 'Materials', 'partner-program' ), __( 'Materials', 'partner-program' ), $cap, 'edit.php?post_type=pp_material' );
		add_submenu_page( 'partner-program', __( 'Compliance', 'partner-program' ), __( 'Compliance', 'partner-program' ), $cap, 'partner-program-compliance', [ ComplianceScreen::class, 'render' ] );
		add_submenu_page( 'partner-program', __( 'Settings', 'partner-program' ), __( 'Settings', 'partner-program' ), $cap, 'partner-program-settings', [ Settings::class, 'render_page' ] );
		add_submenu_page( 'partner-program', __( 'Logs', 'partner-program' ), __( 'Logs', 'partner-program' ), $cap, 'partner-program-logs', [ LogsScreen::class, 'render' ] );
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}
		\PartnerProgram\Admin\DashboardScreen::render();
	}

	public function register_material_cpt(): void {
		register_post_type(
			'pp_material',
			[
				'labels'             => [
					'name'          => __( 'Marketing Materials', 'partner-program' ),
					'singular_name' => __( 'Marketing Material', 'partner-program' ),
					'add_new_item'  => __( 'Add new material', 'partner-program' ),
					'edit_item'     => __( 'Edit material', 'partner-program' ),
				],
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				// Dedicated capability_type so a site editor with the default
				// `edit_posts` cap can't suddenly publish/edit partner
				// marketing materials. map_meta_cap routes meta-caps
				// (edit_post, read_post, delete_post) through these primitives.
				'capability_type'    => [ 'pp_material', 'pp_materials' ],
				'map_meta_cap'       => true,
				'supports'           => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
				'menu_icon'          => 'dashicons-megaphone',
			]
		);
	}
}
