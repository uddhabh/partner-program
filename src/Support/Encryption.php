<?php
/**
 * Symmetric encryption helper for sensitive payout details.
 *
 * Requires libsodium (bundled with PHP 7.2+ and pretty much every modern
 * managed host). The key is generated once on activation/upgrade and stored
 * in wp_options separately from wp-config.php salts, so admins rotating
 * their salts don't brick stored payout blobs.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class Encryption {

	private const PREFIX     = 'pps1:';
	private const KEY_OPTION = 'partner_program_encryption_key';

	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	/**
	 * Generate and persist a dedicated 32-byte encryption key if one
	 * isn't already stored. Idempotent: safe to call from activation and
	 * from the upgrade path.
	 */
	public static function ensure_key(): void {
		if ( ! self::is_available() ) {
			return;
		}
		$existing = get_option( self::KEY_OPTION, '' );
		if ( is_string( $existing ) && '' !== $existing ) {
			return;
		}
		$raw = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		add_option( self::KEY_OPTION, base64_encode( $raw ), '', false );
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

		$key    = $this->key();
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	public function decrypt( string $blob ): string {
		if ( '' === $blob || 0 !== strpos( $blob, self::PREFIX ) ) {
			return '';
		}
		if ( ! self::is_available() ) {
			return '';
		}
		$raw = base64_decode( substr( $blob, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$result = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key() );
		return false === $result ? '' : $result;
	}

	private function key(): string {
		$stored = (string) get_option( self::KEY_OPTION, '' );
		$raw    = '' !== $stored ? base64_decode( $stored, true ) : false;
		if ( false === $raw || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $raw ) ) {
			throw new \RuntimeException( 'Partner Program encryption key is missing — reactivate the plugin.' );
		}
		return $raw;
	}
}
