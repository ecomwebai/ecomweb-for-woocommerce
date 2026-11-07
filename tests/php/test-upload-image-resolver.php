<?php
/**
 * Upload_Image_Resolver tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Api_Client;
use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Http;
use Ecomweb\Woo\Lock;
use Ecomweb\Woo\Product_Serializer;
use Ecomweb\Woo\Session_Store;
use Ecomweb\Woo\Upload_Image_Resolver;

/**
 * Class Test_Upload_Image_Resolver
 */
class Test_Upload_Image_Resolver extends WP_UnitTestCase {

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Attachment ID.
	 *
	 * @var int
	 */
	private $att;

	/**
	 * Set up: signed in, one attachment.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'option_home', '_config_wp_home' );
		remove_filter( 'option_siteurl', '_config_wp_siteurl' );
		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );
		update_option( 'uploads_use_yearmonth_folders', 0 );
		$this->fake = Fake_Api::start();
		$http       = new Http();
		$this->api  = new Api_Client( new Auth( $http, new Session_Store(), new Lock() ), $http );
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT',
				'refresh_token'      => 'RT',
				'expires_at'         => time() + 600,
				'email'              => 'm@shop.vn',
				'user_id'            => 'u1',
				'api_url'            => Config::api_url(),
				'signed_in_home_url' => 'example.org',
			)
		);
		$this->att = Product_Factory::attachment( 'tee-front.png' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		parent::tear_down();
	}

	/**
	 * Resolver for a connection.
	 *
	 * @param string   $connection Connection ID.
	 * @param int|null $deadline   Deadline.
	 * @param int[]    $failed     Known failed.
	 * @return Upload_Image_Resolver
	 */
	private function resolver( string $connection = 'c1', ?int $deadline = null, array $failed = array() ) {
		return new Upload_Image_Resolver( $this->api, 's1', $connection, $deadline, $failed );
	}

	/**
	 * One upload per connection; the asset ID is cached.
	 */
	public function test_uploads_once_and_caches_per_connection() {
		$this->fake->on( 'POST', '/stores/s1/assets/upload', 201, array( 'asset' => array( 'id' => 'as_1' ) ) );
		$r = $this->resolver();
		$this->assertSame(
			array(
				'attachment_id' => $this->att,
				'asset_id'      => 'as_1',
			),
			$r->resolve( $this->att, 7 )
		);
		$this->assertSame( 'as_1', get_post_meta( $this->att, '_ecomweb_asset_c1', true ) );
		$r->resolve( $this->att, 7 );
		$this->assertSame( 1, $this->fake->count( '/assets/upload' ) );
	}

	/**
	 * Another connection has its own cache.
	 */
	public function test_other_connection_uploads_again() {
		$this->fake->on( 'POST', '/stores/s1/assets/upload', 201, array( 'asset' => array( 'id' => 'as_1' ) ) );
		$this->resolver( 'c1' )->resolve( $this->att, 7 );
		$this->resolver( 'c2' )->resolve( $this->att, 7 );
		$this->assertSame( 2, $this->fake->count( '/assets/upload' ) );
	}

	/**
	 * A bare `{id}` response works.
	 */
	public function test_accepts_bare_id_response() {
		$this->fake->on( 'POST', '/stores/s1/assets/upload', 201, array( 'id' => 'as_2' ) );
		$image = $this->resolver()->resolve( $this->att, 7 );
		$this->assertSame( 'as_2', $image['asset_id'] );
	}

	/**
	 * A failed upload gives null and a warning, and nothing is cached.
	 */
	public function test_failed_upload_returns_null_with_warning() {
		$this->fake->on( 'POST', '/stores/s1/assets/upload', 500, array( 'code' => 'INTERNAL' ) );
		$r = $this->resolver();
		$this->assertNull( $r->resolve( $this->att, 7 ) );
		$this->assertSame( 'IMAGE_FAILED', $r->warnings( 7 )[0]['code'] );
		$this->assertSame( '', get_post_meta( $this->att, '_ecomweb_asset_c1', true ) );
		$this->assertSame( array( $this->att ), $r->failed_ids() );
	}

	/**
	 * A transport error is a failure too.
	 */
	public function test_transport_error_returns_null_with_warning() {
		$this->fake->fail( 'POST', '/stores/s1/assets/upload', 'http_request_failed' );
		$r = $this->resolver();
		$this->assertNull( $r->resolve( $this->att, 7 ) );
		$this->assertSame( 'IMAGE_FAILED', $r->warnings( 7 )[0]['code'] );
	}

	/**
	 * A file over 10 MB is skipped without a request.
	 */
	public function test_file_over_10mb_skipped_without_request() {
		$big = wp_tempnam( 'big.png' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$h = fopen( $big, 'wb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		ftruncate( $h, 11 * 1024 * 1024 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $h );
		$filter = function () use ( $big ) {
			return $big;
		};
		add_filter( 'get_attached_file', $filter );
		$r = $this->resolver();
		$this->assertNull( $r->resolve( $this->att, 7 ) );
		remove_filter( 'get_attached_file', $filter );
		wp_delete_file( $big );
		$this->assertSame( 'IMAGE_FAILED', $r->warnings( 7 )[0]['code'] );
		$this->assertSame( 0, $this->fake->count( '/assets/upload' ) );
	}

	/**
	 * Missing or non-image attachments warn without a request.
	 */
	public function test_missing_attachment_warns() {
		$r = $this->resolver();
		$this->assertNull( $r->resolve( 999999, 7 ) );
		$this->assertSame( 'IMAGE_FAILED', $r->warnings( 7 )[0]['code'] );
		$this->assertSame( 0, $this->fake->count( '/assets/upload' ) );
	}

	/**
	 * Past the deadline: null, out_of_time, no warning, no request.
	 */
	public function test_past_deadline_returns_null_and_flags_out_of_time() {
		$r = $this->resolver( 'c1', time() - 1 );
		$this->assertFalse( $r->out_of_time() );
		$this->assertNull( $r->resolve( $this->att, 7 ) );
		$this->assertTrue( $r->out_of_time() );
		$this->assertSame( array(), $r->warnings( 7 ) );
		$this->assertSame( 0, $this->fake->count( '/assets/upload' ) );
	}

	/**
	 * A cached image is returned even past the deadline.
	 */
	public function test_cached_image_ignores_deadline() {
		update_post_meta( $this->att, '_ecomweb_asset_c1', 'as_9' );
		$r = $this->resolver( 'c1', time() - 1 );
		$this->assertSame( 'as_9', $r->resolve( $this->att, 7 )['asset_id'] );
		$this->assertFalse( $r->out_of_time() );
	}

	/**
	 * A multipart body with the catalog category and an ASCII-safe filename.
	 */
	public function test_upload_body_has_catalog_category_and_ascii_filename() {
		$this->fake->on( 'POST', '/stores/s1/assets/upload', 201, array( 'asset' => array( 'id' => 'as_1' ) ) );
		$this->resolver()->resolve( $this->att, 7 );
		$call = $this->fake->calls( '/assets/upload' )[0];
		$this->assertStringContainsString( 'name="asset_category"', $call['body'] );
		$this->assertStringContainsString( 'catalog', $call['body'] );
		$this->assertStringContainsString( 'name="file"; filename="' . $this->att . '.png"', $call['body'] );
		$this->assertStringContainsString( 'Content-Type: image/png', $call['body'] );
	}

	/**
	 * W23: an image that failed earlier in this copy isn't retried.
	 */
	public function test_failed_upload_not_retried_within_same_copy() {
		$this->fake->on( 'POST', '/stores/s1/assets/upload', 500, array() );
		$first = $this->resolver();
		$this->assertNull( $first->resolve( $this->att, 7 ) );
		$this->assertSame( 1, $this->fake->count( '/assets/upload' ) );

		$second = $this->resolver( 'c1', null, $first->failed_ids() );
		$this->assertNull( $second->resolve( $this->att, 7 ) );
		$this->assertSame( 1, $this->fake->count( '/assets/upload' ) );
		$this->assertSame( 'IMAGE_FAILED', $second->warnings( 7 )[0]['code'] );
		$this->assertSame( array( $this->att ), $second->failed_ids() );
	}

	/**
	 * The serialiser puts asset IDs in the payload.
	 */
	public function test_serialised_product_uses_asset_ids() {
		$this->fake->on( 'POST', '/stores/s1/assets/upload', 201, array( 'asset' => array( 'id' => 'as_1' ) ) );
		$product = new WC_Product_Simple();
		$product->set_name( 'Tee' );
		$product->set_regular_price( '10.00' );
		$product->set_image_id( $this->att );
		$product->save();

		$data = ( new Product_Serializer() )->serialize( wc_get_product( $product->get_id() ), $this->resolver() );
		$this->assertSame(
			array(
				'attachment_id' => $this->att,
				'asset_id'      => 'as_1',
			),
			$data['images'][0]
		);
	}
}
