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
	 * Version-aware schema/data migrations. Idempotent, safe to call repeatedly.
	 * Triggered from Plugin::maybe_run_upgrades() with the previously-installed
	 * version so each block runs at most once per site.
	 */
	public static function migrate( string $from_version ): void {
		if ( '' === $from_version || '0' === $from_version ) {
			$from_version = '0.0.0';
		}

		if ( version_compare( $from_version, '1.1.0', '<' ) ) {
			self::migrate_to_1_1_0();
		}
	}

	/**
	 * 1.1.0 — UNIQUE constraint on commissions.order_id (manual adjustments
	 * become NULL), tier-key column on affiliates, settings cleanup.
	 */
	private static function migrate_to_1_1_0(): void {
		global $wpdb;

		$commissions = $wpdb->prefix . 'pp_commissions';
		$affiliates  = $wpdb->prefix . 'pp_affiliates';

		// Move adjustment rows from order_id=0 to NULL BEFORE adding the unique index.
		$wpdb->query( "UPDATE {$commissions} SET order_id = NULL WHERE order_id = 0 AND source = 'adjustment'" ); // phpcs:ignore WordPress.DB

		// Make order_id nullable if not already.
		$col = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$commissions} LIKE %s", 'order_id' ), ARRAY_A );
		if ( $col && false === stripos( (string) ( $col['Null'] ?? '' ), 'YES' ) ) {
			$wpdb->query( "ALTER TABLE {$commissions} MODIFY order_id BIGINT UNSIGNED NULL" ); // phpcs:ignore WordPress.DB
		}

		// Swap the non-unique KEY order_id for UNIQUE KEY uniq_order_id.
		$indexes  = $wpdb->get_results( "SHOW INDEX FROM {$commissions}", ARRAY_A ) ?: [];
		$has_uniq = false;
		$has_old  = false;
		foreach ( $indexes as $idx ) {
			if ( 'uniq_order_id' === ( $idx['Key_name'] ?? '' ) ) {
				$has_uniq = true;
			}
			if ( 'order_id' === ( $idx['Key_name'] ?? '' ) && 1 === (int) ( $idx['Non_unique'] ?? 1 ) ) {
				$has_old = true;
			}
		}
		if ( $has_old ) {
			$wpdb->query( "ALTER TABLE {$commissions} DROP INDEX order_id" ); // phpcs:ignore WordPress.DB
		}
		if ( ! $has_uniq ) {
			// May fail if duplicate (order_id, source=referral) rows exist from a buggy
			// pre-1.1 install. We swallow the error rather than block the upgrade; admins
			// can resolve duplicates and re-run via WP-CLI: `wp partner-program migrate`.
			$wpdb->hide_errors();
			$wpdb->query( "ALTER TABLE {$commissions} ADD UNIQUE KEY uniq_order_id (order_id)" ); // phpcs:ignore WordPress.DB
			$wpdb->show_errors();
		}

		// Add current_tier_key column to affiliates.
		$col = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$affiliates} LIKE %s", 'current_tier_key' ), ARRAY_A );
		if ( ! $col ) {
			$wpdb->query( "ALTER TABLE {$affiliates} ADD COLUMN current_tier_key VARCHAR(40) NULL AFTER current_tier_id" ); // phpcs:ignore WordPress.DB
		}

		// Backfill stable keys + sort onto stored tier settings, and strip dead UI keys.
		self::cleanup_settings_for_1_1_0();

		// Recompute everyone's tier under the new key-based logic.
		if ( class_exists( \PartnerProgram\Domain\TierResolver::class ) ) {
			\PartnerProgram\Domain\TierResolver::recalculate_all();
		}
	}

	private static function cleanup_settings_for_1_1_0(): void {
		$option = SettingsRepo::OPTION;
		$stored = get_option( $option, [] );
		if ( ! is_array( $stored ) ) {
			return;
		}

		// Backfill tier keys + sort by min ASC for stable ordering.
		if ( isset( $stored['tiers'] ) && is_array( $stored['tiers'] ) ) {
			$used   = [];
			$tiers  = [];
			foreach ( array_values( $stored['tiers'] ) as $i => $t ) {
				if ( ! is_array( $t ) ) {
					continue;
				}
				if ( empty( $t['key'] ) ) {
					$base = sanitize_title( (string) ( $t['label'] ?? '' ) );
					if ( '' === $base ) {
						$base = 'tier-' . ( $i + 1 );
					}
					$key = $base;
					$n   = 2;
					while ( in_array( $key, $used, true ) ) {
						$key = $base . '-' . $n;
						++$n;
					}
					$t['key'] = $key;
				}
				$used[]  = (string) $t['key'];
				$tiers[] = $t;
			}
			usort(
				$tiers,
				static function ( $a, $b ) {
					return (float) ( $a['min'] ?? 0 ) <=> (float) ( $b['min'] ?? 0 );
				}
			);
			$stored['tiers'] = $tiers;
		}

		// Drop dead UI keys (require_id_upload, recaptcha_*, reject_chargeback).
		if ( isset( $stored['application'] ) && is_array( $stored['application'] ) ) {
			unset(
				$stored['application']['require_id_upload'],
				$stored['application']['enable_recaptcha'],
				$stored['application']['recaptcha_site'],
				$stored['application']['recaptcha_secret']
			);
		}
		if ( isset( $stored['exclusions'] ) && is_array( $stored['exclusions'] ) ) {
			unset( $stored['exclusions']['reject_chargeback'] );
		}

		update_option( $option, $stored, false );
		( new SettingsRepo() )->reset_cache();
	}
}
