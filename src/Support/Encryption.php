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
	 * from the upgrade path. We store the key independently of
	 * wp-config.php salts so an admin rotating their salts (a documented
	 * hardening practice) doesn't silently brick every encrypted payout
	 * blob on the site.
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

		$key    = $this->primary_key();
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
			// Try the dedicated key first; fall back to the legacy
			// wp_salt-derived key so blobs written by 1.1.x (or by 1.2
			// installs that ran before activate stamped the key option)
			// still decrypt. New writes always go through the dedicated
			// key, so legacy rows naturally migrate the next time they're
			// re-saved.
			foreach ( $this->candidate_keys() as $key ) {
				$result = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
				if ( false !== $result ) {
					return $result;
				}
			}
			return '';
		}

		// Legacy base64 blob from <1.2.0 only kept for backward read.
		if ( 0 === strpos( $blob, self::PREFIX_LEGACY ) ) {
			$decoded = base64_decode( substr( $blob, strlen( self::PREFIX_LEGACY ) ), true );
			return false === $decoded ? '' : $decoded;
		}

		return '';
	}

	private function primary_key(): string {
		$stored = get_option( self::KEY_OPTION, '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			$raw = base64_decode( $stored, true );
			if ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) {
				return $raw;
			}
		}
		// First call before ensure_key() ran (or storage is broken):
		// fall back to the legacy derivation so we don't lose data.
		return $this->legacy_key();
	}

	/**
	 * @return array<int, string>
	 */
	private function candidate_keys(): array {
		$keys   = [];
		$stored = get_option( self::KEY_OPTION, '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			$raw = base64_decode( $stored, true );
			if ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) {
				$keys[] = $raw;
			}
		}
		$keys[] = $this->legacy_key();
		return $keys;
	}

	private function legacy_key(): string {
		$salt = wp_salt( 'auth' );
		return substr( hash( 'sha256', 'partner-program|' . $salt, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
