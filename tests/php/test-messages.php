<?php
/**
 * Messages tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Messages;

/**
 * Class Test_Messages
 */
class Test_Messages extends WP_UnitTestCase {

	/**
	 * Unknown codes use the API text.
	 */
	public function test_known_codes_translated_unknown_falls_back() {
		$this->assertSame(
			'API text',
			Messages::warning(
				array(
					'code'    => 'NEW_CODE',
					'message' => 'API text',
				),
				array()
			)
		);
		$this->assertSame(
			'Err text',
			Messages::error(
				array(
					'code'    => 'NEW_CODE',
					'message' => 'Err text',
				)
			)
		);
		$this->assertSame(
			'An image could not be copied.',
			Messages::warning(
				array(
					'code'    => 'IMAGE_FAILED',
					'message' => 'x',
				),
				array()
			)
		);
		$this->assertSame(
			'Signed out of ecomweb. Sign in again.',
			Messages::error(
				array(
					'code'    => 'SIGNED_OUT',
					'message' => 'x',
				)
			)
		);
	}

	/**
	 * Every code in the contract and every local code has its own text.
	 *
	 * @dataProvider codes
	 * @param string $kind warning|error.
	 * @param string $code Code.
	 */
	public function test_every_code_is_mapped( string $kind, string $code ) {
		$item = array(
			'code'    => $code,
			'message' => 'RAW-FALLBACK',
		);
		$text = 'warning' === $kind ? Messages::warning( $item, array( 'regular_price' => '10.00' ) ) : Messages::error( $item );
		$this->assertNotSame( 'RAW-FALLBACK', $text );
		$this->assertNotSame( '', $text );
	}

	/**
	 * Codes.
	 *
	 * @return array
	 */
	public function codes(): array {
		$out = array();
		foreach ( array( 'ON_SALE_REGULAR_PRICE_NOT_COPIED', 'IMAGE_FAILED', 'IMAGES_TRUNCATED', 'NEEDS_CATEGORY', 'SLUG_SUFFIXED', 'CONTENT_REMOVED', 'VARIANT_DEACTIVATED' ) as $code ) {
			$out[ $code ] = array( 'warning', $code );
		}
		foreach ( array( 'UNSUPPORTED_PRODUCT_TYPE', 'TOO_MANY_VARIANT_AXES', 'TOO_MANY_VARIANTS', 'ANY_ATTRIBUTE_WITHOUT_VALUES', 'INTERNAL', 'BATCH_REJECTED', 'PLAN_LIMIT_EXCEEDED', 'WOOCOMMERCE_CURRENCY_MISMATCH', 'WOOCOMMERCE_CONNECTION_NOT_FOUND', 'SIGNED_OUT', 'NO_ACCESS', 'API_UNREACHABLE', 'JOB_TIMEOUT', 'COPY_INTERRUPTED', 'COPY_NOT_STARTED', 'WOOCOMMERCE_COPY_IN_PROGRESS', 'WOOCOMMERCE_RATE_LIMITED' ) as $code ) {
			$out[ $code ] = array( 'error', $code );
		}
		return $out;
	}

	/**
	 * The pinned on-sale text with the price as plain text.
	 */
	public function test_on_sale_with_regular_price() {
		$text = Messages::warning(
			array(
				'code'    => 'ON_SALE_REGULAR_PRICE_NOT_COPIED',
				'message' => 'x',
			),
			array( 'regular_price' => '25.00' )
		);
		$this->assertStringStartsWith( 'On sale in WooCommerce; regular price ', $text );
		$this->assertStringContainsString( '25.00', $text );
		$this->assertStringNotContainsString( '<', $text );
	}

	/**
	 * W30: a variable product has no regular price.
	 */
	public function test_on_sale_without_regular_price_uses_the_plain_sentence() {
		$text = Messages::warning(
			array(
				'code'    => 'ON_SALE_REGULAR_PRICE_NOT_COPIED',
				'message' => 'x',
			),
			array( 'regular_price' => null )
		);
		$this->assertSame( 'On sale in WooCommerce; the regular price was not copied', $text );
	}

	/**
	 * The batch text includes the API reason.
	 */
	public function test_batch_rejected_includes_reason() {
		$this->assertSame(
			'ecomweb rejected this batch: bad price',
			Messages::error(
				array(
					'code'    => 'BATCH_REJECTED',
					'message' => 'bad price',
				)
			)
		);
	}

	/**
	 * The HTTP_<status> fallback shows the message; a missing message still gives text.
	 */
	public function test_http_fallback_and_empty() {
		$this->assertSame(
			'Nope',
			Messages::error(
				array(
					'code'    => 'HTTP_418',
					'message' => 'Nope',
				)
			)
		);
		$this->assertNotSame(
			'',
			Messages::error(
				array(
					'code'    => 'HTTP_418',
					'message' => '',
				)
			)
		);
	}

	/**
	 * W30: one ON_SALE per variation shows once.
	 */
	public function test_warnings_are_deduplicated() {
		$on_sale = array(
			'code'    => 'ON_SALE_REGULAR_PRICE_NOT_COPIED',
			'message' => 'x',
		);
		$list    = Messages::warnings(
			array(
				'regular_price' => null,
				'warnings'      => array(
					$on_sale,
					$on_sale,
					$on_sale,
					array(
						'code'    => 'IMAGE_FAILED',
						'message' => 'y',
					),
				),
			)
		);
		$this->assertCount( 2, $list );
	}
}
