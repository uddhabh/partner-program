<?php
/**
 * Database installer / upgrader.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Core;

use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'pp_';

		$tables = [];

		$tables[] = "CREATE TABLE {$prefix}affiliates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			referral_code VARCHAR(64) NOT NULL,
			default_commission_rate DECIMAL(7,4) NULL,
			tier_id BIGINT UNSIGNED NULL,
			current_tier_id BIGINT UNSIGNED NULL,
			current_tier_key VARCHAR(40) NULL,
			payout_method VARCHAR(40) NULL,
			payout_details LONGTEXT NULL,
			agreement_version_accepted BIGINT UNSIGNED NULL,
			coupon_id BIGINT UNSIGNED NULL,
			notes LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY referral_code (referral_code),
			UNIQUE KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$prefix}applications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NULL,
			email VARCHAR(190) NOT NULL,
			submitted_data LONGTEXT NOT NULL,
			uploaded_ids LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			reviewer_id BIGINT UNSIGNED NULL,
			review_notes LONGTEXT NULL,
			ip_hash VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			reviewed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY email (email),
			KEY affiliate_id (affiliate_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$prefix}visits (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			referral_code VARCHAR(64) NOT NULL,
			ip_hash VARCHAR(64) NULL,
			user_agent VARCHAR(255) NULL,
			landing_url TEXT NULL,
			referrer_url TEXT NULL,
			converted_order_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY referral_code (referral_code),
			KEY converted_order_id (converted_order_id)
		) {$charset_collate};";

		// order_id is NULLABLE because manual adjustments are not tied to an order.
		// MySQL UNIQUE indexes treat each NULL as distinct, so adjustments coexist
		// while real orders get one-row-per-order enforcement.
		$tables[] = "CREATE TABLE {$prefix}commissions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NULL,
			order_item_id BIGINT UNSIGNED NULL,
			base_amount_cents BIGINT NOT NULL DEFAULT 0,
			rate DECIMAL(7,4) NOT NULL DEFAULT 0,
			amount_cents BIGINT NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			source VARCHAR(20) NOT NULL DEFAULT 'referral',
			coupon_used TINYINT(1) NOT NULL DEFAULT 0,
			coupon_code VARCHAR(190) NULL,
			hold_release_at DATETIME NULL,
			notes LONGTEXT NULL,
			payout_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			UNIQUE KEY uniq_order_id (order_id),
			KEY status (status),
			KEY hold_release_at (hold_release_at),
			KEY payout_id (payout_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$prefix}payouts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			period_start DATE NULL,
			period_end DATE NULL,
			total_amount_cents BIGINT NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			method VARCHAR(40) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			reference VARCHAR(190) NULL,
			notes LONGTEXT NULL,
			csv_batch_id VARCHAR(64) NULL,
			paid_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY status (status),
			KEY csv_batch_id (csv_batch_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$prefix}payout_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			payout_id BIGINT UNSIGNED NOT NULL,
			commission_id BIGINT UNSIGNED NOT NULL,
			amount_cents BIGINT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY commission_id (commission_id),
			KEY payout_id (payout_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$prefix}agreements (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			version BIGINT UNSIGNED NOT NULL,
			body_html LONGTEXT NOT NULL,
			summary VARCHAR(255) NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY version (version)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$prefix}agreement_acceptances (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			agreement_id BIGINT UNSIGNED NOT NULL,
			accepted_at DATETIME NOT NULL,
			ip_hash VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY agreement_id (agreement_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$prefix}logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			channel VARCHAR(40) NOT NULL DEFAULT 'general',
			actor_id BIGINT UNSIGNED NULL,
			subject_type VARCHAR(40) NULL,
			subject_id BIGINT UNSIGNED NULL,
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY channel (channel),
			KEY subject (subject_type, subject_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		foreach ( $tables as $sql ) {
			\dbDelta( $sql );
		}

		( new SettingsRepo() )->ensure_defaults();
	}

	/**
	 * Version-aware migration dispatcher. Idempotent, safe to call repeatedly.
	 * Triggered from Plugin::maybe_run_upgrades() with the previously-installed
	 * version. Currently a no-op — the pre-launch upgrade history (1.0 → 1.1.x)
	 * was scrubbed since no install carried real data; new entries get added
	 * here the first time we ship a non-additive schema or data change to a
	 * site that has data we want to preserve.
	 *
	 * @phpstan-param string $from_version
	 */
	public static function migrate( string $from_version ): void {
		// Reserved for future migrations:
		// if ( version_compare( $from_version, 'X.Y.Z', '<' ) ) {
		//     self::migrate_to_X_Y_Z();
		// }
		unset( $from_version );
	}
}
