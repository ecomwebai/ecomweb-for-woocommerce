<?php
/**
 * Upload mode: the plugin uploads each image to ecomweb and sends the asset ID.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Upload_Image_Resolver
 */
class Upload_Image_Resolver implements Image_Resolver {

	const MAX_BYTES = 10 * 1024 * 1024;
	const META_KEY  = '_ecomweb_asset_';

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Store ref.
	 *
	 * @var string
	 */
	private $store_ref;

	/**
	 * Connection ID (scopes the asset cache).
	 *
	 * @var string
	 */
	private $connection_id;

	/**
	 * Unix time after which no new upload starts, or null.
	 *
	 * @var int|null
	 */
	private $deadline;

	/**
	 * Attachment IDs whose upload failed during this copy.
	 *
	 * @var int[]
	 */
	private $failed;

	/**
	 * Whether the deadline stopped an upload.
	 *
	 * @var bool
	 */
	private $out_of_time = false;

	/**
	 * Warnings by product ID.
	 *
	 * @var array
	 */
	private $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param Api_Client $api           API client.
	 * @param string     $store_ref     Store ref.
	 * @param string     $connection_id Connection ID.
	 * @param int|null   $deadline_unix Stop starting uploads after this time.
	 * @param int[]      $known_failed  Attachments that already failed in this copy.
	 */
	public function __construct( Api_Client $api, string $store_ref, string $connection_id, ?int $deadline_unix = null, array $known_failed = array() ) {
		$this->api           = $api;
		$this->store_ref     = $store_ref;
		$this->connection_id = $connection_id;
		$this->deadline      = $deadline_unix;
		$this->failed        = array_values( array_unique( array_map( 'intval', $known_failed ) ) );
	}

	/**
	 * WooImage `{attachment_id, asset_id}`, or null when the image can't be used now.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $product_id    Product ID (for warnings).
	 * @return array|null
	 */
	public function resolve( int $attachment_id, int $product_id ): ?array {
		$key    = self::META_KEY . $this->connection_id;
		$cached = get_post_meta( $attachment_id, $key, true );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $this->image( $attachment_id, $cached );
		}

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
			return $this->fail( $product_id, __( 'An image could not be copied because it is missing or is not an image.', 'ecomweb-for-woocommerce' ) );
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return $this->fail( $product_id, __( 'An image could not be copied because its file could not be read.', 'ecomweb-for-woocommerce' ) );
		}
		if ( filesize( $file ) > self::MAX_BYTES ) {
			return $this->fail( $product_id, __( 'An image could not be copied because it is larger than 10 MB.', 'ecomweb-for-woocommerce' ) );
		}
		if ( in_array( $attachment_id, $this->failed, true ) ) {
			return $this->fail( $product_id, __( 'An image could not be uploaded.', 'ecomweb-for-woocommerce' ) );
		}
		if ( null !== $this->deadline && time() > $this->deadline ) {
			$this->out_of_time = true;
			return null;
		}

		$type     = wp_check_filetype( $file );
		$ext      = $type['ext'] ? $type['ext'] : 'jpg';
		$mime     = $type['type'] ? $type['type'] : 'application/octet-stream';
		$response = $this->api->upload(
			Api_Client::store_path( $this->store_ref, '/assets/upload' ),
			array( 'asset_category' => 'catalog' ),
			$file,
			$attachment_id . '.' . $ext, // ASCII-safe: the API's multipart parser may mis-decode other names.
			$mime
		);

		$asset_id = null;
		if ( $response instanceof Http_Response && $response->status >= 200 && $response->status < 300 ) {
			$json     = (array) $response->json;
			$asset_id = $json['asset']['id'] ?? ( $json['id'] ?? null );
		}
		if ( ! is_string( $asset_id ) || '' === $asset_id ) {
			$this->failed[] = $attachment_id;
			return $this->fail( $product_id, __( 'An image could not be uploaded.', 'ecomweb-for-woocommerce' ) );
		}

		update_post_meta( $attachment_id, $key, $asset_id );
		return $this->image( $attachment_id, $asset_id );
	}

	/**
	 * Whether the time budget stopped an upload.
	 *
	 * @return bool
	 */
	public function out_of_time(): bool {
		return $this->out_of_time;
	}

	/**
	 * Attachments whose upload failed, including those passed in.
	 *
	 * @return int[]
	 */
	public function failed_ids(): array {
		return array_values( array_unique( $this->failed ) );
	}

	/**
	 * IMAGE_FAILED warnings for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array[]
	 */
	public function warnings( int $product_id ): array {
		return $this->warnings[ $product_id ] ?? array();
	}

	/**
	 * WooImage.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $asset_id      Asset ID.
	 * @return array
	 */
	private function image( int $attachment_id, string $asset_id ): array {
		return array(
			'attachment_id' => $attachment_id,
			'asset_id'      => $asset_id,
		);
	}

	/**
	 * Records an IMAGE_FAILED warning and returns null.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $message    Message.
	 * @return null
	 */
	private function fail( int $product_id, string $message ) {
		$this->warnings[ $product_id ][] = array(
			'code'    => 'IMAGE_FAILED',
			'message' => $message,
		);
		return null;
	}
}
