<?php
/**
 * The encrypted sign-in session, kept in one non-autoloaded option.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Session_Store
 */
class Session_Store {

	const OPTION        = 'ecomweb_session';
	const REASON_OPTION = 'ecomweb_signed_out_reason';

	/**
	 * Reads the session, bypassing the object cache.
	 *
	 * If the stored value can't be decrypted it is deleted and the reason recorded. Never falls back to plaintext.
	 *
	 * @return array|null
	 */
	public function read(): ?array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$stored = get_option( self::OPTION, false );
		if ( false === $stored ) {
			return null;
		}

		$plain   = is_string( $stored ) ? Crypto::decrypt( $stored ) : null;
		$session = null === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $session ) ) {
			$this->delete();
			update_option( self::REASON_OPTION, 'salts_changed', false );
			return null;
		}
		return $session;
	}

	/**
	 * Encrypts and saves the session.
	 *
	 * @param array $session Session data.
	 */
	public function write( array $session ): void {
		$value = Crypto::encrypt( (string) wp_json_encode( $session ) );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $value, '', false );
		} else {
			update_option( self::OPTION, $value, false );
		}
	}

	/**
	 * Removes the session.
	 */
	public function delete(): void {
		delete_option( self::OPTION );
		wp_cache_delete( self::OPTION, 'options' );
	}
}
