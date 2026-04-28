<?php
/**
 * Commission engine: turns a paid WooCommerce order into pp_commissions rows.
 *
 * Hooked from \PartnerProgram\Woo\OrderHooks. Subtotal-after-discount calculation,
 * configurable shipping/tax exclusions, +bonus for coupon attribution.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

use PartnerProgram\Core\Plugin;
use PartnerProgram\Support\Logger;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class CommissionEngine {

	public function register(): void {
		add_action( 'partner_program_record_commission', [ $this, 'record_for_order' ], 10, 1 );
	}

	/**
	 * Record commission(s) for an order ID. Idempotent.
	 */
	public function record_for_order( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Fast-path: avoid the calculation work when we already recorded
		// for this order. Correctness no longer depends on this check —
		// the INSERT below is guarded by UNIQUE KEY (order_id) — but
		// skipping the work is still nice when payment hooks fire twice.
		$existing = CommissionRepo::for_order( $order_id );
		if ( $existing ) {
			return;
		}

		$attribution = apply_filters( 'partner_program_resolve_attribution', null, $order );
		if ( ! $attribution || empty( $attribution['affiliate_id'] ) ) {
			return;
		}

		$settings = new SettingsRepo();
		if ( ! $this->should_pay_for_status( $order, $settings ) ) {
			return;
		}

		$base_cents = $this->calculate_base_cents( $order, $settings );
		if ( $base_cents <= 0 ) {
			return;
		}

		$affiliate_id = (int) $attribution['affiliate_id'];
		$affiliate    = AffiliateRepo::find( $affiliate_id );
		if ( ! $affiliate || 'approved' !== $affiliate['status'] ) {
			return;
		}

		$rate = $this->resolve_rate( $affiliate, $settings );

		$source        = $attribution['source'] ?? 'referral';
		$coupon_used   = ! empty( $attribution['coupon_used'] );
		$coupon_code   = $attribution['coupon_code'] ?? null;
		$coupon_bonus  = 0.0;
		if ( $coupon_used && (bool) $settings->get( 'coupon_bonus.enabled' ) ) {
			$coupon_bonus = (float) $settings->get( 'coupon_bonus.bonus_rate', 0 );
		}

		$effective_rate = $rate + $coupon_bonus;
		$effective_rate = (float) apply_filters( 'partner_program_calculate_commission_rate', $effective_rate, $affiliate, $order, $attribution );

		$amount_cents = (int) round( $base_cents * ( $effective_rate / 100 ) );
		$amount_cents = (int) apply_filters( 'partner_program_calculate_commission_amount', $amount_cents, $base_cents, $effective_rate, $order, $affiliate );

		$hold_days = (int) $settings->get( 'hold_payouts.hold_days', 15 );
		$paid_at   = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time();
		$release   = gmdate( 'Y-m-d H:i:s', $paid_at + ( $hold_days * DAY_IN_SECONDS ) );

		$result = CommissionRepo::create_for_order(
			[
				'affiliate_id'          => $affiliate_id,
				'order_id'              => $order_id,
				'base_amount_cents'     => $base_cents,
				'rate'                  => $effective_rate,
				'amount_cents'          => $amount_cents,
				'original_amount_cents' => $amount_cents,
				'currency'              => $order->get_currency(),
				'status'                => 'pending',
				'source'                => $source,
				'coupon_used'           => $coupon_used ? 1 : 0,
				'coupon_code'           => $coupon_code,
				'hold_release_at'       => $release,
				'notes'                 => null,
			]
		);

		// Concurrent payment hook lost the UNIQUE-key race — bail without
		// firing the recorded action again. Listeners (notifications,
		// analytics, etc.) get exactly one event per order.
		if ( ! $result['created'] ) {
			return;
		}

		$commission_id = $result['id'];
		do_action( 'partner_program_commission_recorded', $commission_id, $affiliate_id, $order_id );

		$logger = Plugin::instance()->get( 'logger' );
		if ( $logger instanceof Logger ) {
			$logger->info(
				sprintf( 'Commission #%d recorded for order #%d', $commission_id, $order_id ),
				'commissions',
				[
					'affiliate_id'   => $affiliate_id,
					'amount_cents'   => $amount_cents,
					'rate'           => $effective_rate,
					'source'         => $source,
				]
			);
		}
	}

	private function should_pay_for_status( \WC_Order $order, SettingsRepo $settings ): bool {
		$status = $order->get_status();
		if ( in_array( $status, [ 'refunded' ], true ) && (bool) $settings->get( 'exclusions.reject_refunded', true ) ) {
			return false;
		}
		if ( 'cancelled' === $status && (bool) $settings->get( 'exclusions.reject_cancelled', true ) ) {
			return false;
		}
		if ( 'failed' === $status && (bool) $settings->get( 'exclusions.reject_failed', true ) ) {
			return false;
		}

		$fraud_meta_key = (string) $settings->get( 'exclusions.fraud_meta_key', '_pp_fraud_risk' );
		if ( $fraud_meta_key && $order->get_meta( $fraud_meta_key ) ) {
			return false;
		}
		$compliance_meta_key = (string) $settings->get( 'exclusions.compliance_meta_key', '_pp_compliance_violation' );
		if ( $compliance_meta_key && $order->get_meta( $compliance_meta_key ) ) {
			return false;
		}

		return (bool) apply_filters( 'partner_program_should_pay_commission', true, $order );
	}

	private function calculate_base_cents( \WC_Order $order, SettingsRepo $settings ): int {
		$basis            = (string) $settings->get( 'commissions.calculation_basis', 'subtotal_after_discount' );
		$exclude_shipping = (bool) $settings->get( 'commissions.exclude_shipping', true );
		$exclude_tax      = (bool) $settings->get( 'commissions.exclude_tax', true );

		$subtotal = (float) $order->get_subtotal();
		$discount = (float) $order->get_total_discount();
		$shipping = (float) $order->get_shipping_total();
		$tax      = (float) $order->get_total_tax();

		switch ( $basis ) {
			case 'subtotal':
				$base = $subtotal;
				break;
			case 'order_total':
				$base = (float) $order->get_total();
				if ( $exclude_shipping ) {
					$base -= $shipping;
				}
				if ( $exclude_tax ) {
					$base -= $tax;
				}
				break;
			case 'subtotal_after_discount':
			default:
				$base = max( 0, $subtotal - $discount );
				break;
		}

		$base = (float) apply_filters( 'partner_program_commission_base', $base, $order, $basis );
		return Money::to_cents( max( 0.0, $base ) );
	}

	/**
	 * Pick the rate for this affiliate, applying overrides then current tier then base.
	 */
	private function resolve_rate( array $affiliate, SettingsRepo $settings ): float {
		if ( ! empty( $affiliate['default_commission_rate'] ) ) {
			return (float) $affiliate['default_commission_rate'];
		}

		$tier_key = isset( $affiliate['current_tier_key'] ) ? (string) $affiliate['current_tier_key'] : '';
		if ( '' !== $tier_key ) {
			$tier = TierResolver::tier_for_key( $tier_key );
			if ( $tier && isset( $tier['rate'] ) ) {
				return (float) $tier['rate'];
			}
		}

		return (float) $settings->get( 'commissions.base_rate', 15 );
	}
}
