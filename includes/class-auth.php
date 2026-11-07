<?php
/**
 * All ecomweb authentication: device-code sign-in, token refresh, clone detection, sign-out.
 *
 * Nothing else reads the session option.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Auth
 */
class Auth {

	const CLIENT            = 'wordpress';
	const REFRESH_MARGIN    = 60;
	const DEVICE_TRANSIENT  = 'ecomweb_device_';
	const CONNECTION_OPTION = 'ecomweb_connection';
	const LOCK_NAME         = 'session';
	const LOCK_TTL          = 30;
	const REFRESH_TIMEOUT   = 15; // Must stay below LOCK_TTL, or a second worker could refresh the same token.
	const LOGOUT_TIMEOUT    = 5;
	const DEFAULT_INTERVAL  = 5;
	const SLOW_DOWN_STEP    = 5;
	const REASONS           = array( 'revoked', 'salts_changed', 'site_moved' );

	/**
	 * Device-token error codes: the API's own and the RFC 8628 names.
	 */
	const DEVICE_CODES = array(
		'DEVICE_AUTH_PENDING'      => 'pending',
		'authorization_pending'    => 'pending',
		'DEVICE_AUTH_SLOW_DOWN'    => 'slow_down',
		'slow_down'                => 'slow_down',
		'DEVICE_AUTH_DENIED'       => 'denied',
		'access_denied'            => 'denied',
		'DEVICE_AUTH_EXPIRED'      => 'expired',
		'DEVICE_AUTH_INVALID_CODE' => 'expired',
		'expired_token'            => 'expired',
	);

	/**
	 * HTTP wrapper.
	 *
	 * @var Http
	 */
	private $http;

	/**
	 * Session store.
	 *
	 * @var Session_Store
	 */
	private $store;

	/**
	 * Mutex.
	 *
	 * @var Lock
	 */
	private $lock;

	/**
	 * Longest wait for the session lock, in seconds.
	 *
	 * @var float
	 */
	private $lock_timeout;

	/**
	 * Constructor.
	 *
	 * @param Http          $http         HTTP wrapper.
	 * @param Session_Store $store        Session store.
	 * @param Lock          $lock         Mutex.
	 * @param float         $lock_timeout Longest wait for the session lock.
	 */
	public function __construct( Http $http, Session_Store $store, Lock $lock, float $lock_timeout = 15.0 ) {
		$this->http         = $http;
		$this->store        = $store;
		$this->lock         = $lock;
		$this->lock_timeout = $lock_timeout;
	}

	/**
	 * Starts a device-code sign-in for a WordPress user. The device code never leaves the server.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|\WP_Error user_code, verification_uri_complete, interval, expires_in.
	 */
	public function start_device_flow( int $user_id ) {
		$response = $this->http->request(
			'POST',
			Config::api_url() . '/auth/device/code',
			array(
				'json' => array(
					'client'      => self::CLIENT,
					'device_name' => mb_substr( 'WooCommerce – ' . wp_parse_url( home_url(), PHP_URL_HOST ), 0, 100 ),
					// EcomWeb sends the browser back to this page after Approve.
					'return_url'  => Admin_Page::url(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->unreachable();
		}
		if ( ! $response->ok() ) {
			return $this->api_error( $response );
		}

		$json        = $response->json;
		$device_code = $json['device_code'] ?? null;
		$user_code   = $json['user_code'] ?? null;
		$uri         = $json['verification_uri_complete'] ?? ( $json['verification_uri'] ?? null );
		if ( ! is_string( $device_code ) || '' === $device_code || ! is_string( $user_code ) || ! is_string( $uri ) ) {
			return $this->bad_response();
		}
		$uri        = $this->trusted_verification_uri( $uri, $user_code );
		$interval   = max( 1, (int) ( $json['interval'] ?? self::DEFAULT_INTERVAL ) );
		$expires_in = max( 1, (int) ( $json['expires_in'] ?? 0 ) );

		set_transient(
			self::DEVICE_TRANSIENT . $user_id,
			array(
				'device_code'  => $device_code,
				'interval'     => $interval,
				'expires_at'   => time() + $expires_in,
				'next_poll_at' => time() + $interval,
			),
			$expires_in
		);

		return array(
			'user_code'                 => $user_code,
			'verification_uri_complete' => $uri,
			'interval'                  => $interval,
			'expires_in'                => $expires_in,
		);
	}

	/**
	 * The verification link the browser may open: only one on the dashboard's own origin.
	 *
	 * Anything else is replaced by a link built from the dashboard URL, and the replacement is
	 * announced on `ecomweb_verification_uri_replaced` (no URL, no tokens).
	 *
	 * @param string $uri       Link from the API.
	 * @param string $user_code User code.
	 * @return string
	 */
	private function trusted_verification_uri( string $uri, string $user_code ): string {
		$expected = self::origin( Config::dashboard_url() );
		$parts    = wp_parse_url( $uri );
		if ( is_array( $parts ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && '' !== $expected && self::origin( $uri ) === $expected ) {
			return $uri;
		}
		do_action( 'ecomweb_verification_uri_replaced' );
		return Config::dashboard_url() . '/device?code=' . rawurlencode( $user_code );
	}

	/**
	 * Scheme, host and port of a URL, lowercased, with default ports made explicit.
	 *
	 * @param string $url URL.
	 * @return string Empty when it has no http(s) scheme or host.
	 */
	private static function origin( string $url ): string {
		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		$host   = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Polls a device-code sign-in. Calls the API at most once per interval.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|\WP_Error status (pending|approved|denied|expired), interval, email.
	 */
	public function poll_device_flow( int $user_id ) {
		$key     = self::DEVICE_TRANSIENT . $user_id;
		$pending = get_transient( $key );

		if ( ! is_array( $pending ) || empty( $pending['device_code'] ) || (int) ( $pending['expires_at'] ?? 0 ) <= time() ) {
			delete_transient( $key );
			// Another tab redeemed the code already (EcomWeb sends the approving tab back here).
			if ( false === $pending && $this->is_signed_in() ) {
				return $this->poll_result( 'approved', self::DEFAULT_INTERVAL, $this->email() );
			}
			return $this->poll_result( 'expired', is_array( $pending ) ? (int) ( $pending['interval'] ?? self::DEFAULT_INTERVAL ) : self::DEFAULT_INTERVAL );
		}

		$interval = (int) $pending['interval'];
		if ( time() < (int) $pending['next_poll_at'] ) {
			return $this->poll_result( 'pending', $interval );
		}

		$pending['next_poll_at'] = time() + $interval;
		$this->save_device_transient( $key, $pending );

		$response = $this->http->request(
			'POST',
			Config::api_url() . '/auth/device/token',
			array( 'json' => array( 'device_code' => (string) $pending['device_code'] ) )
		);
		if ( is_wp_error( $response ) ) {
			return $this->unreachable();
		}

		if ( $response->ok() ) {
			if ( ! $this->save_tokens( (array) $response->json, self::normalise_home( self::home() ) ) ) {
				return $this->bad_response();
			}
			delete_transient( $key );
			delete_option( Session_Store::REASON_OPTION );
			if ( false !== get_option( self::CONNECTION_OPTION, false ) ) {
				do_action( 'ecomweb_signed_in' );
			}
			return $this->poll_result( 'approved', $interval, $this->email() );
		}

		$outcome = self::DEVICE_CODES[ (string) $response->error_code() ] ?? null;
		if ( null === $outcome && isset( $response->json['error'] ) && is_string( $response->json['error'] ) ) {
			$outcome = self::DEVICE_CODES[ $response->json['error'] ] ?? null;
		}

		switch ( $outcome ) {
			case 'pending':
				return $this->poll_result( 'pending', $interval );
			case 'slow_down':
				$interval               += self::SLOW_DOWN_STEP;
				$pending['interval']     = $interval;
				$pending['next_poll_at'] = time() + $interval;
				$this->save_device_transient( $key, $pending );
				return $this->poll_result( 'pending', $interval );
			case 'denied':
			case 'expired':
				delete_transient( $key );
				return $this->poll_result( $outcome, $interval );
		}

		return $response->status >= 500 ? $this->unreachable() : $this->api_error( $response );
	}

	/**
	 * A valid access token, refreshed under the session lock when it is expiring or $force is set.
	 *
	 * @param bool $force Refresh even if the token is not expiring.
	 * @return string|\WP_Error
	 */
	public function access_token( bool $force = false ) {
		if ( $this->check_site_moved() ) {
			return $this->signed_out_error( 'site_moved' );
		}

		$seen = $this->store->read();
		if ( null === $seen ) {
			return $this->not_signed_in();
		}
		// Tokens only ever go to the configured API: never send or refresh them anywhere else.
		if ( ( $seen['api_url'] ?? null ) !== Config::api_url() ) {
			$this->store->delete();
			return $this->not_signed_in();
		}
		if ( ! $force && ! self::expiring( $seen ) ) {
			return (string) $seen['access_token'];
		}

		$token = $this->lock->acquire( self::LOCK_NAME, self::LOCK_TTL, $this->lock_timeout );
		if ( null === $token ) {
			return $this->busy();
		}

		try {
			// Re-read: another request may have refreshed while this one waited.
			$latest = $this->store->read();
			if ( null === $latest ) {
				return $this->not_signed_in();
			}
			if ( ( $latest['access_token'] ?? null ) !== ( $seen['access_token'] ?? null ) && ! self::expiring( $latest ) ) {
				return (string) $latest['access_token'];
			}
			if ( ! $force && ! self::expiring( $latest ) ) {
				return (string) $latest['access_token'];
			}

			$response = $this->http->request(
				'POST',
				Config::api_url() . '/auth/session/refresh',
				array(
					'json'    => array( 'refresh_token' => (string) ( $latest['refresh_token'] ?? '' ) ),
					'timeout' => self::REFRESH_TIMEOUT,
				)
			);
			if ( is_wp_error( $response ) || $response->status >= 500 ) {
				return $this->unreachable();
			}
			// Sign-out doesn't take the lock: if it landed while the request was in flight, the
			// answer belongs to a session that no longer exists. Discard it; never write it back.
			$current = $this->store->read();
			if ( null === $current || ( $current['refresh_token'] ?? null ) !== ( $latest['refresh_token'] ?? null ) ) {
				return $this->not_signed_in();
			}
			if ( $response->ok() ) {
				if ( ! $this->save_tokens( (array) $response->json, (string) ( $latest['signed_in_home_url'] ?? '' ), $latest ) ) {
					return $this->busy();
				}
				return (string) $response->json['access_token'];
			}
			if ( 429 === $response->status ) {
				return $this->busy();
			}
			if ( $response->status >= 400 ) {
				// A non-2xx body is never a session, and a rotated refresh token can't be reused (W9).
				$this->sign_out_local( 'revoked' );
				return $this->signed_out_error( 'revoked' );
			}
			return $this->unreachable();
		} finally {
			$this->lock->release( self::LOCK_NAME, $token );
		}
	}

	/**
	 * Drops the session, without any request, when the site's address changed since sign-in.
	 *
	 * The site UUID, the connection and all product meta stay in place.
	 *
	 * @return bool Whether the site moved.
	 */
	public function check_site_moved(): bool {
		$session = $this->store->read();
		if ( null === $session || ! $this->moved( $session ) ) {
			return false;
		}
		$this->sign_out_local( 'site_moved' );
		return true;
	}

	/**
	 * Lowercased host[:port] plus the path without its trailing slash. The scheme is ignored.
	 *
	 * @param string $url A URL, or an already normalised value.
	 * @return string
	 */
	public static function normalise_home( string $url ): string {
		$url = trim( $url );
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'http://' . ltrim( $url, '/' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $host . $port . untrailingslashit( (string) ( $parts['path'] ?? '' ) );
	}

	/**
	 * Whether a usable session exists for this site and the configured API.
	 *
	 * @return bool
	 */
	public function is_signed_in(): bool {
		if ( $this->check_site_moved() ) {
			return false;
		}
		$session = $this->store->read();
		return null !== $session && ( $session['api_url'] ?? null ) === Config::api_url();
	}

	/**
	 * The signed-in merchant's email, if known.
	 *
	 * @return string|null
	 */
	public function email(): ?string {
		$session = $this->store->read();
		return null !== $session && isset( $session['email'] ) && is_string( $session['email'] ) ? $session['email'] : null;
	}

	/**
	 * Why the merchant was signed out without asking.
	 *
	 * @return string|null revoked, salts_changed, site_moved or null.
	 */
	public function signed_out_reason(): ?string {
		$reason = get_option( Session_Store::REASON_OPTION, false );
		return is_string( $reason ) && in_array( $reason, self::REASONS, true ) ? $reason : null;
	}

	/**
	 * Forgets the signed-out reason.
	 *
	 * @return void
	 */
	public function clear_signed_out_reason(): void {
		delete_option( Session_Store::REASON_OPTION );
	}

	/**
	 * Signs out at the merchant's request: revokes the refresh token (best effort) and deletes the session.
	 *
	 * After a move nothing is sent: the tokens belong to the original site.
	 *
	 * @return void
	 */
	public function sign_out(): void {
		$session = $this->store->read();
		if ( null !== $session && ! $this->moved( $session ) && ( $session['api_url'] ?? null ) === Config::api_url() && ! empty( $session['refresh_token'] ) ) {
			$this->http->request(
				'POST',
				Config::api_url() . '/auth/logout',
				array(
					'json'    => array( 'refresh_token' => (string) $session['refresh_token'] ),
					'timeout' => self::LOGOUT_TIMEOUT,
				)
			);
		}
		$this->store->delete();
	}

	/**
	 * Deletes the session without any request and records why.
	 *
	 * @param string $reason revoked, salts_changed or site_moved.
	 * @return void
	 */
	public function sign_out_local( string $reason ): void {
		$this->store->delete();
		update_option( Session_Store::REASON_OPTION, $reason, false );
	}

	/**
	 * Saves a TokenResponse as the session, only if it carries both tokens (W9).
	 *
	 * @param array      $json               TokenResponse.
	 * @param string     $signed_in_home_url Normalised home URL at sign-in.
	 * @param array|null $previous           Session being refreshed, for fields the response lacks.
	 * @return bool Whether it was saved.
	 */
	private function save_tokens( array $json, string $signed_in_home_url, ?array $previous = null ): bool {
		$access  = $json['access_token'] ?? null;
		$refresh = $json['refresh_token'] ?? null;
		if ( ! is_string( $access ) || '' === $access || ! is_string( $refresh ) || '' === $refresh ) {
			return false;
		}

		$user       = isset( $json['user'] ) && is_array( $json['user'] ) ? $json['user'] : array();
		$expires_at = isset( $json['expires_at'] ) && is_numeric( $json['expires_at'] )
			? (int) $json['expires_at']
			: time() + (int) ( $json['expires_in'] ?? 0 );

		$this->store->write(
			array(
				'access_token'       => $access,
				'refresh_token'      => $refresh,
				'expires_at'         => $expires_at,
				'email'              => array_key_exists( 'email', $user ) ? ( is_string( $user['email'] ) ? $user['email'] : null ) : ( $previous['email'] ?? null ),
				'user_id'            => isset( $user['id'] ) && is_string( $user['id'] ) ? $user['id'] : ( $previous['user_id'] ?? null ),
				'api_url'            => Config::api_url(),
				'signed_in_home_url' => $signed_in_home_url,
			)
		);
		return true;
	}

	/**
	 * Whether the session's home differs from the current one.
	 *
	 * @param array $session Session.
	 * @return bool
	 */
	private function moved( array $session ): bool {
		return self::normalise_home( (string) ( $session['signed_in_home_url'] ?? '' ) ) !== self::normalise_home( self::home() );
	}

	/**
	 * The site's home address from the `home` option. Not home_url(): plugins filter that per
	 * request (WPML language folders, multi-domain), which would look like a move. A clone or a
	 * move changes the option or WP_HOME, and both show here.
	 *
	 * @return string
	 */
	public static function home(): string {
		return (string) get_option( 'home' );
	}

	/**
	 * Whether the access token expires within the margin.
	 *
	 * @param array $session Session.
	 * @return bool
	 */
	private static function expiring( array $session ): bool {
		return (int) ( $session['expires_at'] ?? 0 ) - self::REFRESH_MARGIN <= time();
	}

	/**
	 * Saves the device transient for the rest of its lifetime.
	 *
	 * @param string $key     Transient key.
	 * @param array  $pending Transient value.
	 * @return void
	 */
	private function save_device_transient( string $key, array $pending ): void {
		set_transient( $key, $pending, max( 1, (int) $pending['expires_at'] - time() ) );
	}

	/**
	 * A poll result.
	 *
	 * @param string      $status   Status.
	 * @param int         $interval Seconds between polls.
	 * @param string|null $email    Email, when approved.
	 * @return array
	 */
	private function poll_result( string $status, int $interval, ?string $email = null ): array {
		return array(
			'status'   => $status,
			'interval' => $interval,
			'email'    => $email,
		);
	}

	/**
	 * Signed out without asking.
	 *
	 * @param string $reason Reason.
	 * @return \WP_Error
	 */
	private function signed_out_error( string $reason ): \WP_Error {
		return new \WP_Error( 'ecomweb_signed_out', __( 'You were signed out of EcomWeb. Sign in again.', 'ecomweb-for-woocommerce' ), array( 'reason' => $reason ) );
	}

	/**
	 * Not signed in.
	 *
	 * @return \WP_Error
	 */
	private function not_signed_in(): \WP_Error {
		return new \WP_Error( 'ecomweb_not_signed_in', __( 'Sign in to EcomWeb first.', 'ecomweb-for-woocommerce' ) );
	}

	/**
	 * Lock timeout, rate limit or unusable answer: try again later.
	 *
	 * @return \WP_Error
	 */
	private function busy(): \WP_Error {
		return new \WP_Error( 'ecomweb_busy', __( 'EcomWeb is busy. Try again in a moment.', 'ecomweb-for-woocommerce' ) );
	}

	/**
	 * Network failure or 5xx.
	 *
	 * @return \WP_Error
	 */
	private function unreachable(): \WP_Error {
		return new \WP_Error( 'ecomweb_unreachable', __( 'Could not reach EcomWeb. Try again in a moment.', 'ecomweb-for-woocommerce' ) );
	}

	/**
	 * A 2xx without the expected fields.
	 *
	 * @return \WP_Error
	 */
	private function bad_response(): \WP_Error {
		return new \WP_Error( 'ecomweb_bad_response', __( 'EcomWeb sent an unexpected answer. Try again.', 'ecomweb-for-woocommerce' ) );
	}

	/**
	 * Any other API refusal.
	 *
	 * @param Http_Response $response Response.
	 * @return \WP_Error
	 */
	private function api_error( Http_Response $response ): \WP_Error {
		return new \WP_Error(
			'ecomweb_api_error',
			$response->error_message(),
			array(
				'status' => $response->status,
				'code'   => $response->error_code(),
			)
		);
	}
}
