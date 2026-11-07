<?php
/**
 * Preflight tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Preflight;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Preflight
 */
class Test_Preflight extends WP_UnitTestCase {

	const PATH = '/stores/s1/integrations/woocommerce/preflight';

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Set up: signed in and connected.
	 */
	public function set_up() {
		parent::set_up();
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT',
				'refresh_token'      => 'RT',
				'expires_at'         => time() + 3600,
				'email'              => 'merchant@example.com',
				'user_id'            => 'u1',
				'api_url'            => Config::api_url(),
				'signed_in_home_url' => Auth::normalise_home( home_url() ),
			)
		);
		$this->fake = Fake_Api::start();
		$this->fake->on(
			'POST',
			'/stores/s1/integrations/woocommerce/connections',
			200,
			array(
				'connection_id' => 'c1',
				'images_mode'   => 'upload',
				'store'         => array(
					'id'       => 's1',
					'ref'      => 's1',
					'name'     => 'Shop One',
					'currency' => 'VND',
				),
			)
		);
		Plugin::instance()->connection()->register( 's1' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		parent::tear_down();
	}

	/**
	 * Preflight under test.
	 *
	 * @return Preflight
	 */
	private function preflight(): Preflight {
		return new Preflight( Plugin::instance()->api(), Plugin::instance()->connection() );
	}

	/**
	 * A fake answer for some IDs.
	 *
	 * @param int[]  $ids   IDs.
	 * @param string $state State of each.
	 * @param array  $extra Extra top-level keys.
	 * @return array
	 */
	private function answer( array $ids, string $state = 'new', array $extra = array() ): array {
		$products = array();
		foreach ( $ids as $id ) {
			$products[] = array(
				'wc_product_id'      => $id,
				'state'              => $state,
				'ecomweb_product_id' => null,
			);
		}
		return array_merge(
			array(
				'products'    => $products,
				'plan'        => array(
					'limit'     => 100,
					'current'   => 10,
					'new_count' => count( $ids ),
					'fits'      => count( $ids ),
				),
				'storage'     => null,
				'currency'    => null,
				'upgrade_url' => null,
			),
			$extra
		);
	}

	/**
	 * The request body.
	 */
	public function test_sends_connection_and_ids() {
		$this->fake->on( 'POST', self::PATH, 200, $this->answer( array( 1, 2, 3 ) ) );
		$result = $this->preflight()->run( array( 1, 2, 3 ) );

		$this->assertIsArray( $result );
		$calls = $this->fake->calls( self::PATH );
		$this->assertCount( 1, $calls );
		$this->assertSame(
			array(
				'connection_id'  => 'c1',
				'wc_product_ids' => array( 1, 2, 3 ),
			),
			json_decode( $calls[0]['body'], true )
		);
	}

	/**
	 * 5001 IDs: two calls, merged.
	 */
	public function test_chunks_over_5000_and_merges() {
		$ids                      = range( 1, 5001 );
		$first                    = $this->answer(
			array_slice( $ids, 0, 5000 ),
			'new',
			array(
				'storage' => array(
					'limit_mb' => 100,
					'used_mb'  => 5,
				),
			) // phpcs:ignore Generic.Arrays.MultipleStatementAlignment
		);
		$first['plan']['limit']   = 6000;
		$first['plan']['current'] = 7;
		$last                     = $this->answer(
			array( 5001 ),
			'new',
			array(
				'currency'    => array(
					'store'       => 'VND',
					'woocommerce' => 'VND',
					'matches'     => true,
				),
				'upgrade_url' => 'https://ecomweb.app/store/s1/settings/subscription',
			)
		);
		$last['plan']['limit']    = 1;
		$this->fake->on( 'POST', self::PATH, 200, $first );
		$this->fake->on( 'POST', self::PATH, 200, $last );

		$result = $this->preflight()->run( $ids );

		$calls = $this->fake->calls( self::PATH );
		$this->assertCount( 2, $calls );
		$this->assertCount( 5000, json_decode( $calls[0]['body'], true )['wc_product_ids'] );
		$this->assertCount( 1, json_decode( $calls[1]['body'], true )['wc_product_ids'] );
		$this->assertCount( 5001, $result['products'] );
		$this->assertSame( 5001, $result['plan']['new_count'] );
		$this->assertSame( 6000, $result['plan']['limit'] );
		$this->assertSame( 7, $result['plan']['current'] );
		$this->assertSame( 5, $result['storage']['used_mb'] );
		$this->assertTrue( $result['currency']['matches'] );
		$this->assertSame( 'https://ecomweb.app/store/s1/settings/subscription', $result['upgrade_url'] );
	}

	/**
	 * A product deleted in ecomweb loses its local copied meta.
	 */
	public function test_deleted_in_ecomweb_clears_local_meta() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Tee' );
		$id = $product->save();
		update_post_meta( $id, '_ecomweb_product_id', 'E1' );
		$this->fake->on( 'POST', self::PATH, 200, $this->answer( array( $id ), 'deleted_in_ecomweb' ) );

		$this->preflight()->run( array( $id ) );

		$this->assertSame( '', get_post_meta( $id, '_ecomweb_product_id', true ) );
	}

	/**
	 * A changed currency re-registers before the preflight call (W22).
	 */
	public function test_changed_currency_reregisters_before_preflight() {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->fake->on( 'POST', self::PATH, 200, $this->answer( array( 1 ) ) );

		$this->preflight()->run( array( 1 ) );

		$this->assertSame( 2, $this->fake->count( '/stores/s1/integrations/woocommerce/connections' ) );
		$calls = $this->fake->calls();
		$last  = array_slice( $calls, -2 );
		$this->assertStringEndsWith( '/connections', $last[0]['url'] );
		$this->assertStringEndsWith( '/preflight', $last[1]['url'] );
		$this->assertSame( 'EUR', json_decode( $last[0]['body'], true )['woocommerce_currency'] );
	}

	/**
	 * An API error comes back as a WP_Error.
	 */
	public function test_api_error_is_returned() {
		$this->fake->on( 'POST', self::PATH, 500, array( 'message' => 'Boom' ) );
		$this->assertWPError( $this->preflight()->run( array( 1 ) ) );
	}
}
