<?php
/** @var int $pending_cents @var int $approved_cents @var int $paid_cents @var array $tier_progress @var array $tiers */
defined( 'ABSPATH' ) || exit;
use PartnerProgram\Support\Money;

$current_tier = $tier_progress['current_tier'] ?? null;
$next_tier    = $tier_progress['next_tier'] ?? null;
$current_sales = (int) ( $tier_progress['current_sales_cents'] ?? 0 );
?>
<div class="pp-grid">
	<div class="pp-card">
		<div class="pp-card-title"><?php esc_html_e( 'Pending', 'partner-program' ); ?></div>
		<div class="pp-card-value"><?php echo esc_html( Money::format( $pending_cents ) ); ?></div>
		<div class="pp-card-sub"><?php esc_html_e( 'In hold period', 'partner-program' ); ?></div>
	</div>
	<div class="pp-card">
		<div class="pp-card-title"><?php esc_html_e( 'Approved', 'partner-program' ); ?></div>
		<div class="pp-card-value"><?php echo esc_html( Money::format( $approved_cents ) ); ?></div>
		<div class="pp-card-sub"><?php esc_html_e( 'Eligible for next payout', 'partner-program' ); ?></div>
	</div>
	<div class="pp-card">
		<div class="pp-card-title"><?php esc_html_e( 'Paid', 'partner-program' ); ?></div>
		<div class="pp-card-value"><?php echo esc_html( Money::format( $paid_cents ) ); ?></div>
		<div class="pp-card-sub"><?php esc_html_e( 'Lifetime', 'partner-program' ); ?></div>
	</div>
</div>

<h3 class="pp-mt-xl"><?php esc_html_e( 'This month', 'partner-program' ); ?></h3>
<p>
	<?php
	printf(
		/* translators: %s: dollar amount */
		esc_html__( 'Attributed sales so far: %s', 'partner-program' ),
		'<strong>' . esc_html( Money::format( $current_sales ) ) . '</strong>'
	);
	?>
</p>
<?php if ( $current_tier ) : ?>
	<p>
		<?php printf( esc_html__( 'Current tier: %s (%s%% rate)', 'partner-program' ), esc_html( (string) ( $current_tier['label'] ?? '' ) ), esc_html( (string) $current_tier['rate'] ) ); ?>
	</p>
<?php endif; ?>

<?php if ( $next_tier ) : ?>
	<?php
	$next_min_cents = (int) round( (float) ( $next_tier['min'] ?? 0 ) * 100 );
	$gap_cents      = max( 0, $next_min_cents - $current_sales );
	?>
	<p>
		<?php
		printf(
			/* translators: 1: target sales amount, 2: tier label, 3: tier rate %, 4: remaining amount */
			esc_html__( 'Reach %1$s in sales this month to unlock %2$s (%3$s%%) — %4$s to go.', 'partner-program' ),
			esc_html( Money::format( $next_min_cents ) ),
			esc_html( (string) ( $next_tier['label'] ?? '' ) ),
			esc_html( (string) $next_tier['rate'] ),
			esc_html( Money::format( $gap_cents ) )
		);
		?>
	</p>
<?php endif; ?>
