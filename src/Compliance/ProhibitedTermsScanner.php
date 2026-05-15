<?php
/**
 * Prohibited-term scanner.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Compliance;

use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class ProhibitedTermsScanner {

	/**
	 * @return array{matches:array<int,string>, ok:bool}
	 */
	public static function scan( string $text ): array {
		$settings = new SettingsRepo();
		$terms    = (array) $settings->get( 'compliance.prohibited_terms', [] );
		$haystack = strtolower( wp_strip_all_tags( $text ) );
		$found    = [];
		foreach ( $terms as $term ) {
			$needle = strtolower( trim( (string) $term ) );
			if ( '' === $needle ) {
				continue;
			}
			// Use word-boundary regex so "weight loss" doesn't match inside
			// "counterweight losses" and "dosing" doesn't match "disposing".
			// Multi-word phrases already contain spaces, which act as natural
			// boundaries; the \b anchors handle single-word terms.
			if ( 1 === preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/u', $haystack ) ) {
				$found[] = $term;
			}
		}
		return [ 'matches' => array_values( array_unique( $found ) ), 'ok' => empty( $found ) ];
	}
}
