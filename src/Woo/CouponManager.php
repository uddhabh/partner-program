<?php
/**
 * Auto-create a Woo coupon per approved affiliate so coupon attribution and
 * customer discount live in one place.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Woo;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class CouponManager {

	private const META_DEACTIVATED_REASON = '_pp_deactivated_reason';

	public function register(): void {
		add_action( 'partner_program_affiliate_approved', [ $this, 'ensure_coupon_for_affiliate' ], 10, 1 );
		// Restore runs at lower priority so it sees the coupon row that
		// `ensure_coupon_for_affiliate` may have just created or located.
		add_action( 'partner_program_affiliate_approved', [ $this, 'restore_coupon_for_affiliate' ], 20, 1 );
		add_action( 'partner_program_affiliate_suspended', [ $this, 'deactivate_coupon_for_affiliate' ], 10, 1 );
	}

	public function ensure_coupon_for_affiliate( int $affiliate_id ): ?int {
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return null;
		}

		$affiliate = AffiliateRepo::find( $affiliate_id );
		if ( ! $affiliate ) {
			return null;
		}
		if ( ! empty( $affiliate['coupon_id'] ) ) {
			return (int) $affiliate['coupon_id'];
		}

		$settings = new SettingsRepo();
		if ( ! (bool) $settings->get( 'customer_coupon.auto_create', true ) ) {
			return null;
		}

		$prefix = (string) $settings->get( 'customer_coupon.prefix', 'PARTNER-' );
		$code   = strtoupper( $prefix . $affiliate['referral_code'] );

		$existing_id = wc_get_coupon_id_by_code( $code );
		if ( $existing_id ) {
			AffiliateRepo::update( $affiliate_id, [ 'coupon_id' => (int) $existing_id ] );
			return (int) $existing_id;
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( (string) $settings->get( 'customer_coupon.discount_type', 'percent' ) );
		$coupon->set_amount( (float) $settings->get( 'customer_coupon.discount_value', 10 ) );
		$coupon->set_individual_use( false );
		$coupon->set_usage_limit( 0 );
		$coupon->set_description( sprintf( __( 'Auto-generated coupon for partner %s', 'partner-program' ), $affiliate['referral_code'] ) );
		$coupon->update_meta_data( '_pp_affiliate_id', (string) $affiliate_id );
		$coupon_id = $coupon->save();

		if ( $coupon_id ) {
			AffiliateRepo::update( $affiliate_id, [ 'coupon_id' => (int) $coupon_id ] );
		}
		return $coupon_id ? (int) $coupon_id : null;
	}

	/**
	 * Expire the auto-generated coupon for an affiliate so customers can no
	 * longer redeem it (no more silent revenue leak: discount applied,
	 * commission rejected because affiliate is non-approved). We set
	 * `date_expires` to yesterday rather than deleting so historical
	 * orders' coupon attribution stays intact.
	 */
	public function deactivate_coupon_for_affiliate( int $affiliate_id ): void {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return;
		}
		$aff = AffiliateRepo::find( $affiliate_id );
		if ( ! $aff || empty( $aff['coupon_id'] ) ) {
			return;
		}
		$coupon = new \WC_Coupon( (int) $aff['coupon_id'] );
		if ( ! $coupon->get_id() ) {
			return;
		}
		// Reflect the affiliate's *new* status so we can decide later
		// whether re-approval should auto-restore (suspended yes, rejected
		// no — that's an explicit revoke).
		$reason = 'rejected' === (string) $aff['status'] ? 'rejected' : 'suspended';
		$coupon->set_date_expires( time() - DAY_IN_SECONDS );
		$coupon->update_meta_data( self::META_DEACTIVATED_REASON, $reason );
		$coupon->save();
	}

	/**
	 * Re-enable a coupon that was deactivated for a suspended partner.
	 * Rejected partners do NOT auto-restore: re-approval after a hard
	 * reject is rare and we'd rather make admins flip the expiry by hand.
	 */
	public function restore_coupon_for_affiliate( int $affiliate_id ): void {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return;
		}
		$aff = AffiliateRepo::find( $affiliate_id );
		if ( ! $aff || empty( $aff['coupon_id'] ) ) {
			return;
		}
		$coupon = new \WC_Coupon( (int) $aff['coupon_id'] );
		if ( ! $coupon->get_id() ) {
			return;
		}
		$reason = (string) $coupon->get_meta( self::META_DEACTIVATED_REASON );
		if ( 'suspended' !== $reason ) {
			return;
		}
		$coupon->set_date_expires( null );
		$coupon->delete_meta_data( self::META_DEACTIVATED_REASON );
		$coupon->save();
	}

	public static function affiliate_id_for_code( string $code ): ?int {
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return null;
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( ! $id ) {
			return null;
		}
		$meta = get_post_meta( $id, '_pp_affiliate_id', true );
		return $meta ? (int) $meta : null;
	}
}
