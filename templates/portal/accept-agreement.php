<?php
/**
 * @var array $agreement
 * @var string $action
 * @var string $nonce
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="pp-accept-agreement">
	<h2><?php esc_html_e( 'Updated compliance agreement', 'partner-program' ); ?></h2>
	<p><?php esc_html_e( 'Please read and accept the latest agreement to continue using the partner portal.', 'partner-program' ); ?></p>
	<div class="pp-agreement-body">
		<?php echo wp_kses_post( (string) $agreement['body_html'] ); ?>
	</div>
	<form method="post" action="<?php echo esc_url( $action ); ?>" class="pp-form">
		<input type="hidden" name="action" value="pp_portal_accept_agreement" />
		<?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<label class="pp-check-text"><input type="checkbox" name="accepted" value="1" required /> <?php esc_html_e( 'I have read and agree to the terms above.', 'partner-program' ); ?></label>
		<button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Accept and continue', 'partner-program' ); ?></button>
	</form>
</div>
