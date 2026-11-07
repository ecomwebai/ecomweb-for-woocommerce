<?php
/**
 * Copy_Runner and Copy_State tests. The handlers are called directly, never through the
 * Action Scheduler runner, so a Fake_Api assertion (insecure or unmatched request) fails the test
 * instead of being caught and logged as a failed action.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Connection;
use Ecomweb\Woo\Copy_Runner;
use Ecomweb\Woo\Copy_State;
use Ecomweb\Woo\Lock;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Product_Meta;
use Ecomweb\Woo\Session_Store;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Class Test_Copy_Runner
 */
class Test_Copy_Runner extends WP_UnitTestCase {

	const BATCH  = '/stores/s1/integrations/woocommerce/products/batch';
	const JOBS   = '/stores/s1/integrations/woocommerce/jobs/';
	const UPLOAD = '/stores/s1/assets/upload';

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Set up: signed in and connected to s1 in fetch mode.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
		remove_filter( 'option_home', '_config_wp_home' );
		remove_filter( 'option_siteurl', '_config_wp_siteurl' );
		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );
		update_option( 'uploads_use_yearmonth_folders', 0 );
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT',
				'refresh_token'      => 'RT',
				'expires_at'         => time() + 3600,
				'email'              => 'm@shop.vn',
				'user_id'            => 'u1',
				'api_url'            => Config::api_url(),
				'signed_in_home_url' => Auth::normalise_home( home_url() ),
			)
		);
		$this->connect( 'fetch' );
		$this->fake = Fake_Api::start();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		parent::tear_down();
	}

	/**
	 * Saves the connection.
	 *
	 * @param string $mode Images mode.
	 */
	private function connect( string $mode ) {
		update_option(
			Connection::OPTION,
			array(
				'connection_id' => 'c1',
				'images_mode'   => $mode,
				'store'         => array(
					'id'            => 's1',
					'ref'           => 's1',
					'name'          => 'Shop One',
					'currency'      => 'USD',
					'dashboard_url' => 'https://ecomweb.app/store/s1',
				),
				'payload_hash'  => 'x',
			),
			false
		);
	}

	/**
	 * A runner.
	 *
	 * @param int $budget Upload budget.
	 * @return Copy_Runner
	 */
	private function runner( int $budget = Copy_Runner::UPLOAD_BUDGET ): Copy_Runner {
		return new Copy_Runner( Plugin::instance()->api(), Plugin::instance()->connection(), $budget );
	}

	/**
	 * Simple products.
	 *
	 * @param int   $n     How many.
	 * @param array $props Extra props.
	 * @return int[]
	 */
	private function products( int $n, array $props = array() ): array {
		$ids = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$product = new WC_Product_Simple();
			$product->set_props(
				array_merge(
					array(
						'name'          => 'Tee ' . $i,
						'status'        => 'publish',
						'regular_price' => '25.00',
					),
					$props
				)
			);
			$ids[] = $product->save();
		}
		return $ids;
	}

	/**
	 * Starts a copy and takes its first action off the queue.
	 *
	 * @param int[] $ids       Product IDs.
	 * @param bool  $overwrite Overwrite.
	 * @return string Copy ID.
	 */
	private function start( array $ids, bool $overwrite = false ): string {
		$state = $this->runner()->start( $ids, $overwrite, 0 );
		$this->assertIsArray( $state );
		return $state['id'];
	}

	/**
	 * Cancels the pending actions, as if the Action Scheduler runner had claimed them.
	 *
	 * @param string $id Copy ID.
	 */
	private function consume( string $id ) {
		as_unschedule_all_actions( Copy_Runner::SEND_HOOK, array( $id ), Copy_Runner::GROUP );
		as_unschedule_all_actions( Copy_Runner::POLL_HOOK, array( $id ), Copy_Runner::GROUP );
	}

	/**
	 * Runs SEND.
	 *
	 * @param string           $id     Copy ID.
	 * @param Copy_Runner|null $runner Runner.
	 */
	private function send( string $id, ?Copy_Runner $runner = null ) {
		$this->consume( $id );
		( $runner ?? $this->runner() )->handle_send( $id );
	}

	/**
	 * Runs POLL.
	 *
	 * @param string $id Copy ID.
	 */
	private function poll( string $id ) {
		$this->consume( $id );
		$this->runner()->handle_poll( $id );
	}

	/**
	 * Next run of a hook for the copy: true for async, a timestamp, or false.
	 *
	 * @param string $hook Hook.
	 * @param string $id   Copy ID.
	 * @return int|bool
	 */
	private function next( string $hook, string $id ) {
		return as_next_scheduled_action( $hook, array( $id ), Copy_Runner::GROUP );
	}

	/**
	 * Queues a 202.
	 *
	 * @param string $job Job ID.
	 */
	private function accepted( string $job = 'j1' ) {
		$this->fake->on(
			'POST',
			self::BATCH,
			202,
			array(
				'job_id'     => $job,
				'status_url' => '/stores/s1/integrations/woocommerce/jobs/' . $job,
			)
		);
	}

	/**
	 * Queues a completed job.
	 *
	 * @param string $job     Job ID.
	 * @param array  $results Results.
	 */
	private function completed( string $job, array $results ) {
		$this->fake->on(
			'GET',
			self::JOBS . $job,
			200,
			array(
				'status'  => 'completed',
				'results' => $results,
			)
		);
	}

	/**
	 * A CopyResult.
	 *
	 * @param int    $id     WC product ID.
	 * @param string $result Result.
	 * @param array  $extra  Overrides.
	 * @return array
	 */
	private function result( int $id, string $result, array $extra = array() ): array {
		$ok = 'failed' !== $result;
		return array_merge(
			array(
				'wc_product_id'      => $id,
				'result'             => $result,
				'ecomweb_product_id' => $ok ? 'E' . $id : null,
				'url'                => $ok ? 'https://ecomweb.app/store/s1/products/E' . $id : null,
				'variants'           => array(),
				'warnings'           => array(),
				'error'              => $ok ? null : array(
					'code'    => 'INTERNAL',
					'message' => 'x',
				),
			),
			$extra
		);
	}

	/**
	 * Decoded body of a recorded request.
	 *
	 * @param array $call Recorded call.
	 * @return array
	 */
	private function body( array $call ): array {
		return json_decode( $call['body'], true );
	}

	/**
	 * The saved state.
	 *
	 * @return array
	 */
	private function state(): array {
		$state = Copy_State::get();
		$this->assertIsArray( $state );
		return $state;
	}

	/**
	 * Overwrites fields of the saved state.
	 *
	 * @param array $fields Fields.
	 */
	private function patch( array $fields ) {
		Copy_State::put( array_merge( $this->state(), $fields ) );
	}

	/**
	 * Start writes the state and queues SEND.
	 */
	public function test_start_queues_send_and_writes_state() {
		$state = $this->runner()->start( array( 5, 6 ), false, 1 );

		$this->assertIsArray( $state );
		$saved = $this->state();
		$this->assertSame( $state['id'], $saved['id'] );
		$this->assertTrue( wp_is_uuid( $saved['id'] ) );
		$this->assertSame( 'running', $saved['status'] );
		$this->assertTrue( Copy_State::is_running() );
		$this->assertSame( 'c1', $saved['connection_id'] );
		$this->assertSame( 's1', $saved['store_ref'] );
		$this->assertFalse( $saved['overwrite'] );
		$this->assertSame( 2, $saved['total'] );
		$this->assertSame( array( 5, 6 ), $saved['queue'] );
		$this->assertNull( $saved['in_flight'] );
		$this->assertSame(
			array(
				'created'       => 0,
				'updated'       => 0,
				'skipped'       => 0,
				'failed'        => 0,
				'not_supported' => 1,
			),
			$saved['counts']
		);
		$this->assertNull( $saved['error'] );
		$this->assertTrue( as_has_scheduled_action( Copy_Runner::SEND_HOOK, array( $saved['id'] ), Copy_Runner::GROUP ) );
		$this->assertTrue( $this->next( Copy_Runner::SEND_HOOK, $saved['id'] ) );
	}

	/**
	 * When Action Scheduler doesn't store the first action, the copy is failed, not left running
	 * with nothing scheduled, and a new copy can start.
	 */
	public function test_start_fails_the_copy_when_enqueue_fails() {
		add_filter( 'pre_as_enqueue_async_action', '__return_zero' );
		$state = $this->runner()->start( array( 5, 6 ), false, 0 );
		remove_filter( 'pre_as_enqueue_async_action', '__return_zero' );

		$this->assertIsArray( $state );
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 'COPY_NOT_STARTED', $state['error']['code'] );
		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertIsInt( $this->state()['finished_at'] );
		$this->assertFalse( Copy_State::is_running() );
		$this->assertIsArray( $this->runner()->start( array( 5 ), false, 0 ) );
		$this->assertTrue( Copy_State::is_running() );
	}

	/**
	 * The copy lock outlives a SEND's worst wall time (network waits don't count toward PHP's
	 * time limit): the upload budget, one upload started just inside it, the batch POST, and a
	 * refresh with its lock wait. The session refresh timeout stays below it too.
	 */
	public function test_lock_ttl_covers_worst_case_send() {
		$worst = Copy_Runner::UPLOAD_BUDGET + Ecomweb\Woo\Api_Client::UPLOAD_TIMEOUT + Copy_Runner::BATCH_TIMEOUT + 2 * Auth::REFRESH_TIMEOUT;
		$this->assertGreaterThanOrEqual( $worst + 30, Copy_Runner::LOCK_TTL );
		$this->assertGreaterThan( Copy_Runner::TIME_LIMIT, Copy_Runner::LOCK_TTL );
		$this->assertLessThan( Copy_Runner::LOCK_TTL, Auth::REFRESH_TIMEOUT );
	}

	/**
	 * A second start while one runs is refused (Review Focus 2).
	 */
	public function test_start_twice_returns_running_error() {
		$first = $this->runner()->start( array( 5 ), false, 0 );
		$this->assertIsArray( $first );

		$second = $this->runner()->start( array( 6 ), false, 0 );

		$this->assertWPError( $second );
		$this->assertSame( 'ecomweb_copy_running', $second->get_error_code() );
		$this->assertSame( $first['id'], $this->state()['id'] );
		$this->assertSame( array( 5 ), $this->state()['queue'] );
	}

	/**
	 * Start needs a connection.
	 */
	public function test_start_without_connection_is_refused() {
		delete_option( Connection::OPTION );

		$this->assertWPError( $this->runner()->start( array( 5 ), false, 0 ) );
		$this->assertNull( Copy_State::get() );
	}

	/**
	 * SEND posts the first 20 and schedules POLL.
	 */
	public function test_send_posts_batch_of_20_and_schedules_poll() {
		$ids      = $this->products( 45 );
		$timeouts = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$timeouts ) {
				$timeouts[] = $args['timeout'];
				return $pre;
			},
			5,
			2
		);
		$this->accepted( 'j1' );
		$id = $this->start( $ids );

		$this->send( $id );

		$this->assertSame( 1, $this->fake->count( self::BATCH ) );
		$body = $this->body( $this->fake->calls( self::BATCH )[0] );
		$this->assertCount( 20, $body['products'] );
		$this->assertSame( array_slice( $ids, 0, 20 ), array_column( $body['products'], 'id' ) );
		$this->assertFalse( $body['overwrite'] );
		$this->assertSame( 'c1', $body['connection_id'] );
		$this->assertSame( array( 30 ), $timeouts );

		$state = $this->state();
		$this->assertSame( 'j1', $state['in_flight']['job_id'] );
		$this->assertSame( array_slice( $ids, 0, 20 ), $state['in_flight']['product_ids'] );
		$this->assertEqualsWithDelta( time(), $state['in_flight']['submitted_at'], 2 );
		$this->assertCount( 25, $state['queue'] );
		$this->assertSame( 45, $state['total'] );
		$this->assertEqualsWithDelta( time() + Copy_Runner::POLL_DELAY, $this->next( Copy_Runner::POLL_HOOK, $id ), 2 );
		$this->assertFalse( $this->next( Copy_Runner::SEND_HOOK, $id ) );
	}

	/**
	 * Overwrite travels in the body.
	 */
	public function test_send_carries_overwrite() {
		$this->accepted();
		$id = $this->start( $this->products( 1 ), true );
		$this->send( $id );

		$this->assertTrue( $this->body( $this->fake->calls( self::BATCH )[0] )['overwrite'] );
	}

	/**
	 * A second SEND while a job is in flight sends nothing (Review Focus 2).
	 */
	public function test_duplicate_send_while_job_in_flight_does_nothing() {
		$this->accepted();
		$id = $this->start( $this->products( 25 ) );
		$this->send( $id );
		$before = $this->state();

		$this->runner()->handle_send( $id );

		$this->assertSame( 1, $this->fake->count( self::BATCH ) );
		$this->assertSame( $before, $this->state() );
	}

	/**
	 * An action for an older copy exits without HTTP (Review Focus 2).
	 */
	public function test_stale_action_for_old_copy_id_exits() {
		$id     = $this->start( $this->products( 2 ) );
		$before = $this->state();

		$this->runner()->handle_send( 'old-id' );
		$this->runner()->handle_poll( 'old-id' );

		$this->assertSame( array(), $this->fake->calls() );
		$this->assertSame( $before, $this->state() );
		$this->assertFalse( $this->next( Copy_Runner::SEND_HOOK, 'old-id' ) );
		$this->assertTrue( $this->next( Copy_Runner::SEND_HOOK, $id ) );
	}

	/**
	 * A finished copy's leftover actions exit without HTTP.
	 */
	public function test_action_for_finished_copy_exits() {
		$id = $this->start( $this->products( 2 ) );
		$this->patch( array( 'status' => 'cancelled' ) );

		$this->runner()->handle_send( $id );

		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * POLL with no job in flight exits.
	 */
	public function test_poll_without_job_in_flight_exits() {
		$id = $this->start( $this->products( 1 ) );
		$this->poll( $id );

		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * While the lock is held, SEND puts itself back in 10 s.
	 */
	public function test_send_reschedules_when_lock_is_busy() {
		$id    = $this->start( $this->products( 1 ) );
		$token = Lock::acquire( 'copy', 120, 1 );

		$this->send( $id );
		Lock::release( 'copy', $token );

		$this->assertSame( array(), $this->fake->calls() );
		$this->assertEqualsWithDelta( time() + 10, $this->next( Copy_Runner::SEND_HOOK, $id ), 2 );
	}

	/**
	 * Processing: POLL again in POLL_DELAY.
	 */
	public function test_poll_processing_reschedules() {
		$this->accepted();
		$this->fake->on( 'GET', self::JOBS . 'j1', 200, array( 'status' => 'processing' ) );
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );

		$this->poll( $id );

		$this->assertSame( 1, $this->fake->count( self::JOBS . 'j1' ) );
		$this->assertEqualsWithDelta( time() + Copy_Runner::POLL_DELAY, $this->next( Copy_Runner::POLL_HOOK, $id ), 2 );
		$this->assertSame( 'running', $this->state()['status'] );
		$this->assertSame( 'j1', $this->state()['in_flight']['job_id'] );
	}

	/**
	 * A job still processing after POLL_TIMEOUT (75 min, W29) fails the copy; at 30 min it does not.
	 */
	public function test_poll_timeout_fails_with_job_timeout() {
		$this->assertSame( 4500, Copy_Runner::POLL_TIMEOUT );
		$this->accepted();
		$this->fake->on( 'GET', self::JOBS . 'j1', 200, array( 'status' => 'processing' ) );
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );

		$state                              = $this->state();
		$state['in_flight']['submitted_at'] = time() - 1801;
		Copy_State::put( $state );
		$this->poll( $id );
		$this->assertSame( 'running', $this->state()['status'] );

		$state                              = $this->state();
		$state['in_flight']['submitted_at'] = time() - 4501;
		Copy_State::put( $state );
		$this->poll( $id );

		$state = $this->state();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 'JOB_TIMEOUT', $state['error']['code'] );
		$this->assertIsInt( $state['finished_at'] );
		$this->assertFalse( $this->next( Copy_Runner::POLL_HOOK, $id ) );
	}

	/**
	 * Completed: meta written, counts bumped, issues recorded with local name and price.
	 */
	public function test_completed_results_write_meta_counts_and_issues() {
		list( $a ) = $this->products(
			1,
			array(
				'name'       => 'Áo thun &amp; quần',
				'sale_price' => '20.00',
			)
		);
		list( $b ) = $this->products( 1 );
		$this->accepted();
		$this->completed(
			'j1',
			array(
				$this->result(
					$a,
					'created',
					array(
						'ecomweb_product_id' => 'E5',
						'url'                => 'https://ecomweb.app/store/s1/products/E5',
						'variants'           => array(
							array(
								'wc_variation_id'    => $a,
								'ecomweb_variant_id' => 'V51',
							),
						),
						'warnings'           => array(
							array(
								'code'    => 'ON_SALE_REGULAR_PRICE_NOT_COPIED',
								'message' => '…',
							),
						),
					)
				),
				$this->result(
					$b,
					'failed',
					array(
						'error' => array(
							'code'    => 'TOO_MANY_VARIANT_AXES',
							'message' => '…',
						),
					)
				),
			)
		);
		$id = $this->start( array( $a, $b ) );
		$this->send( $id );

		$this->poll( $id );

		$this->assertSame( 'E5', get_post_meta( $a, Product_Meta::PRODUCT_ID, true ) );
		$this->assertSame( 'c1', get_post_meta( $a, Product_Meta::CONNECTION_ID, true ) );
		$this->assertSame( 'https://ecomweb.app/store/s1/products/E5', get_post_meta( $a, Product_Meta::PRODUCT_URL, true ) );
		$this->assertMatchesRegularExpression( '/^\d+$/', get_post_meta( $a, Product_Meta::COPIED_AT, true ) );
		$this->assertSame( '["V51"]', get_post_meta( $a, Product_Meta::VARIANT_IDS, true ) );
		$this->assertSame( '', get_post_meta( $b, Product_Meta::PRODUCT_ID, true ) );

		$state = $this->state();
		$this->assertSame( 1, $state['counts']['created'] );
		$this->assertSame( 1, $state['counts']['failed'] );
		$this->assertSame( array( $a, $b ), array_keys( $state['issues'] ) );
		$this->assertSame( 'Áo thun & quần', $state['issues'][ $a ]['name'] );
		$this->assertSame( '25.00', $state['issues'][ $a ]['regular_price'] );
		$this->assertSame( 'created', $state['issues'][ $a ]['result'] );
		$this->assertSame( 'https://ecomweb.app/store/s1/products/E5', $state['issues'][ $a ]['url'] );
		$this->assertSame( 'ON_SALE_REGULAR_PRICE_NOT_COPIED', $state['issues'][ $a ]['warnings'][0]['code'] );
		$this->assertNull( $state['issues'][ $a ]['error'] );
		$this->assertSame( 'TOO_MANY_VARIANT_AXES', $state['issues'][ $b ]['error']['code'] );
		$this->assertSame( 'completed', $state['status'] );
		$this->assertIsInt( $state['finished_at'] );
		$this->assertNull( $state['in_flight'] );
		$this->assertFalse( Copy_State::is_running() );
	}

	/**
	 * A product with no warnings and no error records no issue; a non-empty queue sends again.
	 */
	public function test_clean_results_record_no_issue_and_queue_continues() {
		$ids = $this->products( 21 );
		$this->accepted();
		$this->completed(
			'j1',
			array_map(
				function ( $id ) {
					return $this->result( $id, 'created' );
				},
				array_slice( $ids, 0, 20 )
			)
		);
		$id = $this->start( $ids );
		$this->send( $id );
		$this->poll( $id );

		$state = $this->state();
		$this->assertSame( array(), $state['issues'] );
		$this->assertSame( 20, $state['counts']['created'] );
		$this->assertSame( 'running', $state['status'] );
		$this->assertNull( $state['in_flight'] );
		$this->assertTrue( $this->next( Copy_Runner::SEND_HOOK, $id ) );
	}

	/**
	 * A failed job marks its products failed with the job's error, then continues.
	 */
	public function test_failed_job_marks_products_failed_and_continues() {
		$ids = $this->products( 2 );
		$this->accepted();
		$this->fake->on(
			'GET',
			self::JOBS . 'j1',
			200,
			array(
				'status' => 'failed',
				'error'  => array(
					'code'    => 'INTERNAL',
					'message' => 'Worker crashed',
				),
			)
		);
		$id = $this->start( $ids );
		$this->send( $id );
		$this->poll( $id );

		$state = $this->state();
		$this->assertSame( 2, $state['counts']['failed'] );
		$this->assertSame( 'INTERNAL', $state['issues'][ $ids[0] ]['error']['code'] );
		$this->assertSame( 'Worker crashed', $state['issues'][ $ids[1] ]['error']['message'] );
		$this->assertSame( 'completed', $state['status'] );
	}

	/**
	 * 409 COPY_IN_PROGRESS: SEND again in 30 s.
	 */
	public function test_409_reschedules_send_in_30s() {
		$this->fake->on( 'POST', self::BATCH, 409, array( 'code' => 'WOOCOMMERCE_COPY_IN_PROGRESS' ) );
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );

		$state = $this->state();
		$this->assertSame( 'conflict', $state['retry']['kind'] );
		$this->assertSame( 1, $state['retry']['count'] );
		$this->assertSame( 'running', $state['status'] );
		$this->assertNull( $state['in_flight'] );
		$this->assertCount( 1, $state['queue'] );
		$this->assertEqualsWithDelta( time() + 30, $this->next( Copy_Runner::SEND_HOOK, $id ), 2 );
	}

	/**
	 * After 20 conflicts the copy fails.
	 */
	public function test_conflict_gives_up_after_20_tries() {
		$this->fake->on( 'POST', self::BATCH, 409, array( 'code' => 'WOOCOMMERCE_COPY_IN_PROGRESS' ) );
		$id = $this->start( $this->products( 1 ) );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->send( $id );
		}
		$this->assertSame( 'running', $this->state()['status'] );

		$this->send( $id );

		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 'WOOCOMMERCE_COPY_IN_PROGRESS', $this->state()['error']['code'] );
	}

	/**
	 * 429 waits Retry-After, else 60 s.
	 */
	public function test_429_honours_retry_after() {
		$this->fake->on( 'POST', self::BATCH, 429, array( 'code' => 'WOOCOMMERCE_RATE_LIMITED' ), array( 'Retry-After' => '90' ) );
		$this->fake->on( 'POST', self::BATCH, 429, array( 'code' => 'WOOCOMMERCE_RATE_LIMITED' ) );
		$id = $this->start( $this->products( 1 ) );

		$this->send( $id );
		$this->assertEqualsWithDelta( time() + 90, $this->next( Copy_Runner::SEND_HOOK, $id ), 2 );
		$this->assertSame( 'throttled', $this->state()['retry']['kind'] );

		$this->send( $id );
		$this->assertEqualsWithDelta( time() + 60, $this->next( Copy_Runner::SEND_HOOK, $id ), 2 );
		$this->assertSame( 2, $this->state()['retry']['count'] );
	}

	/**
	 * 5xx backs off 30, 60, 120, 240, 480 and then fails with API_UNREACHABLE.
	 */
	public function test_transient_errors_back_off_then_fail() {
		$this->fake->on( 'POST', self::BATCH, 503, array( 'message' => 'down' ) );
		$id = $this->start( $this->products( 1 ) );

		foreach ( array( 30, 60, 120, 240, 480 ) as $delay ) {
			$this->send( $id );
			$this->assertSame( 'running', $this->state()['status'] );
			$this->assertEqualsWithDelta( time() + $delay, $this->next( Copy_Runner::SEND_HOOK, $id ), 2 );
		}
		$this->send( $id );

		$state = $this->state();
		$this->assertSame( 6, $this->fake->count( self::BATCH ) );
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 'API_UNREACHABLE', $state['error']['code'] );
		$this->assertFalse( $this->next( Copy_Runner::SEND_HOOK, $id ) );
	}

	/**
	 * A transport error or 5xx while polling retries the poll.
	 */
	public function test_poll_transport_error_retries_poll() {
		$this->accepted();
		$this->fake->fail( 'GET', self::JOBS . 'j1', 'http_request_failed' );
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );

		$this->poll( $id );

		$this->assertSame( 'transient', $this->state()['retry']['kind'] );
		$this->assertEqualsWithDelta( time() + 30, $this->next( Copy_Runner::POLL_HOOK, $id ), 2 );
		$this->assertSame( 'j1', $this->state()['in_flight']['job_id'] );
	}

	/**
	 * The batch POST times out after the API took it: the retry gets 409, the job reports
	 * skipped, meta is still written and nothing is counted twice (Review Focus 3).
	 */
	public function test_network_timeout_on_send_then_skipped_results_still_write_meta() {
		list( $p ) = $this->products( 1 );
		$this->fake->fail( 'POST', self::BATCH, 'http_request_failed' );
		$this->fake->on( 'POST', self::BATCH, 409, array( 'code' => 'WOOCOMMERCE_COPY_IN_PROGRESS' ) );
		$this->accepted( 'j2' );
		$this->completed( 'j2', array( $this->result( $p, 'skipped', array( 'ecomweb_product_id' => 'E5' ) ) ) );
		$id = $this->start( array( $p ) );

		$this->send( $id );
		$this->assertSame( 'transient', $this->state()['retry']['kind'] );
		$this->send( $id );
		$this->assertSame( 'conflict', $this->state()['retry']['kind'] );
		$this->send( $id );
		$this->assertSame( 'j2', $this->state()['in_flight']['job_id'] );
		$this->assertNull( $this->state()['retry']['kind'] );
		$this->poll( $id );

		$state = $this->state();
		$this->assertSame( 'E5', get_post_meta( $p, Product_Meta::PRODUCT_ID, true ) );
		$this->assertSame( 'c1', get_post_meta( $p, Product_Meta::CONNECTION_ID, true ) );
		$this->assertSame( 1, $state['counts']['skipped'] );
		$this->assertSame( 0, $state['counts']['created'] );
		$this->assertSame( 1, $state['total'] );
		$this->assertSame( 'completed', $state['status'] );
	}

	/**
	 * A 400 body like the API's ValidationPipe answer.
	 *
	 * @param string $first First message.
	 * @return array
	 */
	private function rejected( string $first ): array {
		return array(
			'message'    => array( $first, 'bad' ),
			'error'      => 'Bad Request',
			'statusCode' => 400,
		);
	}

	/**
	 * Product IDs in a recorded batch POST.
	 *
	 * @param array $call Recorded call.
	 * @return int[]
	 */
	private function sent_ids( array $call ): array {
		return array_map(
			function ( $p ) {
				return (int) $p['id'];
			},
			$this->body( $call )['products']
		);
	}

	/**
	 * 400 for a batch of 20: nothing fails yet. The 20 are queued to go one at a time, and the
	 * first of them is sent alone next.
	 */
	public function test_400_on_a_batch_resends_its_products_one_at_a_time() {
		$ids = $this->products( 45 );
		$this->fake->on( 'POST', self::BATCH, 400, $this->rejected( 'products.3.name must be shorter than or equal to 255 characters' ) );
		$this->accepted();
		$id = $this->start( $ids );
		$this->send( $id );

		$state = $this->state();
		$this->assertSame( 0, $state['counts']['failed'] );
		$this->assertSame( array(), $state['issues'] );
		$this->assertSame( $ids, $state['queue'] );
		$this->assertSame( array_slice( $ids, 0, 20 ), $state['single'] );
		$this->assertSame( 'running', $state['status'] );
		$this->assertTrue( $this->next( Copy_Runner::SEND_HOOK, $id ) );

		$this->send( $id );

		$calls = $this->fake->calls( self::BATCH );
		$this->assertCount( 2, $calls );
		$this->assertSame( array( $ids[0] ), $this->sent_ids( $calls[1] ) );
		$state = $this->state();
		$this->assertSame( array( $ids[0] ), $state['in_flight']['product_ids'] );
		$this->assertSame( array_slice( $ids, 1, 19 ), $state['single'] );
		$this->assertSame( array_slice( $ids, 1 ), $state['queue'] );
	}

	/**
	 * Only the invalid product of a rejected batch fails, with the API's message; the other 19
	 * are copied one by one, then batches of 20 resume.
	 */
	public function test_400_fails_only_the_invalid_product_then_batches_resume() {
		$ids = $this->products( 25 );
		$bad = $ids[3];
		$msg = 'products.0.name must be shorter than or equal to 255 characters';
		// The batch of 20, then ids[0..2] alone, then the bad one alone, then the rest.
		$this->fake->on( 'POST', self::BATCH, 400, $this->rejected( 'products.3.name must be shorter than or equal to 255 characters' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->accepted();
		}
		$this->fake->on( 'POST', self::BATCH, 400, $this->rejected( $msg ) );
		$this->accepted();
		$this->completed(
			'j1',
			array_map(
				function ( $i ) {
					return $this->result( $i, 'created' );
				},
				$ids
			)
		);
		$id = $this->start( $ids );

		$this->send( $id );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->send( $id );
			if ( null !== $this->state()['in_flight'] ) {
				$this->poll( $id );
			}
		}
		$this->send( $id );

		$calls = $this->fake->calls( self::BATCH );
		$this->assertCount( 22, $calls, 'The batch, one per product of it, then the next batch.' );
		foreach ( array_slice( $calls, 1, 20 ) as $call ) {
			$this->assertCount( 1, $this->body( $call )['products'] );
		}
		$this->assertSame( array( $bad ), $this->sent_ids( $calls[4] ) );
		$this->assertSame( array_slice( $ids, 20 ), $this->sent_ids( end( $calls ) ) );

		$state = $this->state();
		$this->assertSame( 19, $state['counts']['created'] );
		$this->assertSame( 1, $state['counts']['failed'] );
		$this->assertSame( array( $bad ), array_keys( $state['issues'] ) );
		$this->assertSame( 'BATCH_REJECTED', $state['issues'][ $bad ]['error']['code'] );
		$this->assertSame( $msg . '; bad', $state['issues'][ $bad ]['error']['message'] );
		$this->assertSame( array(), $state['single'] );
		$this->assertSame( array_slice( $ids, 20 ), $state['in_flight']['product_ids'] );
	}

	/**
	 * 400 for a batch of one product: that product fails with BATCH_REJECTED and the copy goes on.
	 */
	public function test_400_on_a_single_product_batch_fails_that_product() {
		$ids = $this->products( 1 );
		$this->fake->on( 'POST', self::BATCH, 400, $this->rejected( 'products.0.images must contain no more than 20 elements' ) );
		$id = $this->start( $ids );
		$this->send( $id );

		$state = $this->state();
		$this->assertSame( 1, $state['counts']['failed'] );
		$this->assertSame( array(), $state['queue'] );
		$this->assertCount( 1, $state['issues'] );
		$this->assertSame( 'BATCH_REJECTED', $state['issues'][ $ids[0] ]['error']['code'] );
		$this->assertSame( 'products.0.images must contain no more than 20 elements; bad', $state['issues'][ $ids[0] ]['error']['message'] );
		$this->assertSame( 'failed', $state['issues'][ $ids[0] ]['result'] );
		$this->assertSame( 'completed', $state['status'] );
		$this->assertSame( 1, $this->fake->count( self::BATCH ) );
	}

	/**
	 * Responses that end the copy.
	 *
	 * @return array
	 */
	public function fatal_responses(): array {
		return array(
			'plan limit'   => array( 403, array( 'code' => 'PLAN_LIMIT_EXCEEDED' ), 'PLAN_LIMIT_EXCEEDED' ),
			'currency'     => array( 422, array( 'code' => 'WOOCOMMERCE_CURRENCY_MISMATCH' ), 'WOOCOMMERCE_CURRENCY_MISMATCH' ),
			'other 403'    => array( 403, array( 'code' => 'FORBIDDEN' ), 'NO_ACCESS' ),
			'unknown 404'  => array( 404, array( 'message' => 'Cannot POST' ), 'HTTP_404' ),
			'413'          => array( 413, array( 'code' => 'PAYLOAD_TOO_LARGE' ), 'PAYLOAD_TOO_LARGE' ),
			'other 409'    => array( 409, array( 'code' => 'SOMETHING_ELSE' ), 'SOMETHING_ELSE' ),
			'202 no jobid' => array( 202, array(), 'HTTP_202' ),
		);
	}

	/**
	 * Each fatal response fails the copy with its code and keeps the connection.
	 *
	 * @dataProvider fatal_responses
	 * @param int    $status Status.
	 * @param array  $json   Body.
	 * @param string $code   Expected error code.
	 */
	public function test_fatal_responses_fail_copy( int $status, array $json, string $code ) {
		$this->fake->on( 'POST', self::BATCH, $status, $json );
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );

		$state = $this->state();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( $code, $state['error']['code'] );
		$this->assertIsString( $state['error']['message'] );
		$this->assertIsInt( $state['finished_at'] );
		$this->assertFalse( $this->next( Copy_Runner::SEND_HOOK, $id ) );
		$this->assertFalse( $this->next( Copy_Runner::POLL_HOOK, $id ) );
		$this->assertNotNull( Plugin::instance()->connection()->current() );
	}

	/**
	 * 403 PLAN_LIMIT_EXCEEDED fails the copy.
	 */
	public function test_plan_limit_403_fails_copy() {
		$this->fake->on(
			'POST',
			self::BATCH,
			403,
			array(
				'code'    => 'PLAN_LIMIT_EXCEEDED',
				'message' => 'Plan limit reached',
			)
		);
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );

		$this->assertSame(
			array(
				'code'    => 'PLAN_LIMIT_EXCEEDED',
				'message' => 'Plan limit reached',
			),
			$this->state()['error']
		);
	}

	/**
	 * 422 currency mismatch fails the copy.
	 */
	public function test_currency_422_fails_copy() {
		$this->fake->on( 'POST', self::BATCH, 422, array( 'code' => 'WOOCOMMERCE_CURRENCY_MISMATCH' ) );
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );

		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 'WOOCOMMERCE_CURRENCY_MISMATCH', $this->state()['error']['code'] );
	}

	/**
	 * 404 connection not found fails the copy and forgets the connection.
	 */
	public function test_connection_404_fails_and_forgets_connection() {
		$this->fake->on( 'POST', self::BATCH, 404, array( 'code' => 'WOOCOMMERCE_CONNECTION_NOT_FOUND' ) );
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );

		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 'WOOCOMMERCE_CONNECTION_NOT_FOUND', $this->state()['error']['code'] );
		$this->assertNull( Plugin::instance()->connection()->current() );
	}

	/**
	 * Signed out mid-copy fails it with SIGNED_OUT.
	 */
	public function test_signed_out_fails_with_signed_out() {
		$this->fake->on( 'POST', self::BATCH, 401, array( 'code' => 'UNAUTHORIZED' ) );
		$this->fake->on( 'POST', '/auth/session/refresh', 401, array( 'code' => 'SESSION_EXPIRED' ) );
		$this->fake->on( 'POST', '/auth/logout', 200 );
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );

		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 'SIGNED_OUT', $this->state()['error']['code'] );
	}

	/**
	 * The store connection disappeared locally: the copy fails without sending.
	 */
	public function test_missing_connection_fails_without_sending() {
		$id = $this->start( $this->products( 1 ) );
		delete_option( Connection::OPTION );
		$this->send( $id );

		$this->assertSame( array(), $this->fake->calls() );
		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 'WOOCOMMERCE_CONNECTION_NOT_FOUND', $this->state()['error']['code'] );
	}

	/**
	 * Products that vanished or became unsupported count as not_supported and leave the total (W26).
	 */
	public function test_unsupported_products_are_dropped_from_total() {
		$ids = $this->products( 3 );
		wp_trash_post( $ids[0] );
		wp_delete_post( $ids[1], true );
		$this->accepted();
		$id = $this->start( $ids );
		$this->send( $id );

		$state = $this->state();
		$this->assertSame( array( $ids[2] ), array_column( $this->body( $this->fake->calls( self::BATCH )[0] )['products'], 'id' ) );
		$this->assertSame( 2, $state['counts']['not_supported'] );
		$this->assertSame( 1, $state['total'] );
		$this->assertSame( array( $ids[2] ), $state['in_flight']['product_ids'] );
	}

	/**
	 * A whole batch of unsupported products moves on to the next without posting an empty batch.
	 */
	public function test_all_unsupported_completes_without_posting() {
		$ids = $this->products( 2 );
		wp_trash_post( $ids[0] );
		wp_trash_post( $ids[1] );
		$id = $this->start( $ids );
		$this->send( $id );

		$this->assertSame( 0, $this->fake->count( self::BATCH ) );
		$this->assertSame( 'completed', $this->state()['status'] );
		$this->assertSame( 0, $this->state()['total'] );
	}

	/**
	 * Upload mode out of time: nothing is posted, SEND is queued again, state unchanged.
	 */
	public function test_upload_mode_out_of_time_requeues_without_posting() {
		$this->connect( 'upload' );
		list( $p ) = $this->products( 1, array( 'image_id' => Product_Factory::attachment( 'tee-front.png' ) ) );
		$id        = $this->start( array( $p ) );
		$before    = $this->state();

		$this->send( $id, $this->runner( -1 ) );

		$this->assertSame( 0, $this->fake->count( self::BATCH ) );
		$this->assertSame( 0, $this->fake->count( self::UPLOAD ) );
		$this->assertTrue( $this->next( Copy_Runner::SEND_HOOK, $id ) );
		$this->assertSame( $before, $this->state() );
	}

	/**
	 * Every action gets its own upload budget from when it starts, so an old copy, or a rerun
	 * after running out of time, still attempts an upload (T8 ruling).
	 */
	public function test_each_action_starts_with_a_fresh_upload_deadline() {
		$this->connect( 'upload' );
		$att       = Product_Factory::attachment( 'tee-front.png' );
		list( $p ) = $this->products( 1, array( 'image_id' => $att ) );
		$this->fake->on( 'POST', self::UPLOAD, 201, array( 'asset' => array( 'id' => 'as_1' ) ) );
		$this->accepted();
		$id = $this->start( array( $p ) );
		$this->patch( array( 'started_at' => time() - 3600 ) );

		$this->send( $id, $this->runner( -1 ) );
		$this->assertSame( 0, $this->fake->count( self::UPLOAD ) );
		$this->send( $id );

		$this->assertSame( 1, $this->fake->count( self::UPLOAD ) );
		$this->assertSame( 1, $this->fake->count( self::BATCH ) );
		$images = $this->body( $this->fake->calls( self::BATCH )[0] )['products'][0]['images'];
		$this->assertSame(
			array(
				array(
					'attachment_id' => $att,
					'asset_id'      => 'as_1',
				),
			),
			$images
		);
	}

	/**
	 * A failed upload is remembered for the copy, and its local warning reaches the issue (W23, W25).
	 */
	public function test_failed_upload_saved_and_its_warning_reaches_the_issue() {
		$this->connect( 'upload' );
		$att       = Product_Factory::attachment( 'tee-front.png' );
		list( $p ) = $this->products( 1, array( 'image_id' => $att ) );
		$this->fake->on( 'POST', self::UPLOAD, 500, array( 'message' => 'no' ) );
		$this->accepted();
		$this->completed( 'j1', array( $this->result( $p, 'created' ) ) );
		$id = $this->start( array( $p ) );

		$this->send( $id );
		$this->assertSame( array( $att ), $this->state()['upload_failed'] );
		$this->assertSame( 'IMAGE_FAILED', $this->state()['in_flight']['local_warnings'][ $p ][0]['code'] );
		$this->poll( $id );

		$issue = $this->state()['issues'][ $p ];
		$this->assertSame( 'created', $issue['result'] );
		$this->assertSame( 'IMAGE_FAILED', $issue['warnings'][0]['code'] );
	}

	/**
	 * Cancel unschedules both hooks and marks the copy cancelled.
	 */
	public function test_cancel_unschedules_and_marks_cancelled() {
		$this->accepted();
		$id = $this->start( $this->products( 25 ) );
		$this->send( $id );
		as_schedule_single_action( time() + 30, Copy_Runner::SEND_HOOK, array( $id ), Copy_Runner::GROUP );
		$this->assertNotFalse( $this->next( Copy_Runner::POLL_HOOK, $id ) );

		Copy_Runner::cancel();

		$this->assertFalse( $this->next( Copy_Runner::SEND_HOOK, $id ) );
		$this->assertFalse( $this->next( Copy_Runner::POLL_HOOK, $id ) );
		$this->assertSame( 'cancelled', $this->state()['status'] );
		$this->assertIsInt( $this->state()['finished_at'] );
		$this->assertFalse( Copy_State::is_running() );
	}

	/**
	 * Cancel leaves a finished copy as it was.
	 */
	public function test_cancel_keeps_finished_copy() {
		$this->start( $this->products( 1 ) );
		$this->patch( array( 'status' => 'completed' ) );

		Copy_Runner::cancel();

		$this->assertSame( 'completed', $this->state()['status'] );
	}

	/**
	 * A cancel that lands while the batch POST is out isn't undone by the handler.
	 */
	public function test_cancel_during_send_is_not_overwritten() {
		$this->accepted();
		$id = $this->start( $this->products( 1 ) );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, '/products/batch' ) ) {
					Copy_Runner::cancel();
				}
				return $pre;
			},
			5,
			3
		);

		$this->send( $id );

		$this->assertSame( 'cancelled', $this->state()['status'] );
		$this->assertFalse( $this->next( Copy_Runner::POLL_HOOK, $id ) );
	}

	/**
	 * An action of this copy that dies is retried on the transient path, then the copy fails
	 * with COPY_INTERRUPTED (W24).
	 */
	public function test_failed_action_retries_then_copy_interrupted() {
		$runner = $this->runner();
		$id     = $this->start( $this->products( 1 ) );

		foreach ( array( 30, 60, 120, 240, 480 ) as $i => $delay ) {
			$action_id = as_enqueue_async_action( Copy_Runner::SEND_HOOK, array( $id ), Copy_Runner::GROUP );
			$this->consume( $id );
			if ( 0 === $i % 2 ) {
				$runner->on_action_failed( $action_id, new Exception( 'boom' ), 'WP Cron' );
			} else {
				$runner->on_action_failed( $action_id, array( 'message' => 'Maximum execution time exceeded' ) );
			}
			$this->assertSame( 'running', $this->state()['status'] );
			$this->assertEqualsWithDelta( time() + $delay, $this->next( Copy_Runner::SEND_HOOK, $id ), 2 );
		}
		$action_id = as_enqueue_async_action( Copy_Runner::SEND_HOOK, array( $id ), Copy_Runner::GROUP );
		$this->consume( $id );
		$runner->on_action_failed( $action_id, new Exception( 'boom' ), 'WP Cron' );

		$state = $this->state();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 'COPY_INTERRUPTED', $state['error']['code'] );
		$this->assertSame( 'The copy stopped unexpectedly on this site. Copy again; products already copied are skipped.', $state['error']['message'] );
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * A dead SEND after the batch was accepted retries the POLL, not the SEND.
	 */
	public function test_failed_action_with_job_in_flight_retries_poll() {
		$this->accepted();
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );
		$action_id = as_enqueue_async_action( Copy_Runner::SEND_HOOK, array( $id ), Copy_Runner::GROUP );
		$this->consume( $id );

		$this->runner()->on_action_failed( $action_id, new Exception( 'boom' ), 'WP Cron' );

		$this->assertEqualsWithDelta( time() + 30, $this->next( Copy_Runner::POLL_HOOK, $id ), 2 );
		$this->assertFalse( $this->next( Copy_Runner::SEND_HOOK, $id ) );
	}

	/**
	 * Other plugins' failed actions, and this plugin's stale ones, are ignored.
	 */
	public function test_failed_action_of_other_hook_or_copy_is_ignored() {
		$id     = $this->start( $this->products( 1 ) );
		$before = $this->state();
		$other  = as_enqueue_async_action( 'someone_else', array( $id ), 'other' );
		$stale  = as_enqueue_async_action( Copy_Runner::SEND_HOOK, array( 'old-id' ), Copy_Runner::GROUP );
		$group  = as_enqueue_async_action( Copy_Runner::SEND_HOOK, array( $id ), 'other' );

		$this->runner()->on_action_failed( $other, new Exception( 'x' ), '' );
		$this->runner()->on_action_failed( $stale, new Exception( 'x' ), '' );
		$this->runner()->on_action_failed( $group, 300 );

		$this->assertSame( $before, $this->state() );
	}

	/**
	 * A Fake_Api assertion inside a handler reaches PHPUnit: the runner doesn't swallow it.
	 */
	public function test_fake_api_failure_is_not_swallowed_by_handler() {
		$id = $this->start( $this->products( 1 ) );
		$this->expectException( AssertionFailedError::class );

		$this->send( $id );
	}

	/**
	 * The plugin hooks the handlers and the failure hooks.
	 */
	public function test_hooks_registered() {
		$runner = Plugin::instance()->runner();
		$this->assertSame( $runner, Plugin::instance()->runner() );
		$runner->register();

		$this->assertNotFalse( has_action( Copy_Runner::SEND_HOOK, array( $runner, 'handle_send' ) ) );
		$this->assertNotFalse( has_action( Copy_Runner::POLL_HOOK, array( $runner, 'handle_poll' ) ) );
		$this->assertNotFalse( has_action( 'action_scheduler_failed_execution', array( $runner, 'on_action_failed' ) ) );
		$this->assertNotFalse( has_action( 'action_scheduler_unexpected_shutdown', array( $runner, 'on_action_failed' ) ) );
		$this->assertNotFalse( has_action( 'action_scheduler_failed_action', array( $runner, 'on_action_failed' ) ) );
	}

	/**
	 * Copy_State::get reads past a stale object cache.
	 */
	public function test_state_get_bypasses_cache() {
		Copy_State::put( array( 'id' => 'a' ) );
		wp_cache_set( Copy_State::OPTION, array( 'id' => 'stale' ), 'options' );
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( array( 'id' => 'b' ) ) ), array( 'option_name' => Copy_State::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup

		$this->assertSame( array( 'id' => 'b' ), Copy_State::get() );
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Copy_State::OPTION ) ), array( 'no', 'off' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test
	}

	/**
	 * A worker killed outright is only reported by the queue cleaner's action_scheduler_failed_action
	 * (action id, timeout): it is retried like the other failures, then the copy is interrupted.
	 */
	public function test_queue_cleaner_failed_action_retries_then_copy_interrupted() {
		$runner = Plugin::instance()->runner();
		$runner->register();
		$id = $this->start( $this->products( 1 ) );

		foreach ( array( 30, 60, 120, 240, 480 ) as $delay ) {
			$action_id = as_enqueue_async_action( Copy_Runner::SEND_HOOK, array( $id ), Copy_Runner::GROUP );
			$this->consume( $id );
			do_action( 'action_scheduler_failed_action', $action_id, 300 );
			$this->assertSame( 'running', $this->state()['status'] );
			$this->assertEqualsWithDelta( time() + $delay, $this->next( Copy_Runner::SEND_HOOK, $id ), 2 );
		}
		$action_id = as_enqueue_async_action( Copy_Runner::SEND_HOOK, array( $id ), Copy_Runner::GROUP );
		$this->consume( $id );
		do_action( 'action_scheduler_failed_action', $action_id, 300 );

		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 'COPY_INTERRUPTED', $this->state()['error']['code'] );
		$this->assertFalse( $this->next( Copy_Runner::SEND_HOOK, $id ) );
	}

	/**
	 * Alternating 503 and 409 don't reset each other's count: the transient delays keep growing
	 * and the copy fails API_UNREACHABLE at the 6th 503.
	 */
	public function test_alternating_5xx_and_409_fails_within_budget() {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->fake->on( 'POST', self::BATCH, 503, array( 'message' => 'down' ) );
			$this->fake->on( 'POST', self::BATCH, 409, array( 'code' => 'WOOCOMMERCE_COPY_IN_PROGRESS' ) );
		}
		$id = $this->start( $this->products( 1 ) );

		foreach ( array( 30, 60, 120, 240, 480 ) as $delay ) {
			$this->send( $id );
			$this->assertEqualsWithDelta( time() + $delay, $this->next( Copy_Runner::SEND_HOOK, $id ), 2 );
			$this->send( $id );
			$this->assertEqualsWithDelta( time() + Copy_Runner::CONFLICT_DELAY, $this->next( Copy_Runner::SEND_HOOK, $id ), 2 );
		}
		$this->assertSame( 'running', $this->state()['status'] );
		$this->send( $id );

		$state = $this->state();
		$this->assertSame( 11, $this->fake->count( self::BATCH ) );
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 'API_UNREACHABLE', $state['error']['code'] );
		$this->assertFalse( $this->next( Copy_Runner::SEND_HOOK, $id ) );
	}

	/**
	 * The batch's retry budget counts every kind: after nine 409s, a 503 and a 409, the next 503
	 * is the 12th retry, past 2 x 5 for transient, so the copy fails with the last kind's code.
	 */
	public function test_total_retry_budget_fails_with_last_kind() {
		for ( $i = 0; $i < 9; $i++ ) {
			$this->fake->on( 'POST', self::BATCH, 409, array( 'code' => 'WOOCOMMERCE_COPY_IN_PROGRESS' ) );
		}
		$this->fake->on( 'POST', self::BATCH, 503, array( 'message' => 'down' ) );
		$this->fake->on( 'POST', self::BATCH, 409, array( 'code' => 'WOOCOMMERCE_COPY_IN_PROGRESS' ) );
		$this->fake->on( 'POST', self::BATCH, 503, array( 'message' => 'down' ) );
		$id = $this->start( $this->products( 1 ) );

		for ( $i = 0; $i < 11; $i++ ) {
			$this->send( $id );
			$this->assertSame( 'running', $this->state()['status'] );
		}
		$this->assertSame( 1, $this->state()['retry']['tries']['transient'] );
		$this->send( $id );

		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 'API_UNREACHABLE', $this->state()['error']['code'] );
	}

	/**
	 * A successful step resets the whole budget.
	 */
	public function test_success_resets_retry_budget() {
		$this->fake->on( 'POST', self::BATCH, 503, array( 'message' => 'down' ) );
		$this->fake->on( 'POST', self::BATCH, 409, array( 'code' => 'WOOCOMMERCE_COPY_IN_PROGRESS' ) );
		$this->accepted();
		$id = $this->start( $this->products( 1 ) );
		$this->send( $id );
		$this->send( $id );
		$this->send( $id );

		$retry = $this->state()['retry'];
		$this->assertSame( 0, $retry['total'] );
		$this->assertSame(
			array(
				'conflict'  => 0,
				'throttled' => 0,
				'transient' => 0,
			),
			$retry['tries']
		);
	}
}
