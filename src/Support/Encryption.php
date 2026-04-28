<?php
/**
 * Symmetric encryption helper for sensitive payout details.
 *
 * Requires libsodium (bundled with PHP 7.2+ and pretty much every modern
 * managed host). The pre-1.2 base64 fallback was removed: silently storing
 * sensitive PII as base64 with an `encrypted-looking` prefix was worse than
 * either failing loudly or refusing to write. The DECRYPT path still
 * understands the legacy `ppp1:` prefix so any historical rows on a host
 * that briefly fell back can still be read.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class Encryption {

	private const PREFIX_SODIUM = 'pps1:';
	private const PREFIX_LEGACY = 'ppp1:'; // Read-only; never emitted by this class anymore.

	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	/**
	 * @throws \RuntimeException when libsodium is not available; callers
	 *         must check Encryption::is_available() first or catch this and
	 *         present a user-actionable error.
	 */
	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! self::is_available() ) {
			throw new \RuntimeException(
				'libsodium is required to store partner payout details. '
				. 'Ask your host to enable the sodium PHP extension.'
			);
		}

		$key    = $this->derive_key();
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher );
	}

	public function decrypt( string $blob ): string {
		if ( '' === $blob ) {
			return '';
		}

		if ( 0 === strpos( $blob, self::PREFIX_SODIUM ) ) {
			if ( ! self::is_available() ) {
				return '';
			}
			$raw = base64_decode( substr( $blob, strlen( self::PREFIX_SODIUM ) ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$key    = $this->derive_key();
			$result = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return false === $result ? '' : $result;
		}

		// Legacy base64 blob from <1.2.0 only kept for backward read.
		if ( 0 === strpos( $blob, self::PREFIX_LEGACY ) ) {
			$decoded = base64_decode( substr( $blob, strlen( self::PREFIX_LEGACY ) ), true );
			return false === $decoded ? '' : $decoded;
		}

		return '';
	}

	private function derive_key(): string {
		$salt = wp_salt( 'auth' );
		return substr( hash( 'sha256', 'partner-program|' . $salt, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
