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
			if ( false !== strpos( $haystack, $needle ) ) {
				$found[] = $term;
			}
		}
		return [ 'matches' => array_values( array_unique( $found ) ), 'ok' => empty( $found ) ];
	}
}
