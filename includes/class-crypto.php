<?php
/**
 * Authenticated encryption for stored tokens, keyed from the site's WordPress salts.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Crypto
 */
class Crypto {

	const PREFIX = 'ecw1:';

	/**
	 * Derives the secretbox key from the auth salts. Not cached, so a salt change takes effect at once.
	 *
	 * @return string
	 */
	private static function key(): string {
		return sodium_crypto_generichash(
			'ecomweb-session-v1|' . wp_salt( 'auth' ) . wp_salt( 'secure_auth' ),
			'',
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}

	/**
	 * Encrypts a string.
	 *
	 * @param string $plain Plaintext.
	 * @return string "ecw1:" . base64( nonce . box ).
	 */
	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext encoding, not obfuscation.
	}

	/**
	 * Decrypts a stored string.
	 *
	 * @param string $stored Output of encrypt().
	 * @return string|null Plaintext, or null on any failure.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext decoding, not obfuscation.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
		return false === $plain ? null : $plain;
	}
}
