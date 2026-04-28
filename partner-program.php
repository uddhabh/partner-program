<?php
/**
 * Plugin Name:       Partner Program for WooCommerce
 * Plugin URI:        https://beenacle.com/partner-program
 * Description:       White-label, fully configurable affiliate / partner program for WooCommerce. Tiered commissions, coupon attribution, hold periods, manual payouts, compliance gating, and a private partner portal. By Beenacle.
 * Version:           1.2.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Beenacle
 * Author URI:        https://beenacle.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       partner-program
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( defined( 'PARTNER_PROGRAM_VERSION' ) ) {
	return;
}

define( 'PARTNER_PROGRAM_VERSION', '1.2.0' );
define( 'PARTNER_PROGRAM_FILE', __FILE__ );
define( 'PARTNER_PROGRAM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PARTNER_PROGRAM_URL', plugin_dir_url( __FILE__ ) );
define( 'PARTNER_PROGRAM_BASENAME', plugin_basename( __FILE__ ) );
define( 'PARTNER_PROGRAM_TEXTDOMAIN', 'partner-program' );

require_once PARTNER_PROGRAM_DIR . 'src/Core/Autoloader.php';
\PartnerProgram\Core\Autoloader::register();

register_activation_hook( __FILE__, [ \PartnerProgram\Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \PartnerProgram\Core\Activator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		\PartnerProgram\Core\Plugin::instance()->boot();
	},
	5
);

/**
 * HPOS (High Performance Order Storage) compatibility declaration.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
