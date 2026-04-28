<?php
/**
 * Settings repository - single JSON blob in wp_options with default fallbacks.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class SettingsRepo {

	public const OPTION = 'partner_program_settings';

	/** @var array<string, mixed>|null */
	private static ?array $cache = null;

	/**
	 * Built-in defaults. Anything not set in wp_options falls through to these.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'general'      => [
				'program_name'   => __( 'Partner Program', 'partner-program' ),
				'logo_url'       => '',
				'support_email'  => get_option( 'admin_email' ),
				'accent_color'   => '#2563eb',
				'terms_url'      => '',
				'login_url'      => '',
			],
			'commissions'  => [
				'base_rate'              => 15.0,
				'calculation_basis'      => 'subtotal_after_discount',
				'exclude_shipping'       => true,
				'exclude_tax'            => true,
				'partial_refund_clawback' => true,
			],
			'tiers'        => [
				[ 'key' => 'bronze', 'min' => 0,     'max' => 4999,  'rate' => 15.0, 'label' => __( 'Bronze', 'partner-program' ) ],
				[ 'key' => 'silver', 'min' => 5000,  'max' => 14999, 'rate' => 18.0, 'label' => __( 'Silver', 'partner-program' ) ],
				[ 'key' => 'gold',   'min' => 15000, 'max' => null,  'rate' => 22.0, 'label' => __( 'Gold',   'partner-program' ) ],
			],
			'coupon_bonus' => [
				'enabled'    => true,
				'bonus_rate' => 2.0,
			],
			'customer_coupon' => [
				'auto_create'    => true,
				'discount_type'  => 'percent',
				'discount_value' => 10.0,
				'prefix'         => 'PARTNER-',
			],
			'tracking'     => [
				'cookie_name'         => 'pp_ref',
				'cookie_lifetime'     => 30,
				'param'               => 'ref',
				'rewrite_slug'        => '',
				'trust_proxy_header'  => false,
			],
			'attribution'  => [
				// Inherit attribution from the parent subscription onto each
				// renewal order. Only takes effect when WooCommerce
				// Subscriptions is active; harmless otherwise.
				'subscription_renewals' => true,
			],
			'hold_payouts' => [
				'hold_days'        => 15,
				'schedule'         => 'monthly',
				'payout_day'       => 1,
				'min_threshold'    => 100.0,
				'enabled_methods'  => [ 'ach', 'paypal', 'zelle', 'cashapp', 'wise' ],
			],
			'application'  => [
				'fields' => self::default_application_fields(),
			],
			'compliance'   => [
				'prohibited_terms' => [
					'human use', 'dosing', 'mixing', 'administration',
					'weight loss', 'fertility', 'bodybuilding results',
					'medical claim', 'therapeutic',
				],
				'penalty_text' => __(
					'Violations result in immediate termination, forfeiture of unpaid commissions, and possible clawback of paid commissions.',
					'partner-program'
				),
				'agreement_body' => self::default_agreement_body(),
				'clawback_days'  => 90,
				'auto_suspend_on_violation' => true,
			],
			'exclusions'   => [
				'reject_refunded'     => true,
				'reject_cancelled'    => true,
				'reject_failed'       => true,
				'fraud_meta_key'      => '_pp_fraud_risk',
				'compliance_meta_key' => '_pp_compliance_violation',
			],
			'logs'         => [
				// Days of history to retain in the pp_logs table. 0 = keep
				// forever; the daily prune cron is a no-op in that case.
				'retention_days' => 90,
			],
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function default_application_fields(): array {
		return [
			[ 'key' => 'full_name', 'label' => __( 'Full name', 'partner-program' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'business_name', 'label' => __( 'Business / brand name', 'partner-program' ), 'type' => 'text', 'required' => false ],
			[ 'key' => 'email', 'label' => __( 'Email', 'partner-program' ), 'type' => 'email', 'required' => true ],
			[ 'key' => 'phone', 'label' => __( 'Phone', 'partner-program' ), 'type' => 'text', 'required' => false ],
			[ 'key' => 'website', 'label' => __( 'Website / social links', 'partner-program' ), 'type' => 'textarea', 'required' => true ],
			[ 'key' => 'audience_type', 'label' => __( 'Audience type', 'partner-program' ), 'type' => 'select', 'required' => true,
				'options' => [
					[ 'value' => 'researchers',     'label' => __( 'Researchers / scientists', 'partner-program' ) ],
					[ 'value' => 'lab',             'label' => __( 'Lab / institution', 'partner-program' ) ],
					[ 'value' => 'content_creator', 'label' => __( 'Content creator', 'partner-program' ) ],
					[ 'value' => 'newsletter',      'label' => __( 'Newsletter / mailing list', 'partner-program' ) ],
					[ 'value' => 'social_media',    'label' => __( 'Social media', 'partner-program' ) ],
					[ 'value' => 'other',           'label' => __( 'Other', 'partner-program' ) ],
				],
			],
			[ 'key' => 'promotion_plan', 'label' => __( 'How will you promote the program?', 'partner-program' ), 'type' => 'textarea', 'required' => true ],
			[ 'key' => 'id_upload', 'label' => __( 'ID / business proof (PDF or image)', 'partner-program' ), 'type' => 'file', 'required' => false ],
			[ 'key' => 'compliance_agreement', 'label' => __( 'I agree to the Research Use Only (RUO) compliance terms.', 'partner-program' ), 'type' => 'checkbox', 'required' => true ],
		];
	}

	private static function default_agreement_body(): string {
		return '<p>' . esc_html__( 'By participating in this Partner Program, you agree to promote products in accordance with all applicable Research Use Only (RUO) regulations and compliance standards.', 'partner-program' ) . '</p>'
			. '<h3>' . esc_html__( 'Prohibited claims', 'partner-program' ) . '</h3>'
			. '<p>' . esc_html__( 'You may not reference human use, dosing, mixing, administration, weight loss, fertility, bodybuilding results, or any medical or therapeutic claims.', 'partner-program' ) . '</p>'
			. '<h3>' . esc_html__( 'Penalty for violation', 'partner-program' ) . '</h3>'
			. '<p>' . esc_html__( 'Violations result in immediate termination, forfeiture of unpaid commissions, and possible clawback of paid commissions.', 'partner-program' ) . '</p>';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, [] );
			self::$cache = self::deep_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
		}
		return self::$cache;
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	public function get( string $path, $default = null ) {
		$parts = explode( '.', $path );
		$value = $this->all();
		foreach ( $parts as $part ) {
			if ( is_array( $value ) && array_key_exists( $part, $value ) ) {
				$value = $value[ $part ];
			} else {
				return $default;
			}
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $values Whole settings tree (sections).
	 */
	public function save_section( string $section, array $values ): void {
		$all              = $this->all();
		$all[ $section ]  = self::deep_merge( $all[ $section ] ?? [], $values );
		self::$cache      = $all;
		update_option( self::OPTION, $all, false );
	}

	public function replace_all( array $values ): void {
		self::$cache = self::deep_merge( self::defaults(), $values );
		update_option( self::OPTION, self::$cache, false );
	}

	/**
	 * Filter an arbitrary array down to the set of known top-level
	 * sections, dropping anything we don't recognise. Used by the import
	 * handler so a malformed or hostile JSON file can't poison the
	 * settings blob with arbitrary keys.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function filter_for_import( array $values ): array {
		$allowed = array_keys( self::defaults() );
		$out     = [];
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $values ) && is_array( $values[ $key ] ) ) {
				$out[ $key ] = $values[ $key ];
			}
		}
		return $out;
	}

	public function ensure_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, self::defaults(), false );
		}
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	private static function deep_merge( array $a, array $b ): array {
		foreach ( $b as $key => $value ) {
			if ( is_array( $value ) && isset( $a[ $key ] ) && is_array( $a[ $key ] ) && self::is_assoc( $a[ $key ] ) && self::is_assoc( $value ) ) {
				$a[ $key ] = self::deep_merge( $a[ $key ], $value );
			} else {
				$a[ $key ] = $value;
			}
		}
		return $a;
	}

	private static function is_assoc( array $arr ): bool {
		if ( [] === $arr ) {
			return true;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
