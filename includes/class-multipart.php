<?php
/**
 * Builds a multipart/form-data body with one file.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Multipart
 */
class Multipart {

	/**
	 * Builds the body and its Content-Type.
	 *
	 * @param array  $fields     Text fields, name => value.
	 * @param string $file_field Name of the file field.
	 * @param string $filename   File name sent to the server.
	 * @param string $mime       File MIME type.
	 * @param string $bytes      File contents.
	 * @return array [string $body, string $content_type]
	 */
	public static function build( array $fields, string $file_field, string $filename, string $mime, string $bytes ): array {
		$boundary = wp_generate_password( 24, false );
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="' . self::quote( (string) $name ) . '"' . "\r\n\r\n"
				. (string) $value . "\r\n";
		}

		$body .= '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="' . self::quote( $file_field ) . '"; filename="' . self::quote( $filename ) . '"' . "\r\n"
			. 'Content-Type: ' . self::header_value( $mime ) . "\r\n\r\n"
			. $bytes . "\r\n"
			. '--' . $boundary . "--\r\n";

		return array( $body, 'multipart/form-data; boundary=' . $boundary );
	}

	/**
	 * Makes a value safe inside a quoted Content-Disposition parameter.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function quote( string $value ): string {
		return str_replace( '"', '%22', self::header_value( $value ) );
	}

	/**
	 * Strips line breaks so a value can't start a new header.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function header_value( string $value ): string {
		return str_replace( array( "\r", "\n" ), '', $value );
	}
}
