<?php
/**
 * Runs a copy on Action Scheduler: one batch of at most 20 products per SEND, then POLL the job.
 *
 * Every action re-reads the state under the `copy` lock and exits when it belongs to another
 * copy, the copy isn't running, or (SEND) a job is already in flight. So a duplicate or stale
 * action does nothing, and only one batch is ever in flight.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Copy_Runner
 */
class Copy_Runner {

	const GROUP            = 'ecomweb-for-woocommerce';
	const SEND_HOOK        = 'ecomweb_copy_send';
	const POLL_HOOK        = 'ecomweb_copy_poll';
	const BATCH_SIZE       = 20;
	const POLL_DELAY       = 5;
	const CONFLICT_DELAY   = 30;
	const CONFLICT_TRIES   = 20;
	const THROTTLE_DELAY   = 60;
	const THROTTLE_TRIES   = 10;
	const TRANSIENT_DELAYS = array( 30, 60, 120, 240, 480 );
	const POLL_TIMEOUT     = 4500; // 75 min: the API's stale-job limit (W29).
	const UPLOAD_BUDGET    = 20;
	const BATCH_TIMEOUT    = 30;
	const TIME_LIMIT       = 120;
	const LOCK             = 'copy';
	const LOCK_TTL         = 120;
	const LOCK_TIMEOUT     = 5;
	const LOCK_BUSY_DELAY  = 10;
	const RESULTS          = array( 'created', 'updated', 'skipped', 'failed' );

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Seconds each action may spend starting image uploads.
	 *
	 * @var int
	 */
	private $upload_budget;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $api           API client.
	 * @param Connection $connection    Connection.
	 * @param int        $upload_budget Seconds each action may spend starting uploads (W27).
	 */
	public function __construct( Api_Client $api, Connection $connection, int $upload_budget = self::UPLOAD_BUDGET ) {
		$this->api           = $api;
		$this->connection    = $connection;
		$this->upload_budget = $upload_budget;
	}

	/**
	 * Hooks the handlers and the Action Scheduler failure hooks (W24).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::SEND_HOOK, array( $this, 'handle_send' ) );
		add_action( self::POLL_HOOK, array( $this, 'handle_poll' ) );
		add_action( 'action_scheduler_failed_execution', array( $this, 'on_action_failed' ) );
		add_action( 'action_scheduler_unexpected_shutdown', array( $this, 'on_action_failed' ) );
	}

	/**
	 * Starts a copy.
	 *
	 * @param int[] $queue         Product IDs to send, in order.
	 * @param bool  $overwrite     Overwrite products already copied.
	 * @param int   $not_supported Products left out before the start.
	 * @return array|\WP_Error The new state.
	 */
	public function start( array $queue, bool $overwrite, int $not_supported ) {
		$token = Lock::acquire( self::LOCK, self::LOCK_TTL, self::LOCK_TIMEOUT );
		if ( null === $token ) {
			return self::running_error();
		}
		try {
			if ( Copy_State::is_running() ) {
				return self::running_error();
			}
			$connection = $this->connection->current();
			if ( null === $connection ) {
				return new \WP_Error( 'ecomweb_not_connected', __( 'Choose an ecomweb store first.', 'ecomweb-for-woocommerce' ) );
			}

			$ids   = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', $queue ),
						function ( $id ) {
							return $id > 0;
						}
					)
				)
			);
			$state = array(
				'id'            => wp_generate_uuid4(),
				'status'        => 'running',
				'connection_id' => (string) $connection['connection_id'],
				'store_ref'     => (string) $connection['store']['ref'],
				'overwrite'     => $overwrite,
				'started_at'    => time(),
				'finished_at'   => null,
				'total'         => count( $ids ),
				'queue'         => $ids,
				'in_flight'     => null,
				'retry'         => self::no_retry(),
				'counts'        => array(
					'created'       => 0,
					'updated'       => 0,
					'skipped'       => 0,
					'failed'        => 0,
					'not_supported' => max( 0, $not_supported ),
				),
				'issues'        => array(),
				'upload_failed' => array(),
				'error'         => null,
			);
			Copy_State::put( $state );
			as_enqueue_async_action( self::SEND_HOOK, array( $state['id'] ), self::GROUP );
			return $state;
		} finally {
			Lock::release( self::LOCK, $token );
		}
	}

	/**
	 * SEND: serialises the next batch and posts it.
	 *
	 * @param string $copy_id Copy ID.
	 * @return void
	 */
	public function handle_send( $copy_id ): void {
		$this->locked(
			self::SEND_HOOK,
			(string) $copy_id,
			function ( array $state ) {
				if ( null === $state['in_flight'] ) {
					$this->send( $state );
				}
			}
		);
	}

	/**
	 * POLL: asks for the job in flight and records its results.
	 *
	 * @param string $copy_id Copy ID.
	 * @return void
	 */
	public function handle_poll( $copy_id ): void {
		$this->locked(
			self::POLL_HOOK,
			(string) $copy_id,
			function ( array $state ) {
				if ( null !== $state['in_flight'] ) {
					$this->poll( $state );
				}
			}
		);
	}

	/**
	 * Action Scheduler says one of our actions threw or died (W24). For the running copy it is
	 * retried on the transient path; after the last delay the copy fails with COPY_INTERRUPTED.
	 *
	 * @param int $action_id Action ID.
	 * @return void
	 */
	public function on_action_failed( $action_id ): void {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return;
		}
		$action = \ActionScheduler::store()->fetch_action( (int) $action_id );
		if ( ! in_array( $action->get_hook(), array( self::SEND_HOOK, self::POLL_HOOK ), true ) ) {
			return;
		}
		$args  = $action->get_args();
		$state = self::running_state( isset( $args[0] ) && is_scalar( $args[0] ) ? (string) $args[0] : '' );
		if ( null === $state ) {
			return;
		}
		$this->retry(
			$state,
			'transient',
			null === $state['in_flight'] ? self::SEND_HOOK : self::POLL_HOOK,
			'COPY_INTERRUPTED',
			__( 'The copy stopped unexpectedly on this site. Copy again; products already copied are skipped.', 'ecomweb-for-woocommerce' )
		);
	}

	/**
	 * Stops the running copy: unschedules its actions and marks it cancelled.
	 *
	 * @return void
	 */
	public static function cancel(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// Hook only: with args [] Action Scheduler would match only actions whose args are empty.
			as_unschedule_all_actions( self::SEND_HOOK );
			as_unschedule_all_actions( self::POLL_HOOK );
		}
		$state = Copy_State::get();
		if ( null !== $state && 'running' === ( $state['status'] ?? null ) ) {
			$state['status']      = 'cancelled';
			$state['finished_at'] = time();
			Copy_State::put( $state );
		}
	}

	/**
	 * Runs $work with the copy lock and the copy's running state; reschedules when the lock is busy.
	 *
	 * @param string   $hook    Hook to reschedule.
	 * @param string   $copy_id Copy ID.
	 * @param callable $work    Receives the state.
	 * @return void
	 */
	private function locked( string $hook, string $copy_id, callable $work ): void {
		wc_set_time_limit( self::TIME_LIMIT );
		$token = Lock::acquire( self::LOCK, self::LOCK_TTL, self::LOCK_TIMEOUT );
		if ( null === $token ) {
			if ( null !== self::running_state( $copy_id ) ) {
				self::schedule( $hook, $copy_id, self::LOCK_BUSY_DELAY );
			}
			return;
		}
		try {
			$state = self::running_state( $copy_id );
			if ( null !== $state ) {
				$work( $state );
			}
		} finally {
			Lock::release( self::LOCK, $token );
		}
	}

	/**
	 * Builds and posts the next batch.
	 *
	 * @param array $state Running state with nothing in flight.
	 * @return void
	 */
	private function send( array $state ): void {
		$connection = $this->connection->current();
		if ( null === $connection || (string) $connection['connection_id'] !== $state['connection_id'] ) {
			$this->fail( $state, 'WOOCOMMERCE_CONNECTION_NOT_FOUND', __( 'This site is no longer connected to that ecomweb store. Choose the store again.', 'ecomweb-for-woocommerce' ) );
			return;
		}

		// A fresh deadline per action, so every action attempts at least one upload (T8 ruling).
		$resolver   = 'upload' === ( $connection['images_mode'] ?? 'fetch' )
			? new Upload_Image_Resolver( $this->api, $state['store_ref'], $state['connection_id'], time() + $this->upload_budget, $state['upload_failed'] ?? array() )
			: new Fetch_Image_Resolver();
		$serializer = new Product_Serializer();
		$next       = $state;
		$products   = array();
		$ids        = array();
		$warnings   = array();

		while ( empty( $products ) && ! empty( $next['queue'] ) ) {
			foreach ( array_slice( $next['queue'], 0, self::BATCH_SIZE ) as $id ) {
				$product = wc_get_product( $id );
				$payload = $product instanceof \WC_Product ? $serializer->serialize( $product, $resolver ) : null;
				if ( null === $payload ) {
					// Gone or no longer supported: leaves the total (W26).
					$next['queue'] = array_values( array_diff( $next['queue'], array( $id ) ) );
					$next['total'] = max( 0, (int) $next['total'] - 1 );
					++$next['counts']['not_supported'];
					continue;
				}
				$products[] = $payload;
				$ids[]      = (int) $id;
				$local      = array_merge( $serializer->warnings( $id ), $resolver->warnings( $id ) );
				if ( ! empty( $local ) ) {
					$warnings[ $id ] = $local;
				}
			}

			if ( $resolver instanceof Upload_Image_Resolver && $resolver->out_of_time() ) {
				// Save only the failed uploads (W23); uploads already done are cached, so the rerun is cheaper.
				$state['upload_failed'] = $resolver->failed_ids();
				if ( $this->commit( $state ) ) {
					self::schedule( self::SEND_HOOK, $state['id'], 0 );
				}
				return;
			}
		}
		if ( $resolver instanceof Upload_Image_Resolver ) {
			$next['upload_failed'] = $resolver->failed_ids();
		}
		if ( empty( $products ) ) {
			$this->advance( $next );
			return;
		}

		$response = $this->api->post(
			Api_Client::store_path( $next['store_ref'], '/integrations/woocommerce/products/batch' ),
			array(
				'connection_id' => $next['connection_id'],
				'overwrite'     => (bool) $next['overwrite'],
				'products'      => $products,
			),
			self::BATCH_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			$this->transport_error( $next, $response, self::SEND_HOOK );
			return;
		}
		$job_id = $response->json['job_id'] ?? null;
		if ( $response->ok() && is_string( $job_id ) && '' !== $job_id ) {
			$next['in_flight'] = array(
				'job_id'         => $job_id,
				'product_ids'    => $ids,
				'submitted_at'   => time(),
				'local_warnings' => $warnings,
			);
			$next['queue']     = array_values( array_diff( $next['queue'], $ids ) );
			$next['retry']     = self::no_retry();
			if ( $this->commit( $next ) ) {
				self::schedule( self::POLL_HOOK, $next['id'], self::POLL_DELAY );
			}
			return;
		}

		$code = $response->error_code();
		if ( 409 === $response->status && 'WOOCOMMERCE_COPY_IN_PROGRESS' === $code ) {
			$this->retry( $next, 'conflict', self::SEND_HOOK, $code, $response->error_message(), self::CONFLICT_DELAY );
		} elseif ( 429 === $response->status ) {
			$this->retry( $next, 'throttled', self::SEND_HOOK, $code ?? 'WOOCOMMERCE_RATE_LIMITED', $response->error_message(), self::retry_after( $response ) );
		} elseif ( $response->status >= 500 ) {
			$this->retry( $next, 'transient', self::SEND_HOOK, 'API_UNREACHABLE', $response->error_message() );
		} elseif ( 400 === $response->status ) {
			$this->reject_batch( $next, $ids, $warnings, $response->error_message() );
		} else {
			$this->fatal( $next, $response );
		}
	}

	/**
	 * Asks for the job in flight.
	 *
	 * @param array $state Running state with a job in flight.
	 * @return void
	 */
	private function poll( array $state ): void {
		$response = $this->api->get( Api_Client::store_path( $state['store_ref'], '/integrations/woocommerce/jobs/' . rawurlencode( (string) $state['in_flight']['job_id'] ) ) );
		if ( is_wp_error( $response ) ) {
			$this->transport_error( $state, $response, self::POLL_HOOK );
			return;
		}
		if ( $response->status >= 500 ) {
			$this->retry( $state, 'transient', self::POLL_HOOK, 'API_UNREACHABLE', $response->error_message() );
			return;
		}
		if ( 429 === $response->status ) {
			$this->retry( $state, 'throttled', self::POLL_HOOK, $response->error_code() ?? 'WOOCOMMERCE_RATE_LIMITED', $response->error_message(), self::retry_after( $response ) );
			return;
		}

		$status = $response->ok() ? ( $response->json['status'] ?? null ) : null;
		if ( 'processing' === $status ) {
			if ( time() - (int) $state['in_flight']['submitted_at'] > self::POLL_TIMEOUT ) {
				$this->fail( $state, 'JOB_TIMEOUT', __( 'ecomweb took too long to copy a batch.', 'ecomweb-for-woocommerce' ) );
				return;
			}
			$state['retry'] = self::no_retry();
			if ( $this->commit( $state ) ) {
				self::schedule( self::POLL_HOOK, $state['id'], self::POLL_DELAY );
			}
		} elseif ( 'completed' === $status ) {
			$results = $response->json['results'] ?? array();
			$this->record_results( $state, is_array( $results ) ? $results : array(), null );
		} elseif ( 'failed' === $status ) {
			$error = self::error( $response->json['error'] ?? null );
			$this->record_results( $state, array(), $error ?? self::error_item( 'INTERNAL', '' ) );
		} else {
			$this->fatal( $state, $response );
		}
	}

	/**
	 * Applies a finished job's results, then moves on.
	 *
	 * @param array      $state     State with the job in flight.
	 * @param array      $results   CopyResult items.
	 * @param array|null $job_error Error for products without a result (a failed job).
	 * @return void
	 */
	private function record_results( array $state, array $results, ?array $job_error ): void {
		$in_flight = $state['in_flight'];
		$local     = $in_flight['local_warnings'] ?? array();
		$pending   = array_flip( array_map( 'intval', $in_flight['product_ids'] ) );

		foreach ( $results as $result ) {
			$id = is_array( $result ) && isset( $result['wc_product_id'] ) ? (int) $result['wc_product_id'] : 0;
			if ( ! isset( $pending[ $id ] ) ) {
				continue;
			}
			unset( $pending[ $id ] );

			$kind = in_array( $result['result'] ?? null, self::RESULTS, true ) ? $result['result'] : 'failed';
			Product_Meta::apply_result( $result, $state['connection_id'] );
			++$state['counts'][ $kind ];

			$warnings = array_merge( self::warnings( $result['warnings'] ?? array() ), $local[ $id ] ?? array() );
			$error    = self::error( $result['error'] ?? null );
			if ( ! empty( $warnings ) || null !== $error ) {
				$url                    = isset( $result['url'] ) && is_string( $result['url'] ) ? $result['url'] : null;
				$state['issues'][ $id ] = self::issue( $id, $kind, $url, $warnings, $error );
			}
		}

		$missing = $job_error ?? self::error_item( 'INTERNAL', __( 'ecomweb did not report a result for this product.', 'ecomweb-for-woocommerce' ) );
		foreach ( array_keys( $pending ) as $id ) {
			++$state['counts']['failed'];
			$state['issues'][ $id ] = self::issue( $id, 'failed', null, $local[ $id ] ?? array(), $missing );
		}

		$state['in_flight'] = null;
		$state['retry']     = self::no_retry();
		$this->advance( $state );
	}

	/**
	 * A 400 for the batch: its products fail with BATCH_REJECTED and the copy goes on.
	 *
	 * @param array  $state    State.
	 * @param int[]  $ids      Products in the batch.
	 * @param array  $warnings Local warnings by product ID.
	 * @param string $message  The API's message.
	 * @return void
	 */
	private function reject_batch( array $state, array $ids, array $warnings, string $message ): void {
		foreach ( $ids as $id ) {
			++$state['counts']['failed'];
			$state['issues'][ $id ] = self::issue( $id, 'failed', null, $warnings[ $id ] ?? array(), self::error_item( 'BATCH_REJECTED', $message ) );
		}
		$state['queue'] = array_values( array_diff( $state['queue'], $ids ) );
		$state['retry'] = self::no_retry();
		$this->advance( $state );
	}

	/**
	 * Queues the next SEND, or completes the copy when nothing is left.
	 *
	 * @param array $state State with nothing in flight.
	 * @return void
	 */
	private function advance( array $state ): void {
		if ( ! empty( $state['queue'] ) ) {
			if ( $this->commit( $state ) ) {
				self::schedule( self::SEND_HOOK, $state['id'], 0 );
			}
			return;
		}
		$state['status']      = 'completed';
		$state['finished_at'] = time();
		$this->commit( $state );
	}

	/**
	 * A WP_Error from the API client: signed out ends the copy, anything else is retried.
	 *
	 * @param array     $state State.
	 * @param \WP_Error $error Error.
	 * @param string    $hook  Hook to retry.
	 * @return void
	 */
	private function transport_error( array $state, \WP_Error $error, string $hook ): void {
		if ( in_array( $error->get_error_code(), array( 'ecomweb_signed_out', 'ecomweb_not_signed_in' ), true ) ) {
			$this->fail( $state, 'SIGNED_OUT', $error->get_error_message() );
			return;
		}
		$this->retry( $state, 'transient', $hook, 'API_UNREACHABLE', $error->get_error_message() );
	}

	/**
	 * An answer that ends the copy (W28 for anything not named).
	 *
	 * @param array         $state    State.
	 * @param Http_Response $response Response.
	 * @return void
	 */
	private function fatal( array $state, Http_Response $response ): void {
		$code = $response->error_code();
		if ( 403 === $response->status && 'PLAN_LIMIT_EXCEEDED' !== $code ) {
			$code = 'NO_ACCESS';
		}
		if ( 404 === $response->status && 'WOOCOMMERCE_CONNECTION_NOT_FOUND' === $code ) {
			$this->connection->forget();
		}
		$this->fail( $state, $code ?? 'HTTP_' . $response->status, $response->error_message() );
	}

	/**
	 * Schedules a retry of $kind, or fails the copy once its tries are used up.
	 *
	 * @param array  $state   State.
	 * @param string $kind    conflict, throttled or transient.
	 * @param string $hook    Hook to retry.
	 * @param string $code    Error code when giving up.
	 * @param string $message Error message when giving up.
	 * @param int    $delay   Delay (conflict, throttled); transient uses TRANSIENT_DELAYS.
	 * @return void
	 */
	private function retry( array $state, string $kind, string $hook, string $code, string $message, int $delay = 0 ): void {
		$limits = array(
			'conflict'  => self::CONFLICT_TRIES,
			'throttled' => self::THROTTLE_TRIES,
			'transient' => count( self::TRANSIENT_DELAYS ),
		);
		$count  = ( $state['retry']['kind'] ?? null ) === $kind ? (int) $state['retry']['count'] : 0;
		if ( $count >= $limits[ $kind ] ) {
			$this->fail( $state, $code, $message );
			return;
		}
		if ( 'transient' === $kind ) {
			$delay = self::TRANSIENT_DELAYS[ $count ];
		}
		$state['retry'] = array(
			'kind'  => $kind,
			'count' => $count + 1,
		);
		if ( $this->commit( $state ) ) {
			self::schedule( $hook, $state['id'], $delay );
		}
	}

	/**
	 * Ends the copy with an error.
	 *
	 * @param array  $state   State.
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return void
	 */
	private function fail( array $state, string $code, string $message ): void {
		$state['status']      = 'failed';
		$state['finished_at'] = time();
		$state['error']       = self::error_item( $code, $message );
		$this->commit( $state );
	}

	/**
	 * Saves the state, unless the stored copy was replaced or stopped meanwhile (a Cancel that
	 * landed during the HTTP call must not be undone).
	 *
	 * @param array $state State.
	 * @return bool Whether it was saved.
	 */
	private function commit( array $state ): bool {
		if ( null === self::running_state( (string) $state['id'] ) ) {
			return false;
		}
		Copy_State::put( $state );
		return true;
	}

	/**
	 * The saved state when it is this copy and it is running.
	 *
	 * @param string $copy_id Copy ID.
	 * @return array|null
	 */
	private static function running_state( string $copy_id ): ?array {
		$state = Copy_State::get();
		if ( null === $state || '' === $copy_id || ( $state['id'] ?? null ) !== $copy_id || 'running' !== ( $state['status'] ?? null ) ) {
			return null;
		}
		return $state;
	}

	/**
	 * Schedules a hook for the copy: now (async) or after $delay seconds.
	 *
	 * @param string $hook    Hook.
	 * @param string $copy_id Copy ID.
	 * @param int    $delay   Seconds.
	 * @return void
	 */
	private static function schedule( string $hook, string $copy_id, int $delay ): void {
		if ( $delay <= 0 ) {
			as_enqueue_async_action( $hook, array( $copy_id ), self::GROUP );
			return;
		}
		as_schedule_single_action( time() + $delay, $hook, array( $copy_id ), self::GROUP );
	}

	/**
	 * Seconds from a numeric Retry-After header (1 to 3600), else THROTTLE_DELAY.
	 *
	 * @param Http_Response $response Response.
	 * @return int
	 */
	private static function retry_after( Http_Response $response ): int {
		$value = trim( (string) $response->header( 'Retry-After' ) );
		return '' !== $value && ctype_digit( $value ) ? max( 1, min( 3600, (int) $value ) ) : self::THROTTLE_DELAY;
	}

	/**
	 * An issue entry: the local name and regular price, the result, and what went wrong.
	 *
	 * @param int         $id       Product ID.
	 * @param string      $result   Result.
	 * @param string|null $url      ecomweb URL.
	 * @param array       $warnings `{code, message}` items.
	 * @param array|null  $error    `{code, message}` or null.
	 * @return array
	 */
	private static function issue( int $id, string $result, ?string $url, array $warnings, ?array $error ): array {
		$product = wc_get_product( $id );
		$regular = $product instanceof \WC_Product ? (string) $product->get_regular_price( 'edit' ) : '';
		return array(
			'name'          => $product instanceof \WC_Product ? html_entity_decode( $product->get_name( 'edit' ), ENT_QUOTES, 'UTF-8' ) : '#' . $id,
			'result'        => $result,
			'url'           => $url,
			'regular_price' => '' === $regular ? null : $regular,
			'warnings'      => $warnings,
			'error'         => $error,
		);
	}

	/**
	 * Well-formed `{code, message}` warnings.
	 *
	 * @param mixed $items Items.
	 * @return array[]
	 */
	private static function warnings( $items ): array {
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$warning = self::error( $item );
			if ( null !== $warning ) {
				$out[] = $warning;
			}
		}
		return $out;
	}

	/**
	 * A `{code, message}` item, or null when it has no string code.
	 *
	 * @param mixed $item Item.
	 * @return array|null
	 */
	private static function error( $item ): ?array {
		if ( ! is_array( $item ) || ! isset( $item['code'] ) || ! is_string( $item['code'] ) || '' === $item['code'] ) {
			return null;
		}
		return self::error_item( $item['code'], isset( $item['message'] ) && is_scalar( $item['message'] ) ? (string) $item['message'] : '' );
	}

	/**
	 * `{code, message}`.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private static function error_item( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Retry state after a successful step.
	 *
	 * @return array
	 */
	private static function no_retry(): array {
		return array(
			'kind'  => null,
			'count' => 0,
		);
	}

	/**
	 * The refusal when a copy is already running.
	 *
	 * @return \WP_Error
	 */
	private static function running_error(): \WP_Error {
		return new \WP_Error( 'ecomweb_copy_running', __( 'Wait for the current copy to finish.', 'ecomweb-for-woocommerce' ) );
	}
}
