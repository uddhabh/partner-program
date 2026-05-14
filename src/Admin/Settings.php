<?php
/**
 * Settings screen with tabbed sections; saves into pp_settings option via SettingsRepo.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Emails\EventRegistry;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const NONCE = 'partner_program_save_settings';

	public function register(): void {
		add_action( 'admin_post_partner_program_save_settings', [ $this, 'handle_save' ] );
		add_action( 'admin_post_partner_program_export_settings', [ $this, 'handle_export' ] );
		add_action( 'admin_post_partner_program_import_settings', [ $this, 'handle_import' ] );
	}

	public static function render_page(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		$settings = new SettingsRepo();
		$tabs     = [
			'general'      => __( 'General / Branding', 'partner-program' ),
			'commissions'  => __( 'Commissions', 'partner-program' ),
			'tiers'        => __( 'Tiers', 'partner-program' ),
			'coupon_bonus' => __( 'Coupon Bonus', 'partner-program' ),
			'customer_coupon' => __( 'Customer Coupon', 'partner-program' ),
			'tracking'     => __( 'Tracking', 'partner-program' ),
			'hold_payouts' => __( 'Hold & Payouts', 'partner-program' ),
			'application'  => __( 'Application Form', 'partner-program' ),
			'compliance'   => __( 'Compliance', 'partner-program' ),
			'exclusions'   => __( 'Exclusions', 'partner-program' ),
			'emails'       => __( 'Emails', 'partner-program' ),
			'logs'         => __( 'Logs', 'partner-program' ),
			'iotools'      => __( 'Import / Export', 'partner-program' ),
		];
		$active = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'general';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Partner Program Settings', 'partner-program' ) . '</h1>';

		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = 'iotools' === $active
				? __( 'Settings imported.', 'partner-program' )
				: __( 'Settings saved.', 'partner-program' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
		if ( 'iotools' === $active && isset( $_GET['import_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Import failed. Upload a valid Partner Program JSON export (max 1 MB).', 'partner-program' ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$class = 'nav-tab' . ( $active === $key ? ' nav-tab-active' : '' );
			$url   = add_query_arg(
				[ 'page' => 'partner-program-settings', 'tab' => $key ],
				admin_url( 'admin.php' )
			);
			printf( '<a class="%s" href="%s">%s</a>', esc_attr( $class ), esc_url( $url ), esc_html( $label ) );
		}
		echo '</h2>';

		if ( 'iotools' === $active ) {
			self::render_iotools();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="partner_program_save_settings" />';
		echo '<input type="hidden" name="section" value="' . esc_attr( $active ) . '" />';
		wp_nonce_field( self::NONCE );

		switch ( $active ) {
			case 'general':       self::tab_general( $settings ); break;
			case 'commissions':   self::tab_commissions( $settings ); break;
			case 'tiers':         self::tab_tiers( $settings ); break;
			case 'coupon_bonus':  self::tab_coupon_bonus( $settings ); break;
			case 'customer_coupon': self::tab_customer_coupon( $settings ); break;
			case 'tracking':      self::tab_tracking( $settings ); break;
			case 'hold_payouts':  self::tab_hold_payouts( $settings ); break;
			case 'application':   self::tab_application( $settings ); break;
			case 'compliance':    self::tab_compliance( $settings ); break;
			case 'exclusions':    self::tab_exclusions( $settings ); break;
			case 'emails':        self::tab_emails( $settings ); break;
			case 'logs':          self::tab_logs( $settings ); break;
		}

		submit_button();
		echo '</form></div>';
	}

	private static function field_text( string $name, string $label, string $value, string $description = '', string $type = 'text' ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%5$s" id="%1$s" name="%1$s" value="%3$s" class="regular-text" />%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			$description ? '<p class="description">' . esc_html( $description ) . '</p>' : '',
			esc_attr( $type )
		);
	}

	private static function field_checkbox( string $name, string $label, bool $checked, string $description = '' ): void {
		printf(
			'<tr><th scope="row">%2$s</th><td><label><input type="checkbox" name="%1$s" value="1" %3$s /> %4$s</label></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			$checked ? 'checked' : '',
			esc_html( $description )
		);
	}

	private static function field_select( string $name, string $label, string $value, array $options, string $description = '' ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $opt_label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $key ), selected( $value, (string) $key, false ), esc_html( (string) $opt_label ) );
		}
		echo '</select>';
		if ( $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function tab_general( SettingsRepo $s ): void {
		echo '<table class="form-table">';
		self::field_text( 'program_name', __( 'Program name', 'partner-program' ), (string) $s->get( 'general.program_name' ) );
		self::field_text( 'logo_url', __( 'Logo URL', 'partner-program' ), (string) $s->get( 'general.logo_url' ), '', 'url' );
		self::field_text( 'support_email', __( 'Support email', 'partner-program' ), (string) $s->get( 'general.support_email' ), '', 'email' );
		self::field_text( 'accent_color', __( 'Accent color', 'partner-program' ), (string) $s->get( 'general.accent_color' ) );
		self::field_text( 'terms_url', __( 'Terms URL', 'partner-program' ), (string) $s->get( 'general.terms_url' ), '', 'url' );
		self::field_text( 'login_url', __( 'Login URL override', 'partner-program' ), (string) $s->get( 'general.login_url' ), __( 'Optional', 'partner-program' ), 'url' );
		echo '</table>';
	}

	private static function tab_commissions( SettingsRepo $s ): void {
		echo '<table class="form-table">';
		self::field_text( 'base_rate', __( 'Base commission rate %', 'partner-program' ), (string) $s->get( 'commissions.base_rate' ), '', 'number' );
		self::field_select(
			'calculation_basis',
			__( 'Calculation basis', 'partner-program' ),
			(string) $s->get( 'commissions.calculation_basis' ),
			[
				'subtotal_after_discount' => __( 'Subtotal after discount (recommended)', 'partner-program' ),
				'subtotal'                => __( 'Subtotal', 'partner-program' ),
				'order_total'             => __( 'Order total', 'partner-program' ),
			]
		);
		self::field_checkbox( 'exclude_shipping', __( 'Exclude shipping', 'partner-program' ), (bool) $s->get( 'commissions.exclude_shipping' ) );
		self::field_checkbox( 'exclude_tax', __( 'Exclude tax', 'partner-program' ), (bool) $s->get( 'commissions.exclude_tax' ) );
		self::field_checkbox( 'partial_refund_clawback', __( 'Adjust commissions on partial refunds', 'partner-program' ), (bool) $s->get( 'commissions.partial_refund_clawback' ) );
		echo '</table>';
	}

	private static function tab_tiers( SettingsRepo $s ): void {
		$tiers = (array) $s->get( 'tiers', [] );
		echo '<p>' . esc_html__( 'Tier ranges are matched against the affiliate\'s prior calendar month sales (gross attributed). The matched rate applies to the next month\'s commissions.', 'partner-program' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Key', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Label', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Min ($)', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Max ($)', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Rate %', 'partner-program' ) . '</th>'
			. '</tr></thead><tbody>';
		$tiers[] = [ 'key' => '', 'label' => '', 'min' => '', 'max' => '', 'rate' => '' ];
		foreach ( $tiers as $i => $t ) {
			printf(
				'<tr><td><input type="text" name="tiers[%1$d][key]" value="%2$s" placeholder="auto" /></td>'
				. '<td><input type="text" name="tiers[%1$d][label]" value="%3$s" /></td>'
				. '<td><input type="number" step="0.01" name="tiers[%1$d][min]" value="%4$s" /></td>'
				. '<td><input type="number" step="0.01" name="tiers[%1$d][max]" value="%5$s" /></td>'
				. '<td><input type="number" step="0.01" name="tiers[%1$d][rate]" value="%6$s" /></td></tr>',
				(int) $i,
				esc_attr( (string) ( $t['key'] ?? '' ) ),
				esc_attr( (string) ( $t['label'] ?? '' ) ),
				esc_attr( (string) ( $t['min'] ?? '' ) ),
				esc_attr( (string) ( $t['max'] ?? '' ) ),
				esc_attr( (string) ( $t['rate'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Key is the stable identifier for an affiliate\'s assigned tier; leave blank to auto-generate from the label. Leave the last row empty if you do not need it. Tiers are sorted by Min on save and matching is "highest tier whose Min ≤ sales", so Max is informational only — set it as a guideline for admins, not as a runtime gate.', 'partner-program' ) . '</p>';
	}

	private static function tab_coupon_bonus( SettingsRepo $s ): void {
		echo '<table class="form-table">';
		self::field_checkbox( 'enabled', __( 'Enable +bonus when an attributed coupon is used', 'partner-program' ), (bool) $s->get( 'coupon_bonus.enabled' ) );
		self::field_text( 'bonus_rate', __( 'Bonus rate %', 'partner-program' ), (string) $s->get( 'coupon_bonus.bonus_rate' ), '', 'number' );
		echo '</table>';
	}

	private static function tab_customer_coupon( SettingsRepo $s ): void {
		echo '<table class="form-table">';
		self::field_checkbox( 'auto_create', __( 'Auto-create a Woo coupon on affiliate approval', 'partner-program' ), (bool) $s->get( 'customer_coupon.auto_create' ) );
		self::field_select(
			'discount_type',
			__( 'Discount type', 'partner-program' ),
			(string) $s->get( 'customer_coupon.discount_type' ),
			[
				'percent'       => __( 'Percent', 'partner-program' ),
				'fixed_cart'    => __( 'Fixed cart', 'partner-program' ),
				'fixed_product' => __( 'Fixed product', 'partner-program' ),
			]
		);
		self::field_text( 'discount_value', __( 'Discount value', 'partner-program' ), (string) $s->get( 'customer_coupon.discount_value' ), '', 'number' );
		self::field_text( 'prefix', __( 'Coupon code prefix', 'partner-program' ), (string) $s->get( 'customer_coupon.prefix' ) );
		echo '</table>';
	}

	private static function tab_tracking( SettingsRepo $s ): void {
		echo '<table class="form-table">';
		self::field_text( 'cookie_name', __( 'Cookie name', 'partner-program' ), (string) $s->get( 'tracking.cookie_name' ) );
		self::field_text( 'cookie_lifetime', __( 'Cookie lifetime (days)', 'partner-program' ), (string) $s->get( 'tracking.cookie_lifetime' ), '', 'number' );
		self::field_text( 'param', __( 'URL parameter', 'partner-program' ), (string) $s->get( 'tracking.param' ) );
		self::field_text( 'rewrite_slug', __( 'Pretty rewrite slug (optional)', 'partner-program' ), (string) $s->get( 'tracking.rewrite_slug' ) );
		self::field_checkbox(
			'trust_proxy_header',
			__( 'Trust proxy headers for visitor IP', 'partner-program' ),
			(bool) $s->get( 'tracking.trust_proxy_header', false ),
			__( 'Read CF-Connecting-IP / X-Forwarded-For when resolving the client IP. Only enable if your site is behind a trusted proxy or CDN; otherwise visitors can spoof their IP.', 'partner-program' )
		);
		self::field_checkbox(
			'attribution_subscription_renewals',
			__( 'Inherit attribution onto subscription renewals', 'partner-program' ),
			(bool) $s->get( 'attribution.subscription_renewals', true ),
			__( 'Only applies when WooCommerce Subscriptions is active.', 'partner-program' )
		);
		echo '</table>';
	}

	private static function tab_emails( SettingsRepo $s ): void {
		echo '<p class="description">' . esc_html__( 'Customize transactional emails sent by the plugin. Leave subject or body blank to use the built-in default. Tokens like {program_name} are replaced at send time — see each event\'s help text for available tokens.', 'partner-program' ) . '</p>';

		echo '<h2>' . esc_html__( 'Sender', 'partner-program' ) . '</h2>';
		echo '<table class="form-table">';
		self::field_text(
			'emails_from_name',
			__( 'From name', 'partner-program' ),
			(string) $s->get( 'emails.from_name', '' ),
			__( 'Defaults to the program name.', 'partner-program' )
		);
		self::field_text(
			'emails_from_email',
			__( 'From email', 'partner-program' ),
			(string) $s->get( 'emails.from_email', '' ),
			__( 'Defaults to the support email.', 'partner-program' ),
			'email'
		);
		printf(
			'<tr><th scope="row"><label for="emails_footer_text">%s</label></th><td><textarea id="emails_footer_text" name="emails_footer_text" rows="2" cols="60">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Footer text', 'partner-program' ),
			esc_textarea( (string) $s->get( 'emails.footer_text', '' ) ),
			esc_html__( 'Shown at the bottom of every email. Leave blank for the default.', 'partner-program' )
		);
		echo '</table>';

		echo '<h2>' . esc_html__( 'Events', 'partner-program' ) . '</h2>';

		foreach ( EventRegistry::all() as $key => $event ) {
			$config  = (array) $s->get( 'emails.events.' . $key, [] );
			$enabled = array_key_exists( 'enabled', $config ) ? (bool) $config['enabled'] : (bool) $event['default_enabled'];
			$subject = (string) ( $config['subject'] ?? '' );
			$body    = (string) ( $config['body'] ?? '' );

			$tokens_html = '';
			foreach ( $event['tokens'] as $token => $token_desc ) {
				$tokens_html .= sprintf(
					'<li><code>%s</code> — %s</li>',
					esc_html( $token ),
					esc_html( $token_desc )
				);
			}

			$audience_label = 'admin' === $event['audience']
				? __( 'Sent to admin', 'partner-program' )
				: __( 'Sent to partner', 'partner-program' );

			?>
			<details class="pp-email-event" <?php echo $enabled ? 'open' : ''; ?> style="border:1px solid #c3c4c7;background:#fff;padding:8px 16px;margin:0 0 12px;">
				<summary style="cursor:pointer;font-weight:600;padding:6px 0;">
					<?php echo esc_html( $event['label'] ); ?>
					<span style="font-weight:400;color:#646970;margin-left:8px;">— <?php echo esc_html( $audience_label ); ?></span>
				</summary>
				<p class="description" style="margin-top:4px;"><?php echo esc_html( $event['description'] ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled', 'partner-program' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="emails_events[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Send this email', 'partner-program' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="emails_subject_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Subject', 'partner-program' ); ?></label></th>
						<td>
							<input type="text" id="emails_subject_<?php echo esc_attr( $key ); ?>" name="emails_events[<?php echo esc_attr( $key ); ?>][subject]" value="<?php echo esc_attr( $subject ); ?>" class="large-text" placeholder="<?php echo esc_attr( $event['subject'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to use the default shown as placeholder.', 'partner-program' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="emails_body_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Body', 'partner-program' ); ?></label></th>
						<td>
							<textarea id="emails_body_<?php echo esc_attr( $key ); ?>" name="emails_events[<?php echo esc_attr( $key ); ?>][body]" rows="8" class="large-text code" placeholder="<?php echo esc_attr( $event['body'] ); ?>"><?php echo esc_textarea( $body ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Plain text or basic HTML. Leave blank to use the default.', 'partner-program' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Available tokens', 'partner-program' ); ?></th>
						<td>
							<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;"><?php echo $tokens_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></ul>
						</td>
					</tr>
				</table>
			</details>
			<?php
		}
	}

	private static function tab_logs( SettingsRepo $s ): void {
		echo '<table class="form-table">';
		self::field_text(
			'logs_retention_days',
			__( 'Retention (days)', 'partner-program' ),
			(string) $s->get( 'logs.retention_days', 90 ),
			__( 'Audit-log rows older than this are pruned daily. Set to 0 to keep everything.', 'partner-program' ),
			'number'
		);
		echo '</table>';
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=partner-program-logs' ) ),
			esc_html__( 'View logs and prune now →', 'partner-program' )
		);
	}

	private static function tab_hold_payouts( SettingsRepo $s ): void {
		echo '<table class="form-table">';
		self::field_text( 'hold_days', __( 'Hold period (days)', 'partner-program' ), (string) $s->get( 'hold_payouts.hold_days' ), '', 'number' );
		self::field_select(
			'schedule',
			__( 'Schedule', 'partner-program' ),
			(string) $s->get( 'hold_payouts.schedule' ),
			[
				'monthly' => __( 'Monthly', 'partner-program' ),
				'weekly'  => __( 'Weekly', 'partner-program' ),
				'manual'  => __( 'Manual', 'partner-program' ),
			]
		);
		self::field_text( 'payout_day', __( 'Payout day-of-month (1-28)', 'partner-program' ), (string) $s->get( 'hold_payouts.payout_day' ), '', 'number' );
		self::field_text( 'min_threshold', __( 'Minimum payout threshold', 'partner-program' ), (string) $s->get( 'hold_payouts.min_threshold' ), '', 'number' );

		$enabled = (array) $s->get( 'hold_payouts.enabled_methods', [] );
		echo '<tr><th scope="row">' . esc_html__( 'Enabled payout methods', 'partner-program' ) . '</th><td>';
		echo '<fieldset class="pp-checkbox-group">';
		foreach ( [ 'ach' => 'ACH', 'paypal' => 'PayPal', 'zelle' => 'Zelle', 'cashapp' => 'CashApp', 'wise' => 'Wise', 'check' => 'Check' ] as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="enabled_methods[]" value="%s" %s /> %s</label>',
				esc_attr( $key ),
				in_array( $key, $enabled, true ) ? 'checked' : '',
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '</td></tr>';
		echo '</table>';
	}

	private static function tab_application( SettingsRepo $s ): void {
		echo '<p class="description">' . esc_html__( 'Edit the public application form below. Reorder rows with the ▲▼ buttons, add or remove rows with the buttons in each row, and toggle the Required column to mark a field mandatory. For Select fields, use the inline editor in the Options column to manage choices.', 'partner-program' ) . '</p>';

		echo '<h2>' . esc_html__( 'Form fields', 'partner-program' ) . '</h2>';
		echo '<table class="widefat striped pp-fields-table"><thead><tr>'
			. '<th class="pp-col-handle" scope="col"><span class="screen-reader-text">' . esc_html__( 'Reorder', 'partner-program' ) . '</span></th>'
			. '<th scope="col">' . esc_html__( 'Key', 'partner-program' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Label', 'partner-program' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Type', 'partner-program' ) . '</th>'
			. '<th class="pp-col-required" scope="col">' . esc_html__( 'Required', 'partner-program' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Options (select / checkboxes)', 'partner-program' ) . '</th>'
			. '<th class="pp-col-actions" scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'partner-program' ) . '</span></th>'
			. '</tr></thead><tbody>';
		$fields = (array) $s->get( 'application.fields', [] );
		foreach ( $fields as $i => $f ) {
			echo self::render_field_row( (array) $f, (string) $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';

		printf(
			'<p><button type="button" class="button pp-field-add">%s</button></p>',
			esc_html__( '+ Add field', 'partner-program' )
		);

		// Templates the JS clones when adding a new field row or option row.
		// Placeholders: __I__ = field index, __J__ = option index. Both are
		// replaced with random strings on insert so they can't collide with
		// existing numeric indices coming from the server-rendered rows.
		echo '<template id="pp-field-row-template">' . self::render_field_row( [], '__I__' ) . '</template>';
		echo '<template id="pp-option-row-template">' . self::render_option_row( [], '__I__', '__J__' ) . '</template>';

		self::print_application_styles_and_script();
	}

	/**
	 * Render one field row of the Application Form admin table.
	 *
	 * `$idx` is interpolated into every input name so the row submits as
	 * `fields[$idx][...]`. We pass it as a string (not int) so the row can
	 * also be used as a JS clone template with placeholder indices.
	 *
	 * @param array<string, mixed> $f
	 */
	private static function render_field_row( array $f, string $idx ): string {
		$type     = (string) ( $f['type'] ?? 'text' );
		$key      = (string) ( $f['key'] ?? '' );
		$label    = (string) ( $f['label'] ?? '' );
		$required = ! empty( $f['required'] );
		$options  = (array) ( $f['options'] ?? [] );

		$option_rows = '';
		foreach ( $options as $j => $opt ) {
			$option_rows .= self::render_option_row( is_array( $opt ) ? $opt : [ 'value' => (string) $opt ], $idx, (string) $j );
		}

		ob_start();
		?>
<tr class="pp-field-row" data-type="<?php echo esc_attr( $type ); ?>">
	<td class="pp-col-handle">
		<button type="button" class="button-link pp-move-up" aria-label="<?php esc_attr_e( 'Move up', 'partner-program' ); ?>">▲</button>
		<button type="button" class="button-link pp-move-down" aria-label="<?php esc_attr_e( 'Move down', 'partner-program' ); ?>">▼</button>
	</td>
	<td><input type="text" name="fields[<?php echo esc_attr( $idx ); ?>][key]" value="<?php echo esc_attr( $key ); ?>" placeholder="<?php esc_attr_e( 'unique_key', 'partner-program' ); ?>" /></td>
	<td><input type="text" name="fields[<?php echo esc_attr( $idx ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Label shown to applicants', 'partner-program' ); ?>" /></td>
	<td><?php echo self::type_select( $idx, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
	<td class="pp-col-required"><input type="checkbox" name="fields[<?php echo esc_attr( $idx ); ?>][required]" value="1" <?php checked( $required ); ?> /></td>
	<td class="pp-field-options-cell">
		<table class="pp-options-table"><tbody>
			<?php echo $option_rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</tbody></table>
		<p>
			<button type="button" class="button button-small pp-option-add">+ <?php esc_html_e( 'Add option', 'partner-program' ); ?></button>
		</p>
	</td>
	<td class="pp-col-actions">
		<button type="button" class="button-link delete pp-field-remove" aria-label="<?php esc_attr_e( 'Delete field', 'partner-program' ); ?>"><?php esc_html_e( 'Delete', 'partner-program' ); ?></button>
	</td>
</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one option sub-row inside a select field's Options cell.
	 * Submits as `fields[$field_idx][options][$opt_idx][value|label]`.
	 *
	 * @param array<string, mixed> $opt
	 */
	private static function render_option_row( array $opt, string $field_idx, string $opt_idx ): string {
		$value = (string) ( $opt['value'] ?? '' );
		$label = (string) ( $opt['label'] ?? '' );
		ob_start();
		?>
<tr class="pp-option-row">
	<td><input type="text" name="fields[<?php echo esc_attr( $field_idx ); ?>][options][<?php echo esc_attr( $opt_idx ); ?>][value]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php esc_attr_e( 'value', 'partner-program' ); ?>" /></td>
	<td><input type="text" name="fields[<?php echo esc_attr( $field_idx ); ?>][options][<?php echo esc_attr( $opt_idx ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Label', 'partner-program' ); ?>" /></td>
	<td><button type="button" class="button-link pp-option-remove" aria-label="<?php esc_attr_e( 'Remove option', 'partner-program' ); ?>">×</button></td>
</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Normalize the raw options array submitted from the per-row option
	 * editor into the canonical `[ ['value'=>..., 'label'=>...], ... ]`
	 * shape consumed by `templates/application/form.php`.
	 *
	 * - Skips entirely empty rows (the user clicked Add option then never
	 *   filled either field).
	 * - If only one of value/label is filled, the other is derived from it.
	 * - Reindexes numerically so JSON storage stays a list, not an object.
	 *
	 * @param array<int|string, mixed> $rows Raw `$_POST['fields'][i]['options']` value.
	 * @return array<int, array{value:string,label:string}>
	 */
	private static function sanitize_options_input( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$value = sanitize_key( (string) ( $row['value'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			if ( '' === $value && '' === $label ) {
				continue;
			}
			if ( '' === $value ) { $value = sanitize_key( $label ); }
			if ( '' === $label ) { $label = $value; }
			if ( '' === $value ) {
				continue;
			}
			$out[] = [ 'value' => $value, 'label' => $label ];
		}
		return $out;
	}

	private static function type_select( string $idx, string $current ): string {
		$opts = [ 'text', 'email', 'textarea', 'select', 'checkbox', 'checkboxes', 'file' ];
		$out  = '<select name="fields[' . esc_attr( $idx ) . '][type]">';
		foreach ( $opts as $t ) {
			$out .= '<option value="' . esc_attr( $t ) . '" ' . selected( $current, $t, false ) . '>' . esc_html( $t ) . '</option>';
		}
		$out .= '</select>';
		return $out;
	}

	/**
	 * Inline CSS and JS that powers the Application Form fields editor.
	 *
	 * Kept inline (no separate enqueue) because it's only relevant on this
	 * single settings tab; conditional enqueue would add more complexity
	 * than the byte savings are worth. No external dependencies.
	 */
	private static function print_application_styles_and_script(): void {
		?>
<style>
.pp-fields-table th.pp-col-handle,
.pp-fields-table td.pp-col-handle { width: 1%; white-space: nowrap; }
.pp-fields-table th.pp-col-required,
.pp-fields-table td.pp-col-required { width: 1%; text-align: center; }
.pp-fields-table th.pp-col-actions,
.pp-fields-table td.pp-col-actions { width: 1%; text-align: right; white-space: nowrap; }
.pp-fields-table .pp-move-up,
.pp-fields-table .pp-move-down { padding: 0 .25em; font-size: 14px; line-height: 1; vertical-align: middle; }
.pp-fields-table input[type="text"] { width: 100%; }
.pp-fields-table .pp-options-table { width: 100%; margin: 0; border: 0; }
.pp-fields-table .pp-options-table td { padding: 2px 4px; border: 0; }
.pp-fields-table .pp-options-table td:first-child { padding-left: 0; }
.pp-fields-table .pp-options-table td:last-child { width: 1%; text-align: right; padding-right: 0; }
.pp-fields-table .pp-option-remove { color: #b32d2e; font-weight: 600; font-size: 18px; line-height: 1; }
.pp-fields-table .pp-field-options-cell.is-disabled { opacity: .35; pointer-events: none; }
.pp-fields-table .pp-field-options-cell.is-disabled::after { content: attr(data-disabled-hint); display: block; font-size: 11px; color: #646970; margin-top: 4px; }
.pp-field-remove { color: #b32d2e; }
</style>
<script>
(function () {
	var table = document.querySelector('.pp-fields-table');
	var fieldTpl = document.getElementById('pp-field-row-template');
	var optionTpl = document.getElementById('pp-option-row-template');
	var addFieldBtn = document.querySelector('.pp-field-add');
	if (!table || !fieldTpl || !optionTpl || !addFieldBtn) { return; }

	var DISABLED_HINT = <?php echo wp_json_encode( __( 'Options apply only to fields of type "select" or "checkboxes".', 'partner-program' ) ); ?>;
	var TYPES_WITH_OPTIONS = ['select', 'checkboxes'];

	function uid() { return 'n' + Math.random().toString(36).slice(2, 9); }

	function fieldIndexOf(row) {
		var input = row.querySelector('input[name^="fields["]');
		var match = input && input.name.match(/^fields\[([^\]]+)\]/);
		return match ? match[1] : '';
	}

	function syncRow(row) {
		var typeSel = row.querySelector('select[name$="[type]"]');
		var type = typeSel ? typeSel.value : '';
		row.dataset.type = type;
		var cell = row.querySelector('.pp-field-options-cell');
		if (!cell) { return; }
		var disabled = (TYPES_WITH_OPTIONS.indexOf(type) === -1);
		cell.classList.toggle('is-disabled', disabled);
		cell.setAttribute('data-disabled-hint', disabled ? DISABLED_HINT : '');
		cell.querySelectorAll('input, button').forEach(function (el) { el.disabled = disabled; });
	}

	function instantiate(tpl, replacements) {
		var html = tpl.innerHTML;
		Object.keys(replacements).forEach(function (k) {
			html = html.split(k).join(replacements[k]);
		});
		var wrap = document.createElement('tbody');
		wrap.innerHTML = html.trim();
		return wrap.firstElementChild;
	}

	function addField() {
		var row = instantiate(fieldTpl, { '__I__': uid() });
		table.querySelector('tbody').appendChild(row);
		syncRow(row);
		var keyInput = row.querySelector('input[name$="[key]"]');
		if (keyInput) { keyInput.focus(); }
	}

	function addOption(fieldRow) {
		var fieldIdx = fieldIndexOf(fieldRow);
		var optRow = instantiate(optionTpl, { '__I__': fieldIdx, '__J__': uid() });
		fieldRow.querySelector('.pp-options-table tbody').appendChild(optRow);
		var first = optRow.querySelector('input');
		if (first) { first.focus(); }
	}

	addFieldBtn.addEventListener('click', addField);

	table.addEventListener('click', function (e) {
		var t = e.target;
		var fieldRow = t.closest('tr.pp-field-row');
		if (!fieldRow) { return; }
		if (t.classList.contains('pp-field-remove')) {
			fieldRow.remove();
			return;
		}
		if (t.classList.contains('pp-move-up')) {
			var prev = fieldRow.previousElementSibling;
			if (prev) { fieldRow.parentNode.insertBefore(fieldRow, prev); }
			return;
		}
		if (t.classList.contains('pp-move-down')) {
			var next = fieldRow.nextElementSibling;
			if (next) { fieldRow.parentNode.insertBefore(next, fieldRow); }
			return;
		}
		if (t.classList.contains('pp-option-add')) {
			addOption(fieldRow);
			return;
		}
		if (t.classList.contains('pp-option-remove')) {
			var optRow = t.closest('tr.pp-option-row');
			if (optRow) { optRow.remove(); }
			return;
		}
	});

	table.addEventListener('change', function (e) {
		if (e.target.matches && e.target.matches('select[name$="[type]"]')) {
			syncRow(e.target.closest('tr.pp-field-row'));
		}
	});

	table.querySelectorAll('tr.pp-field-row').forEach(syncRow);
})();
</script>
		<?php
	}

	private static function tab_compliance( SettingsRepo $s ): void {
		$prohibited = (array) $s->get( 'compliance.prohibited_terms', [] );
		echo '<table class="form-table">';
		printf(
			'<tr><th scope="row"><label for="prohibited_terms">%s</label></th><td><textarea id="prohibited_terms" name="prohibited_terms" rows="6" cols="60">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Prohibited terms (one per line)', 'partner-program' ),
			esc_textarea( implode( "\n", $prohibited ) ),
			esc_html__( 'Used by the prohibited-term scanner.', 'partner-program' )
		);
		printf(
			'<tr><th scope="row"><label for="penalty_text">%s</label></th><td><textarea id="penalty_text" name="penalty_text" rows="3" cols="60">%s</textarea></td></tr>',
			esc_html__( 'Penalty text', 'partner-program' ),
			esc_textarea( (string) $s->get( 'compliance.penalty_text' ) )
		);
		self::field_text( 'clawback_days', __( 'Clawback window (days)', 'partner-program' ), (string) $s->get( 'compliance.clawback_days' ), '', 'number' );
		self::field_checkbox( 'auto_suspend_on_violation', __( 'Auto-suspend partners on compliance violation', 'partner-program' ), (bool) $s->get( 'compliance.auto_suspend_on_violation' ) );

		printf(
			'<tr><th scope="row"><label for="agreement_body">%s</label></th><td>',
			esc_html__( 'Compliance agreement body (HTML, versioned)', 'partner-program' )
		);
		wp_editor( (string) $s->get( 'compliance.agreement_body' ), 'agreement_body', [ 'textarea_name' => 'agreement_body' ] );
		echo '<p class="description">' . esc_html__( 'Saving this section creates a new agreement version. Existing partners will be prompted to re-accept.', 'partner-program' ) . '</p></td></tr>';

		echo '</table>';
	}

	private static function tab_exclusions( SettingsRepo $s ): void {
		echo '<table class="form-table">';
		self::field_checkbox( 'reject_refunded', __( 'Reject commissions on refunded orders', 'partner-program' ), (bool) $s->get( 'exclusions.reject_refunded' ) );
		self::field_checkbox( 'reject_cancelled', __( 'Reject commissions on cancelled orders', 'partner-program' ), (bool) $s->get( 'exclusions.reject_cancelled' ) );
		self::field_checkbox( 'reject_failed', __( 'Reject commissions on failed orders', 'partner-program' ), (bool) $s->get( 'exclusions.reject_failed' ) );
		self::field_text( 'fraud_meta_key', __( 'Order meta key marking fraud', 'partner-program' ), (string) $s->get( 'exclusions.fraud_meta_key' ) );
		self::field_text( 'compliance_meta_key', __( 'Order meta key marking compliance violation', 'partner-program' ), (string) $s->get( 'exclusions.compliance_meta_key' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Chargebacks', 'partner-program' ) . '</th><td><p class="description">' . esc_html__( 'Mark chargeback orders with the fraud meta key above (or via the Compliance violation meta key) — they\'ll be excluded automatically.', 'partner-program' ) . '</p></td></tr>';
		echo '</table>';
	}

	private static function render_iotools(): void {
		$nonce = wp_create_nonce( self::NONCE );
		echo '<h2>' . esc_html__( 'Export', 'partner-program' ) . '</h2>';
		printf(
			'<form method="post" action="%s"><input type="hidden" name="action" value="partner_program_export_settings" /><input type="hidden" name="_wpnonce" value="%s" />%s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $nonce ),
			get_submit_button( __( 'Download settings JSON', 'partner-program' ), 'secondary', 'submit', false )
		);
		echo '<h2>' . esc_html__( 'Import', 'partner-program' ) . '</h2>';
		printf(
			'<form method="post" enctype="multipart/form-data" action="%s">'
			. '<input type="hidden" name="action" value="partner_program_import_settings" />'
			. '<input type="hidden" name="_wpnonce" value="%s" />'
			. '<input type="file" name="settings_file" accept=".json,application/json" required /> %s'
			. '<p class="description">%s</p></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $nonce ),
			get_submit_button( __( 'Import settings', 'partner-program' ), 'primary', 'submit', false ),
			esc_html__( 'Only JSON files exported from this plugin are accepted.', 'partner-program' )
		);
	}

	public function handle_save(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'partner-program' ) );
		}
		check_admin_referer( self::NONCE );

		$section = isset( $_POST['section'] ) ? sanitize_key( (string) $_POST['section'] ) : '';
		$repo    = new SettingsRepo();

		switch ( $section ) {
			case 'general':
				$repo->save_section( 'general', [
					'program_name'  => sanitize_text_field( wp_unslash( (string) ( $_POST['program_name'] ?? '' ) ) ),
					'logo_url'      => esc_url_raw( wp_unslash( (string) ( $_POST['logo_url'] ?? '' ) ) ),
					'support_email' => sanitize_email( wp_unslash( (string) ( $_POST['support_email'] ?? '' ) ) ),
					'accent_color'  => sanitize_hex_color( wp_unslash( (string) ( $_POST['accent_color'] ?? '' ) ) ) ?: '#2563eb',
					'terms_url'     => esc_url_raw( wp_unslash( (string) ( $_POST['terms_url'] ?? '' ) ) ),
					'login_url'     => esc_url_raw( wp_unslash( (string) ( $_POST['login_url'] ?? '' ) ) ),
				] );
				break;

			case 'commissions':
				$repo->save_section( 'commissions', [
					'base_rate'              => (float) ( $_POST['base_rate'] ?? 0 ),
					'calculation_basis'      => sanitize_key( (string) ( $_POST['calculation_basis'] ?? 'subtotal_after_discount' ) ),
					'exclude_shipping'       => ! empty( $_POST['exclude_shipping'] ),
					'exclude_tax'            => ! empty( $_POST['exclude_tax'] ),
					'partial_refund_clawback' => ! empty( $_POST['partial_refund_clawback'] ),
				] );
				break;

			case 'tiers':
				$rows         = isset( $_POST['tiers'] ) && is_array( $_POST['tiers'] ) ? wp_unslash( (array) $_POST['tiers'] ) : [];
				$all          = $repo->all();
				$all['tiers'] = TierResolver::normalize( $rows );
				$repo->replace_all( $all );
				break;

			case 'coupon_bonus':
				$repo->save_section( 'coupon_bonus', [
					'enabled'    => ! empty( $_POST['enabled'] ),
					'bonus_rate' => (float) ( $_POST['bonus_rate'] ?? 0 ),
				] );
				break;

			case 'customer_coupon':
				$repo->save_section( 'customer_coupon', [
					'auto_create'    => ! empty( $_POST['auto_create'] ),
					'discount_type'  => sanitize_key( (string) ( $_POST['discount_type'] ?? 'percent' ) ),
					'discount_value' => (float) ( $_POST['discount_value'] ?? 0 ),
					'prefix'         => sanitize_text_field( (string) ( $_POST['prefix'] ?? 'PARTNER-' ) ),
				] );
				break;

			case 'tracking':
				$repo->save_section( 'tracking', [
					'cookie_name'        => sanitize_text_field( (string) ( $_POST['cookie_name'] ?? 'pp_ref' ) ),
					'cookie_lifetime'    => max( 1, (int) ( $_POST['cookie_lifetime'] ?? 30 ) ),
					'param'              => sanitize_key( (string) ( $_POST['param'] ?? 'ref' ) ),
					'rewrite_slug'       => sanitize_title( (string) ( $_POST['rewrite_slug'] ?? '' ) ),
					'trust_proxy_header' => ! empty( $_POST['trust_proxy_header'] ),
				] );
				$repo->save_section( 'attribution', [
					'subscription_renewals' => ! empty( $_POST['attribution_subscription_renewals'] ),
				] );
				break;

			case 'hold_payouts':
				$methods = isset( $_POST['enabled_methods'] ) && is_array( $_POST['enabled_methods'] )
					? array_map( 'sanitize_key', wp_unslash( (array) $_POST['enabled_methods'] ) )
					: [];
				$repo->save_section( 'hold_payouts', [
					'hold_days'       => max( 0, (int) ( $_POST['hold_days'] ?? 15 ) ),
					'schedule'        => sanitize_key( (string) ( $_POST['schedule'] ?? 'monthly' ) ),
					'payout_day'      => max( 1, min( 28, (int) ( $_POST['payout_day'] ?? 1 ) ) ),
					'min_threshold'   => (float) ( $_POST['min_threshold'] ?? 100 ),
					'enabled_methods' => $methods,
				] );
				break;

			case 'application':
				$rows  = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( (array) $_POST['fields'] ) : [];
				$clean = [];
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) { continue; }
					$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
					if ( '' === $key ) { continue; }
					$type  = sanitize_key( (string) ( $row['type'] ?? 'text' ) );
					$field = [
						'key'      => $key,
						'label'    => sanitize_text_field( (string) ( $row['label'] ?? $key ) ),
						'type'     => $type,
						'required' => ! empty( $row['required'] ),
					];
					if ( in_array( $type, [ 'select', 'checkboxes' ], true ) ) {
						$options = self::sanitize_options_input( (array) ( $row['options'] ?? [] ) );
						if ( $options ) {
							$field['options'] = $options;
						}
					}
					$clean[] = $field;
				}
				$repo->save_section( 'application', [
					'fields' => $clean,
				] );
				break;

			case 'compliance':
				$prohibited_raw = (string) wp_unslash( $_POST['prohibited_terms'] ?? '' );
				$lines          = array_filter( array_map( 'trim', explode( "\n", $prohibited_raw ) ) );

				$body = wp_kses_post( wp_unslash( (string) ( $_POST['agreement_body'] ?? '' ) ) );
				$repo->save_section( 'compliance', [
					'prohibited_terms'          => array_values( $lines ),
					'penalty_text'              => sanitize_textarea_field( wp_unslash( (string) ( $_POST['penalty_text'] ?? '' ) ) ),
					'agreement_body'            => $body,
					'clawback_days'             => max( 0, (int) ( $_POST['clawback_days'] ?? 90 ) ),
					'auto_suspend_on_violation' => ! empty( $_POST['auto_suspend_on_violation'] ),
				] );

				$current = \PartnerProgram\Domain\AgreementRepo::current();
				if ( ! $current || trim( (string) $current['body_html'] ) !== trim( $body ) ) {
					\PartnerProgram\Domain\AgreementRepo::create( $body, null, get_current_user_id() );
				}
				break;

			case 'exclusions':
				$repo->save_section( 'exclusions', [
					'reject_refunded'     => ! empty( $_POST['reject_refunded'] ),
					'reject_cancelled'    => ! empty( $_POST['reject_cancelled'] ),
					'reject_failed'       => ! empty( $_POST['reject_failed'] ),
					'fraud_meta_key'      => sanitize_text_field( (string) ( $_POST['fraud_meta_key'] ?? '' ) ),
					'compliance_meta_key' => sanitize_text_field( (string) ( $_POST['compliance_meta_key'] ?? '' ) ),
				] );
				break;

			case 'emails':
				$raw_events = isset( $_POST['emails_events'] ) && is_array( $_POST['emails_events'] )
					? wp_unslash( (array) $_POST['emails_events'] )
					: [];
				$clean_events = [];
				foreach ( EventRegistry::all() as $event_key => $event_def ) {
					$row                       = is_array( $raw_events[ $event_key ] ?? null ) ? $raw_events[ $event_key ] : [];
					$clean_events[ $event_key ] = [
						'enabled' => ! empty( $row['enabled'] ),
						'subject' => sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
						'body'    => wp_kses_post( (string) ( $row['body'] ?? '' ) ),
					];
				}
				$repo->save_section( 'emails', [
					'from_name'   => sanitize_text_field( wp_unslash( (string) ( $_POST['emails_from_name'] ?? '' ) ) ),
					'from_email'  => sanitize_email( wp_unslash( (string) ( $_POST['emails_from_email'] ?? '' ) ) ),
					'footer_text' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['emails_footer_text'] ?? '' ) ) ),
					'events'      => $clean_events,
				] );
				break;

			case 'logs':
				$repo->save_section( 'logs', [
					'retention_days' => max( 0, (int) ( $_POST['logs_retention_days'] ?? 90 ) ),
				] );
				break;

		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'partner-program-settings', 'tab' => $section, 'saved' => 1 ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_export(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'partner-program' ) );
		}
		check_admin_referer( self::NONCE );
		$repo = new SettingsRepo();
		nocache_headers();
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="partner-program-settings.json"' );
		echo wp_json_encode( $repo->all(), JSON_PRETTY_PRINT );
		exit;
	}

	public function handle_import(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'partner-program' ) );
		}
		check_admin_referer( self::NONCE );

		$tmp = isset( $_FILES['settings_file']['tmp_name'] ) ? (string) $_FILES['settings_file']['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=partner-program-settings&tab=iotools&import_error=1' ) );
			exit;
		}

		// Cap the import payload at 1 MB. The settings blob is a small JSON
		// tree (KBs, not MBs); anything bigger is either malformed or hostile,
		// and a real export will never come close to this ceiling.
		$size = (int) ( filesize( $tmp ) ?: 0 );
		if ( $size <= 0 || $size > 1024 * 1024 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=partner-program-settings&tab=iotools&import_error=1' ) );
			exit;
		}

		$json = file_get_contents( $tmp );
		$data = $json ? json_decode( (string) $json, true ) : null;
		if ( ! is_array( $data ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=partner-program-settings&tab=iotools&import_error=1' ) );
			exit;
		}

		// Drop anything we don't recognise so a malformed (or hostile) file
		// can't poison the settings blob with arbitrary top-level keys.
		$filtered = SettingsRepo::filter_for_import( $data );
		if ( ! $filtered ) {
			wp_safe_redirect( admin_url( 'admin.php?page=partner-program-settings&tab=iotools&import_error=1' ) );
			exit;
		}

		// Re-normalize tiers so an import can't sneak in unsorted or
		// duplicate-keyed tiers that the read path no longer defends
		// against.
		if ( isset( $filtered['tiers'] ) && is_array( $filtered['tiers'] ) ) {
			$filtered['tiers'] = TierResolver::normalize( $filtered['tiers'] );
		}

		( new SettingsRepo() )->replace_all( $filtered );
		wp_safe_redirect( admin_url( 'admin.php?page=partner-program-settings&tab=iotools&saved=1' ) );
		exit;
	}
}
