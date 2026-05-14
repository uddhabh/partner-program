<?php
/**
 * HTML wrapper for transactional partner-program emails.
 *
 * Override in theme: {theme}/partner-program/emails/wrapper.php
 *
 * Provided variables:
 *  - string $body         Plain-text body (may contain author HTML).
 *  - array  $tokens       Already-replaced token map (for reference).
 *  - string $program_name Configured program name.
 *  - string $logo_url     Configured logo URL (may be empty).
 *  - string $accent_color Hex color (e.g. #2563eb).
 *  - string $footer_text  Pre-formatted footer string.
 *
 * @package PartnerProgram
 */

defined( 'ABSPATH' ) || exit;

/** @var string $body */
/** @var string $program_name */
/** @var string $logo_url */
/** @var string $accent_color */
/** @var string $footer_text */

// Looks like real HTML => trust it (already sanitised by author). Otherwise
// auto-paragraph the plain-text body.
$content = ( false !== stripos( $body, '<p' ) || false !== stripos( $body, '<br' ) )
	? wp_kses_post( $body )
	: wpautop( wp_kses_post( $body ) );

$accent = preg_match( '/^#[0-9a-fA-F]{3,8}$/', $accent_color ) ? $accent_color : '#2563eb';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width,initial-scale=1" />
	<title><?php echo esc_html( $program_name ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#1f2328;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f5f7;padding:32px 16px;">
		<tr>
			<td align="center">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.06);overflow:hidden;">
					<tr>
						<td style="background:<?php echo esc_attr( $accent ); ?>;padding:20px 28px;color:#ffffff;">
							<?php if ( $logo_url ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $program_name ); ?>" style="max-height:36px;display:block;border:0;outline:none;" />
							<?php else : ?>
								<div style="font-size:18px;font-weight:600;line-height:1.2;"><?php echo esc_html( $program_name ); ?></div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td style="padding:28px;font-size:15px;line-height:1.6;color:#1f2328;">
							<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
					</tr>
					<tr>
						<td style="padding:18px 28px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#6b7280;background:#fafbfc;">
							<?php echo esc_html( $footer_text ); ?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
