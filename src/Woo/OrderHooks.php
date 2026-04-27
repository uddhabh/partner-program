<?php
/**
 * WooCommerce order hooks: persist attribution on order creation, then trigger
 * commission recording on payment, and update commission status on refunds.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Woo;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class OrderHooks {

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_attribution_meta' ], 10, 2 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'record_commission' ], 20, 1 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'record_commission' ], 20, 1 );
		add_action( 'woocommerce_order_status_refunded', [ $this, 'reject_on_refund' ], 10, 1 );
		add_action( 'woocommerce_order_refunded', [ $this, 'partial_refund_handler' ], 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'reject_on_status' ], 10, 1 );
		add_action( 'woocommerce_order_status_failed', [ $this, 'reject_on_status' ], 10, 1 );

		add_filter( 'partner_program_resolve_attribution', [ $this, 'resolve_attribution' ], 10, 2 );
	}

	public function attach_attribution_meta( \WC_Order $order, array $data ): void {
		$code = Tracker::current_referral_code();
		if ( $code ) {
			$affiliate = AffiliateRepo::find_by_code( $code );
			if ( $affiliate && 'approved' === $affiliate['status'] ) {
				$order->update_meta_data( '_pp_affiliate_id', (string) $affiliate['id'] );
				$order->update_meta_data( '_pp_referral_code', $code );
				$order->update_meta_data( '_pp_attribution_source', 'referral' );
			}
		}

		$used_codes = (array) $order->get_coupon_codes();
		foreach ( $used_codes as $coupon_code ) {
			$aff_id = CouponManager::affiliate_id_for_code( $coupon_code );
			if ( ! $aff_id ) {
				continue;
			}
			$affiliate = AffiliateRepo::find( $aff_id );
			if ( ! $affiliate || 'approved' !== $affiliate['status'] ) {
				continue;
			}
			$order->update_meta_data( '_pp_coupon_used', '1' );
			$order->update_meta_data( '_pp_coupon_code', $coupon_code );

			$existing_aff = (int) $order->get_meta( '_pp_affiliate_id' );
			if ( ! $existing_aff ) {
				$order->update_meta_data( '_pp_affiliate_id', (string) $aff_id );
				$order->update_meta_data( '_pp_attribution_source', 'coupon' );
			} elseif ( $existing_aff === $aff_id ) {
				$order->update_meta_data( '_pp_attribution_source', 'both' );
			}
			break;
		}
	}

	public function record_commission( int $order_id ): void {
		do_action( 'partner_program_record_commission', $order_id );
	}

	public function resolve_attribution( $current, \WC_Order $order ): ?array {
		if ( is_array( $current ) ) {
			return $current;
		}
		$affiliate_id = (int) $order->get_meta( '_pp_affiliate_id' );
		if ( ! $affiliate_id ) {
			return null;
		}
		return [
			'affiliate_id' => $affiliate_id,
			'source'       => (string) ( $order->get_meta( '_pp_attribution_source' ) ?: 'referral' ),
			'coupon_used'  => (bool) $order->get_meta( '_pp_coupon_used' ),
			'coupon_code'  => (string) ( $order->get_meta( '_pp_coupon_code' ) ?: '' ),
		];
	}

	public function reject_on_refund( int $order_id ): void {
		$this->reject_commissions( $order_id, 'rejected', 'order_refunded' );
	}

	public function reject_on_status( int $order_id ): void {
		$this->reject_commissions( $order_id, 'rejected', 'order_status_change' );
	}

	public function partial_refund_handler( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$commissions = CommissionRepo::for_order( $order_id );
		if ( ! $commissions ) {
			return;
		}
		$total           = (float) $order->get_total();
		$refunded_total  = (float) $order->get_total_refunded();
		if ( $refunded_total <= 0 || $total <= 0 ) {
			return;
		}
		$ratio = max( 0.0, min( 1.0, ( $total - $refunded_total ) / $total ) );

		foreach ( $commissions as $row ) {
			if ( in_array( $row['status'], [ 'paid', 'rejected' ], true ) ) {
				continue;
			}
			$marker = sprintf( 'refund_id=%d', $refund_id );
			$prior  = (string) ( $row['notes'] ?? '' );
			if ( '' !== $prior && false !== strpos( $prior, $marker ) ) {
				continue; // Same refund already applied; idempotent on retries.
			}
			$new_amount = (int) round( (int) $row['amount_cents'] * $ratio );
			$entry      = sprintf( 'Adjusted for partial refund (%s, ratio=%.4f)', $marker, $ratio );
			$notes      = '' === $prior ? $entry : trim( $prior ) . "\n" . $entry;
			CommissionRepo::update(
				(int) $row['id'],
				[
					'amount_cents' => $new_amount,
					'notes'        => $notes,
				]
			);
		}
	}

	private function reject_commissions( int $order_id, string $status, string $reason ): void {
		$rows = CommissionRepo::for_order( $order_id );
		foreach ( $rows as $row ) {
			if ( 'paid' === $row['status'] ) {
				continue; // never auto-revert paid; admin can clawback manually.
			}
			CommissionRepo::update(
				(int) $row['id'],
				[
					'status' => $status,
					'notes'  => $reason,
				]
			);
		}
	}
}
