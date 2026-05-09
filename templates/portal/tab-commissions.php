<?php
/** @var array $commissions */
defined( 'ABSPATH' ) || exit;
use PartnerProgram\Support\Money;
?>
<h3><?php esc_html_e( 'Commissions', 'partner-program' ); ?></h3>
<?php if ( ! $commissions ) : ?>
	<p><?php esc_html_e( 'No commissions yet.', 'partner-program' ); ?></p>
<?php else : ?>
	<table class="pp-table">
		<thead><tr>
			<th><?php esc_html_e( 'Date', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Order', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Source', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Rate', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Base', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Amount', 'partner-program' ); ?></th>
			<th><?php esc_html_e( 'Status', 'partner-program' ); ?></th>
		</tr></thead>
		<tbody>
			<?php foreach ( $commissions as $c ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $c['created_at'] ); ?></td>
					<td><?php echo ( (int) $c['order_id'] > 0 ) ? '#' . (int) $c['order_id'] : '—'; ?></td>
					<td><?php echo esc_html( (string) $c['source'] ); ?><?php echo $c['coupon_used'] ? ' ★' : ''; ?></td>
					<td><?php echo esc_html( (string) $c['rate'] ); ?>%</td>
					<td><?php echo esc_html( Money::format( (int) $c['base_amount_cents'], (string) $c['currency'] ) ); ?></td>
					<td><?php echo esc_html( Money::format( (int) $c['amount_cents'], (string) $c['currency'] ) ); ?></td>
					<td><?php echo esc_html( (string) $c['status'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
