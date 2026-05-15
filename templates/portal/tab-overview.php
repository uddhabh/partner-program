<?php
/** @var int $pending_cents @var int $approved_cents @var int $paid_cents @var array $tier_progress @var array $tiers @var array|null $stored_tier */
defined( 'ABSPATH' ) || exit;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\Ui;

$next_tier     = $tier_progress['next_tier'] ?? null;
$current_sales = (int) ( $tier_progress['current_sales_cents'] ?? 0 );

Ui::stat_cards( [
	[ 'title' => __( 'Pending', 'partner-program' ),  'value' => Money::format( $pending_cents ),  'sub' => __( 'In hold period', 'partner-program' ) ],
	[ 'title' => __( 'Approved', 'partner-program' ), 'value' => Money::format( $approved_cents ), 'sub' => __( 'Eligible for next payout', 'partner-program' ) ],
	[ 'title' => __( 'Paid', 'partner-program' ),     'value' => Money::format( $paid_cents ),     'sub' => __( 'Lifetime', 'partner-program' ) ],
] );
?>

<?php if ( $stored_tier ) : ?>
<p class="pp-mt-xl">
	<?php
	printf(
		/* translators: 1: tier label, 2: commission rate percentage */
		esc_html__( 'Your earning tier: %1$s (%2$s%% commission rate)', 'partner-program' ),
		esc_html( (string) ( $stored_tier['label'] ?? '' ) ),
		esc_html( (string) $stored_tier['rate'] )
	);
	?>
</p>
<?php else : ?>
<p class="pp-mt-xl"><?php esc_html_e( 'No tier assigned yet — tier is set on the 1st of each month based on the previous month\'s sales.', 'partner-program' ); ?></p>
<?php endif; ?>

<h3 class="pp-mt-xl"><?php esc_html_e( 'This month\'s progress', 'partner-program' ); ?></h3>
<p>
	<?php
	printf(
		/* translators: %s: dollar amount */
		esc_html__( 'Attributed sales so far: %s', 'partner-program' ),
		'<strong>' . esc_html( Money::format( $current_sales ) ) . '</strong>'
	);
	?>
</p>

<?php if ( $next_tier ) : ?>
	<?php
	$next_min_cents = (int) round( (float) ( $next_tier['min'] ?? 0 ) * 100 );
	$gap_cents      = max( 0, $next_min_cents - $current_sales );
	?>
	<p>
		<?php
		printf(
			/* translators: 1: target sales amount, 2: tier label, 3: tier rate %, 4: remaining amount */
			esc_html__( 'Reach %1$s in sales this month to qualify for %2$s (%3$s%%) next month — %4$s to go.', 'partner-program' ),
			esc_html( Money::format( $next_min_cents ) ),
			esc_html( (string) ( $next_tier['label'] ?? '' ) ),
			esc_html( (string) $next_tier['rate'] ),
			esc_html( Money::format( $gap_cents ) )
		);
		?>
	</p>
<?php endif; ?>
