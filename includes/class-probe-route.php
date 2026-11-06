<?php
/**
 * Public probe: lets the ecomweb API check that it can reach this site (to fetch images from it).
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Probe_Route
 */
class Probe_Route {

	const NAMESPACE_ = 'ecomweb/v1';
	const ROUTE      = '/probe';

	/**
	 * Hooks the route.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Registers GET ecomweb/v1/probe.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE_,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Public on purpose: the API calls it without credentials. It answers the site UUID only.
			)
		);
	}

	/**
	 * The answer: the site UUID and nothing else.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle(): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'site_uuid' => Plugin::site_uuid() ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
