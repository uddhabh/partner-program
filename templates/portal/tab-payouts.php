<?php
/**
 * @var array  $affiliate
 * @var array  $payouts
 * @var array  $enabled_methods
 * @var int    $approved_cents
 * @var int    $min_threshold_cents
 */
defined( 'ABSPATH' ) || exit;
use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Support\Money;

$details        = AffiliateRepo::decrypt_payout_details( $affiliate['payout_details'] ?? null );
$current_method = (string) ( $affiliate['payout_method'] ?? '' );
$progress_pct   = $min_threshold_cents > 0 ? min( 100, (int) round( ( $approved_cents / $min_threshold_cents ) * 100 ) ) : 100;
?>
<h3><?php esc_html_e( 'Payout method', 'partner-program' ); ?></h3>
<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
	<div class="pp-alert pp-alert-success"><?php esc_html_e( 'Saved.', 'partner-program' ); ?></div>
<?php endif; ?>
<?php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$pp_error = isset( $_GET['pp_error'] ) ? sanitize_key( (string) wp_unslash( $_GET['pp_error'] ) ) : '';
if ( 'invalid_method' === $pp_error ) :
	?>
	<div class="pp-alert pp-alert-error"><?php esc_html_e( 'Please choose one of the enabled payout methods.', 'partner-program' ); ?></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pp-form">
	<input type="hidden" name="action" value="pp_portal_save_payout" />
	<?php wp_nonce_field( 'pp_save_payout', '_pp_save_payout_nonce' ); ?>
	<div class="pp-field">
		<label><?php esc_html_e( 'Method', 'partner-program' ); ?>
			<select name="payout_method">
				<option value=""><?php esc_html_e( '— Select —', 'partner-program' ); ?></option>
				<?php foreach ( $enabled_methods as $m ) : ?>
					<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $current_method, $m ); ?>><?php echo esc_html( strtoupper( $m ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	</div>
	<div class="pp-field"><label><?php esc_html_e( 'Account / handle / email', 'partner-program' ); ?><input type="text" name="payout_details[account]" value="<?php echo esc_attr( (string) ( $details['account'] ?? '' ) ); ?>" /></label></div>
	<div class="pp-field"><label><?php esc_html_e( 'Routing / extra info', 'partner-program' ); ?><input type="text" name="payout_details[routing]" value="<?php echo esc_attr( (string) ( $details['routing'] ?? '' ) ); ?>" /></label></div>
	<div class="pp-field"><label><?php esc_html_e( 'Notes (e.g. legal name)', 'partner-program' ); ?><input type="text" name="payout_details[notes]" value="<?php echo esc_attr( (string) ( $details['notes'] ?? '' ) ); ?>" /></label></div>
	<button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Save payout method', 'partner-program' ); ?></button>
</form>

<h3 style="margin-top:2em;"><?php esc_html_e( 'Threshold progress', 'partner-program' ); ?></h3>
<div class="pp-progress"><div class="pp-progress-bar" style="width: <?php echo (int) $progress_pct; ?>%;"></div></div>
<p>
	<?php
	printf(
		esc_html__( 'You have %1$s approved of %2$s minimum payout threshold.', 'partner-program' ),
		'<strong>' . esc_html( Money::format( $approved_cents ) ) . '</strong>',
		'<strong>' . esc_html( Money::format( $min_threshold_cents ) ) . '</strong>'
	);
	?>
</p>

<h3 style="margin-top:2em;"><?php esc_html_e( 'Payout history', 'partner-program' ); ?></h3>
<?php if ( ! $payouts ) : ?>
	<p><?php esc_html_e( 'No payouts yet.', 'partner-program' ); ?></p>
<?php else : ?>
	<table class="pp-table">
		<thead><tr>
			<th><?php esc_html_e( 'Period', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Amount', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Method', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Status', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Reference', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Paid at', 'partner-program' ); ?></th>
		</tr></thead>
		<tbody>
			<?php foreach ( $payouts as $p ) : ?>
				<tr>
					<td><?php echo esc_html( ( $p['period_start'] ?? '' ) . ' / ' . ( $p['period_end'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( Money::format( (int) $p['total_amount_cents'], (string) $p['currency'] ) ); ?></td>
					<td><?php echo esc_html( (string) $p['method'] ); ?></td>
					<td><?php echo esc_html( (string) $p['status'] ); ?></td>
					<td><?php echo esc_html( (string) ( $p['reference'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $p['paid_at'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
