<?php
/**
 * Apply penalty for a compliance violation.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Compliance;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Support\Logger;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class ViolationManager {

	public static function flag( int $affiliate_id, string $reason ): void {
		global $wpdb;
		$settings = new SettingsRepo();

		if ( (bool) $settings->get( 'compliance.auto_suspend_on_violation', true ) ) {
			AffiliateRepo::update( $affiliate_id, [ 'status' => 'suspended' ] );
			// Mirror the AffiliatesScreen suspend action so listeners
			// (CouponManager deactivation, partner notification email)
			// fire on compliance-driven suspensions too.
			do_action( 'partner_program_affiliate_suspended', $affiliate_id );
		}

		// Forfeit unpaid commissions.
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . CommissionRepo::table() . " SET status = 'rejected', notes = CONCAT(COALESCE(notes,''), ' | violation forfeit'), updated_at = %s WHERE affiliate_id = %d AND status IN ('pending','approved')",
			current_time( 'mysql', true ),
			$affiliate_id
		) );

		// Optional clawback on previously paid commissions in window.
		$days = (int) $settings->get( 'compliance.clawback_days', 90 );
		if ( $days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . CommissionRepo::table() . " SET status = 'clawback', notes = CONCAT(COALESCE(notes,''), ' | clawback'), updated_at = %s WHERE affiliate_id = %d AND status = 'paid' AND created_at >= %s",
				current_time( 'mysql', true ),
				$affiliate_id,
				$cutoff
			) );
		}

		( new Logger() )->log(
			'Compliance violation flagged: ' . $reason,
			'compliance',
			'warning',
			$affiliate_id,
			'affiliate',
			[ 'reason' => $reason ]
		);
		do_action( 'partner_program_violation_flagged', $affiliate_id, $reason );
	}
}
