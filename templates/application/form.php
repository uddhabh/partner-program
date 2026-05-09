<?php
/**
 * @var array  $fields
 * @var \PartnerProgram\Support\SettingsRepo $settings
 * @var array|null $flash
 * @var string $action
 * @var string $nonce  Pre-rendered nonce field HTML.
 */
defined( 'ABSPATH' ) || exit;

$program = (string) $settings->get( 'general.program_name', __( 'Partner Program', 'partner-program' ) );
$accent  = (string) $settings->get( 'general.accent_color', '#2563eb' );
?>
<div class="pp-application" style="--pp-accent: <?php echo esc_attr( $accent ); ?>;">
	<h2><?php echo esc_html( sprintf( __( 'Apply to the %s', 'partner-program' ), $program ) ); ?></h2>

	<?php if ( $flash ) : ?>
		<div class="pp-alert pp-alert-<?php echo esc_attr( $flash['type'] ); ?>">
			<?php echo esc_html( $flash['message'] ); ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $action ); ?>" enctype="multipart/form-data" class="pp-form">
		<input type="hidden" name="action" value="partner_program_apply" />
		<?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div style="display:none" aria-hidden="true">
			<label>Website (leave empty)<input type="text" name="hp_website" /></label>
		</div>

		<?php
		foreach ( $fields as $f ) :
			$key      = (string) ( $f['key'] ?? '' );
			$label    = (string) ( $f['label'] ?? $key );
			$type     = (string) ( $f['type'] ?? 'text' );
			$required = ! empty( $f['required'] );
			if ( '' === $key ) {
				continue;
			}
			$id        = 'pp_' . $key;
			$req_attr  = $required ? ' required' : '';
			$req_mark  = $required ? ' <span class="pp-required" aria-hidden="true">*</span>' : '';
			$options   = (array) ( $f['options'] ?? [] );

			if ( 'checkbox' === $type ) :
				// Single checkbox: input sits inline inside the label so the
				// box and its sentence read as one line. No wpautop "<br>"
				// injected because the label/input pair is one tag with no
				// intervening newline.
				?>
				<div class="pp-field pp-field-checkbox"><label for="<?php echo esc_attr( $id ); ?>"><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="1"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> /> <span class="pp-check-text"><?php echo esc_html( $label ); ?><?php echo $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></label></div>
				<?php
				continue;
			endif;

			if ( 'checkboxes' === $type ) :
				// Multi-select checkbox group. Submitted as `name="key[]"` so
				// the existing array-aware handler in ApplicationForm picks
				// up the selected values without changes.
				?>
				<fieldset class="pp-field pp-field-checkboxes">
					<legend><?php echo esc_html( $label ); echo $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></legend>
					<?php foreach ( $options as $opt_key => $opt ) :
						if ( is_array( $opt ) ) {
							$opt_value = (string) ( $opt['value'] ?? $opt_key );
							$opt_label = (string) ( $opt['label'] ?? $opt_value );
						} else {
							$opt_value = (string) $opt;
							$opt_label = ucwords( str_replace( [ '_', '-' ], ' ', $opt_value ) );
						}
						$opt_id = $id . '_' . sanitize_html_class( $opt_value );
						?>
						<label for="<?php echo esc_attr( $opt_id ); ?>" class="pp-check-option"><input type="checkbox" id="<?php echo esc_attr( $opt_id ); ?>" name="<?php echo esc_attr( $key ); ?>[]" value="<?php echo esc_attr( $opt_value ); ?>" /> <?php echo esc_html( $opt_label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<?php
				continue;
			endif;
			?>
			<div class="pp-field">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); echo $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<?php if ( 'textarea' === $type ) : ?>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="4"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></textarea>
				<?php elseif ( 'select' === $type ) : ?>
					<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<option value=""><?php esc_html_e( '— Select —', 'partner-program' ); ?></option>
						<?php foreach ( $options as $opt_key => $opt ) :
							if ( is_array( $opt ) ) {
								$opt_value = (string) ( $opt['value'] ?? $opt_key );
								$opt_label = (string) ( $opt['label'] ?? $opt_value );
							} else {
								$opt_value = (string) $opt;
								// Legacy flat shape: humanize the key for display.
								$opt_label = ucwords( str_replace( [ '_', '-' ], ' ', $opt_value ) );
							}
							?>
							<option value="<?php echo esc_attr( $opt_value ); ?>"><?php echo esc_html( $opt_label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php elseif ( 'file' === $type ) : ?>
					<input type="file" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" accept=".pdf,image/*"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				<?php elseif ( 'email' === $type ) : ?>
					<input type="email" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Submit application', 'partner-program' ); ?></button>
	</form>
</div>
