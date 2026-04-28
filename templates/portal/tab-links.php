<?php
/** @var string $coupon_code @var string $ref_link */
defined( 'ABSPATH' ) || exit;
?>
<h3><?php esc_html_e( 'Your referral link', 'partner-program' ); ?></h3>
<div class="pp-copy">
	<input type="text" readonly value="<?php echo esc_attr( $ref_link ); ?>" id="pp-ref-link" />
	<button type="button" class="pp-btn" data-pp-copy="pp-ref-link"><?php esc_html_e( 'Copy', 'partner-program' ); ?></button>
</div>

<h3 class="pp-mt-lg"><?php esc_html_e( 'Your coupon code', 'partner-program' ); ?></h3>
<div class="pp-copy">
	<input type="text" readonly value="<?php echo esc_attr( $coupon_code ); ?>" id="pp-coupon" />
	<button type="button" class="pp-btn" data-pp-copy="pp-coupon"><?php esc_html_e( 'Copy', 'partner-program' ); ?></button>
</div>

<h3 class="pp-mt-lg"><?php esc_html_e( 'Build a tagged link', 'partner-program' ); ?></h3>
<p><?php esc_html_e( 'Paste any URL on this site and we will tag it with your referral code.', 'partner-program' ); ?></p>
<div class="pp-link-builder">
	<input type="url" id="pp-builder-url" class="pp-builder-input" placeholder="https://..." />
	<button type="button" class="pp-btn pp-btn-primary" data-pp-build-link><?php esc_html_e( 'Build', 'partner-program' ); ?></button>
	<input type="text" id="pp-builder-result" class="pp-builder-output" readonly />
</div>
