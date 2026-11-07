<?php
/**
 * Crypto tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Crypto;

/**
 * Class Test_Crypto
 */
class Test_Crypto extends WP_UnitTestCase {

	/**
	 * Round trip.
	 */
	public function test_round_trip() {
		$this->assertSame( 'tok-123', Crypto::decrypt( Crypto::encrypt( 'tok-123' ) ) );
	}

	/**
	 * Prefix and random nonce.
	 */
	public function test_ciphertext_differs_each_time_and_has_prefix() {
		$a = Crypto::encrypt( 'tok-123' );
		$this->assertStringStartsWith( 'ecw1:', $a );
		$this->assertNotSame( $a, Crypto::encrypt( 'tok-123' ) );
	}

	/**
	 * No plaintext leak.
	 */
	public function test_ciphertext_does_not_contain_plaintext() {
		$this->assertStringNotContainsString( 'tok-123', Crypto::encrypt( 'tok-123' ) );
	}

	/**
	 * Salt change makes old ciphertext unreadable.
	 */
	public function test_changed_salts_fail() {
		$box = Crypto::encrypt( 'x' );
		add_filter(
			'salt',
			function ( $salt ) {
				return $salt . 'rotated';
			}
		);
		$this->assertNull( Crypto::decrypt( $box ) );
	}

	/**
	 * Garbage never decrypts and plaintext is never returned.
	 */
	public function test_garbage_and_plaintext_fail() {
		$this->assertNull( Crypto::decrypt( 'tok-123' ) );
		$this->assertNull( Crypto::decrypt( 'ecw1:!!!' ) );
		$this->assertNull( Crypto::decrypt( 'ecw1:' . base64_encode( 'short' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * A flipped byte fails authentication.
	 */
	public function test_tampered_ciphertext_fails() {
		$box                       = Crypto::encrypt( 'tok-123' );
		$raw                       = base64_decode( substr( $box, 5 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->assertNull( Crypto::decrypt( 'ecw1:' . base64_encode( $raw ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
