<?php
/** @var ?array $agreement @var \PartnerProgram\Support\SettingsRepo $settings */
defined( 'ABSPATH' ) || exit;
$prohibited = (array) $settings->get( 'compliance.prohibited_terms', [] );
$penalty    = (string) $settings->get( 'compliance.penalty_text', '' );
?>
<?php if ( $agreement ) : ?>
	<h3><?php echo esc_html( sprintf( __( 'Compliance agreement (v%d)', 'partner-program' ), (int) $agreement['version'] ) ); ?></h3>
	<div class="pp-agreement-body">
		<?php echo wp_kses_post( (string) $agreement['body_html'] ); ?>
	</div>
<?php endif; ?>

<?php if ( $prohibited ) : ?>
	<h3 class="pp-mt-lg"><?php esc_html_e( 'Prohibited claims', 'partner-program' ); ?></h3>
	<ul>
		<?php foreach ( $prohibited as $term ) : ?>
			<li><?php echo esc_html( (string) $term ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php if ( $penalty ) : ?>
	<h3><?php esc_html_e( 'Penalty for violations', 'partner-program' ); ?></h3>
	<p><?php echo esc_html( $penalty ); ?></p>
<?php endif; ?>
