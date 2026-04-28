<?php
/**
 * Plugin bootstrap / service container.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Core;

use PartnerProgram\Admin\AdminMenu;
use PartnerProgram\Admin\Settings;
use PartnerProgram\Application\ApplicationForm;
use PartnerProgram\Application\ApplicationReview;
use PartnerProgram\Application\PrivateUploads;
use PartnerProgram\Cli\Commands;
use PartnerProgram\Compliance\AgreementManager;
use PartnerProgram\Compliance\ProhibitedTermsScanner;
use PartnerProgram\Frontend\Portal;
use PartnerProgram\Payouts\PayoutManager;
use PartnerProgram\Rest\RestController;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Encryption;
use PartnerProgram\Support\Logger;
use PartnerProgram\Support\Privacy;
use PartnerProgram\Support\Updater;
use PartnerProgram\Tracking\Tracker;
use PartnerProgram\Woo\OrderHooks;
use PartnerProgram\Woo\CouponManager;
use PartnerProgram\Domain\CommissionEngine;
use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Domain\HoldReleaser;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( PARTNER_PROGRAM_TEXTDOMAIN, false, dirname( PARTNER_PROGRAM_BASENAME ) . '/languages' );

		add_action( 'init', [ $this, 'on_init' ], 5 );
		add_action( 'admin_init', [ $this, 'maybe_run_upgrades' ] );
		add_action( 'partner_program_release_holds', [ HoldReleaser::class, 'release_due' ] );
		add_action( 'partner_program_recalculate_tiers', [ TierResolver::class, 'recalculate_all' ] );
		add_action( 'partner_program_prune_logs', [ Logger::class, 'run_scheduled_prune' ] );

		$this->boot_subsystems();

		Privacy::register();

		// Updater also runs on cron-driven update checks (wp_update_plugins),
		// so we register it on every request, not just admin pageloads.
		Updater::register();

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			Commands::register();
		}
	}

	public function on_init(): void {
		// NOTE: role/cap registration intentionally lives in
		// Activator::activate() and maybe_run_upgrades(), NOT here.
		// WP_Role::add_cap() always writes to wp_user_roles even when the
		// cap is already set, so doing it on every init triggered three
		// autoloaded option writes per front-end pageview.
		do_action( 'partner_program_init', $this );
	}

	public function maybe_run_upgrades(): void {
		$installed = (string) get_option( 'partner_program_db_version', '' );
		if ( PARTNER_PROGRAM_VERSION === $installed ) {
			return;
		}
		// dbDelta is idempotent and adds any new columns/tables a release
		// introduced. Caps, encryption-key, and cron-event registration
		// are likewise idempotent so existing installs pick up anything
		// new without going through activate().
		Installer::install();
		Capabilities::register_role();
		Capabilities::grant_admin_caps();
		Encryption::ensure_key();
		Activator::schedule_crons();
		update_option( 'partner_program_db_version', PARTNER_PROGRAM_VERSION );
	}

	private function boot_subsystems(): void {
		// HoldReleaser, TierResolver, ProhibitedTermsScanner are pure
		// static utilities — their cron / scan hooks are wired directly
		// in boot() above, so they don't need register() instantiations.
		( new AdminMenu() )->register();
		( new Settings() )->register();
		( new ApplicationForm() )->register();
		( new ApplicationReview() )->register();
		( new PrivateUploads() )->register();
		( new Portal() )->register();
		( new Tracker() )->register();
		( new CouponManager() )->register();
		( new OrderHooks() )->register();
		( new CommissionEngine() )->register();
		( new PayoutManager() )->register();
		( new AgreementManager() )->register();
		( new RestController() )->register();
	}
}
