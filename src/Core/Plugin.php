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
use PartnerProgram\Support\SettingsRepo;
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

	/** @var array<string, object> */
	private array $services = [];

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

		$this->register_core_services();

		add_action( 'init', [ $this, 'on_init' ], 5 );
		add_action( 'admin_init', [ $this, 'maybe_run_upgrades' ] );
		add_action( 'partner_program_release_holds', [ HoldReleaser::class, 'release_due' ] );
		add_action( 'partner_program_recalculate_tiers', [ TierResolver::class, 'recalculate_all' ] );

		$this->boot_subsystems();

		Privacy::register();

		if ( is_admin() ) {
			Updater::register();
		}

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			Commands::register();
		}
	}

	public function on_init(): void {
		Capabilities::register_role();
		do_action( 'partner_program_init', $this );
	}

	public function maybe_run_upgrades(): void {
		$installed = get_option( 'partner_program_db_version' );
		if ( PARTNER_PROGRAM_VERSION !== $installed ) {
			Installer::install();
			update_option( 'partner_program_db_version', PARTNER_PROGRAM_VERSION );
		}
	}

	public function get( string $key ): ?object {
		return $this->services[ $key ] ?? null;
	}

	public function set( string $key, object $service ): void {
		$this->services[ $key ] = $service;
	}

	private function register_core_services(): void {
		$this->set( 'logger', new Logger() );
		$this->set( 'settings', new SettingsRepo() );
		$this->set( 'encryption', new Encryption() );
	}

	private function boot_subsystems(): void {
		( new AdminMenu() )->register();
		( new Settings() )->register();
		( new ApplicationForm() )->register();
		( new ApplicationReview() )->register();
		( new Portal() )->register();
		( new Tracker() )->register();
		( new CouponManager() )->register();
		( new OrderHooks() )->register();
		( new CommissionEngine() )->register();
		( new HoldReleaser() )->register();
		( new TierResolver() )->register();
		( new PayoutManager() )->register();
		( new AgreementManager() )->register();
		( new ProhibitedTermsScanner() )->register();
		( new RestController() )->register();
	}
}
