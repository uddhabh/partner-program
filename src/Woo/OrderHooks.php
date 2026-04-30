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
use PartnerProgram\Support\SettingsRepo;
use PartnerProgram\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class OrderHooks {

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_attribution_meta' ], 10, 2 );
		// Late catch-all: any order that wasn't created via the standard
		// checkout flow (admin-created orders, REST/CLI, payment-gateway
		// callbacks that skip checkout_create_order) gets a second pass
		// once the order is saved. Idempotent — bails if meta is already
		// set by the early hook.
		add_action( 'woocommerce_new_order', [ $this, 'attach_attribution_late' ], 20, 2 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'record_commission' ], 20, 1 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'record_commission' ], 20, 1 );
		add_action( 'woocommerce_order_status_refunded', [ $this, 'reject_on_refund' ], 10, 1 );
		add_action( 'woocommerce_order_refunded', [ $this, 'partial_refund_handler' ], 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'reject_on_status' ], 10, 1 );
		add_action( 'woocommerce_order_status_failed', [ $this, 'reject_on_status' ], 10, 1 );

		// WooCommerce Subscriptions: stamp attribution onto renewal orders
		// from the parent subscription / parent order. Registered
		// unconditionally — gating on `class_exists( 'WC_Subscription' )`
		// here was load-order-dependent and silently dropped the listener
		// because WCS doesn't load until plugins_loaded priority 10 and we
		// boot at priority 5. The hook is never fired without WCS, so an
		// always-on add_action() is free.
		add_action( 'wcs_renewal_order_created', [ $this, 'inherit_subscription_attribution' ], 20, 2 );

		add_filter( 'partner_program_resolve_attribution', [ $this, 'resolve_attribution' ], 10, 2 );
	}

	public function attach_attribution_meta( \WC_Order $order, array $data ): void {
		unset( $data );
		$this->apply_attribution( $order );
	}

	/**
	 * Late attribution pass for orders that didn't pass through
	 * `woocommerce_checkout_create_order` (admin-created, REST, CLI, some
	 * gateway callback paths). Idempotent.
	 *
	 * @param int            $order_id
	 * @param \WC_Order|null $order
	 */
	public function attach_attribution_late( int $order_id, $order = null ): void {
		if ( ! $order || ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $order->get_meta( '_pp_affiliate_id' ) ) {
			return;
		}
		if ( $this->apply_attribution( $order ) ) {
			$order->save();
		}
	}

	/**
	 * Resolve cookie + coupon attribution and stamp the meta keys on the
	 * given order. Returns true if any meta was set so callers know
	 * whether to persist.
	 */
	private function apply_attribution( \WC_Order $order ): bool {
		$changed = false;

		$code = Tracker::current_referral_code();
		if ( $code ) {
			$affiliate = AffiliateRepo::find_by_code( $code );
			if ( $affiliate && 'approved' === $affiliate['status'] ) {
				$order->update_meta_data( '_pp_affiliate_id', (string) $affiliate['id'] );
				$order->update_meta_data( '_pp_referral_code', $code );
				$order->update_meta_data( '_pp_attribution_source', 'referral' );
				$changed = true;
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

			// Only record the coupon as "used for attribution" when it
			// actually lines up with the affiliate getting credit.
			// Otherwise (cookie attributes to A, but B's coupon is on the
			// order) the coupon-bonus rate would land on A even though B
			// issued the coupon.
			$existing_aff = (int) $order->get_meta( '_pp_affiliate_id' );
			if ( ! $existing_aff ) {
				$order->update_meta_data( '_pp_affiliate_id', (string) $aff_id );
				$order->update_meta_data( '_pp_attribution_source', 'coupon' );
				$order->update_meta_data( '_pp_coupon_used', '1' );
				$order->update_meta_data( '_pp_coupon_code', $coupon_code );
				$changed = true;
			} elseif ( $existing_aff === $aff_id ) {
				$order->update_meta_data( '_pp_attribution_source', 'both' );
				$order->update_meta_data( '_pp_coupon_used', '1' );
				$order->update_meta_data( '_pp_coupon_code', $coupon_code );
				$changed = true;
			}
			break;
		}

		return $changed;
	}

	/**
	 * Copy attribution meta from a subscription (or its parent order)
	 * onto each freshly-created renewal order. Required because WCS
	 * doesn't propagate custom meta automatically.
	 *
	 * @param mixed $renewal_order
	 * @param mixed $subscription
	 */
	public function inherit_subscription_attribution( $renewal_order, $subscription ): void {
		if ( ! $renewal_order instanceof \WC_Order || ! is_object( $subscription ) ) {
			return;
		}
		if ( ! (bool) ( new SettingsRepo() )->get( 'attribution.subscription_renewals', true ) ) {
			return;
		}
		if ( $renewal_order->get_meta( '_pp_affiliate_id' ) ) {
			return;
		}

		$parent = null;
		if ( method_exists( $subscription, 'get_parent_id' ) ) {
			$parent_id = (int) $subscription->get_parent_id();
			if ( $parent_id > 0 ) {
				$candidate = wc_get_order( $parent_id );
				if ( $candidate instanceof \WC_Order ) {
					$parent = $candidate;
				}
			}
		}

		// Renewal orders aren't placed via the coupon — only the parent
		// order was. Inheriting `_pp_coupon_used` would cause every
		// renewal forever to receive the coupon-bonus rate. Inherit
		// attribution identity only.
		$keys    = [ '_pp_affiliate_id', '_pp_referral_code', '_pp_attribution_source' ];
		$changed = false;
		foreach ( $keys as $key ) {
			$value = method_exists( $subscription, 'get_meta' ) ? $subscription->get_meta( $key ) : '';
			if ( '' === (string) $value && $parent ) {
				$value = $parent->get_meta( $key );
			}
			if ( '' === (string) $value ) {
				continue;
			}
			// 'both' on the parent meant cookie + coupon agreed; on a
			// renewal there's no coupon, so collapse to plain 'referral'.
			if ( '_pp_attribution_source' === $key && 'both' === (string) $value ) {
				$value = 'referral';
			}
			$renewal_order->update_meta_data( $key, $value );
			$changed = true;
		}
		if ( $changed ) {
			$renewal_order->save();
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
		// Respect the admin's "Adjust commissions on partial refunds" toggle.
		// Without this gate the handler always ran regardless of the setting,
		// making the checkbox a placebo. Default-on so behaviour for sites
		// that never touched the setting is unchanged.
		if ( ! (bool) ( new SettingsRepo() )->get( 'commissions.partial_refund_clawback', true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$commissions = CommissionRepo::for_order( $order_id );
		if ( ! $commissions ) {
			return;
		}
		$total          = (float) $order->get_total();
		$refunded_total = (float) $order->get_total_refunded();
		if ( $refunded_total <= 0 || $total <= 0 ) {
			return;
		}
		$ratio = max( 0.0, min( 1.0, ( $total - $refunded_total ) / $total ) );

		foreach ( $commissions as $row ) {
			if ( in_array( $row['status'], [ 'paid', 'rejected' ], true ) ) {
				continue;
			}
			// Wrap the refund-id in delimiters so `refund_id=10` doesn't
			// false-match against an earlier `refund_id=100` already in
			// notes (substring containment) and silently skip the second
			// adjustment.
			$marker = sprintf( '[refund_id=%d]', $refund_id );
			$prior  = (string) ( $row['notes'] ?? '' );
			if ( '' !== $prior && false !== strpos( $prior, $marker ) ) {
				continue; // Same refund already applied; idempotent on retries.
			}
			// Scale from the immutable original commission amount, not the
			// current (possibly already-reduced) amount_cents — otherwise a
			// second partial refund decays geometrically off the result of
			// the first one.
			$new_amount = (int) round( (int) $row['original_amount_cents'] * $ratio );
			$entry      = sprintf( 'Adjusted for partial refund %s (ratio=%.4f)', $marker, $ratio );
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
