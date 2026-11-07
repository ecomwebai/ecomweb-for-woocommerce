<?php
/**
 * The WooCommerce → ecomweb admin page.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Page
 */
class Admin_Page {

	const SLUG       = 'ecomweb';
	const CAPABILITY = 'manage_woocommerce';
	const HOOK       = 'woocommerce_page_ecomweb';
	const ACTIONS    = array(
		'ecomweb_select_store'   => 'handle_select_store',
		'ecomweb_change_store'   => 'handle_change_store',
		'ecomweb_disconnect'     => 'handle_disconnect',
		'ecomweb_recheck_images' => 'handle_recheck_images',
	);
	const FLASH      = 'ecomweb_notice_';

	/**
	 * Auth.
	 *
	 * @var Auth
	 */
	private $auth;

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook = self::HOOK;

	/**
	 * Constructor.
	 *
	 * @param Auth            $auth       Auth.
	 * @param Connection|null $connection Connection; the shared one by default.
	 */
	public function __construct( Auth $auth, ?Connection $connection = null ) {
		$this->auth       = $auth;
		$this->connection = $connection ?? Plugin::instance()->connection();
	}

	/**
	 * Hooks the menu and the assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		foreach ( self::ACTIONS as $action => $handler ) {
			add_action( 'admin_post_' . $action, array( $this, $handler ) );
		}
	}

	/**
	 * Adds WooCommerce → ecomweb.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$hook = add_submenu_page( 'woocommerce', 'ecomweb', 'ecomweb', self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
		if ( is_string( $hook ) ) {
			$this->hook = $hook;
		}
	}

	/**
	 * The page URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Loads the script and style, on this page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'ecomweb-admin', plugins_url( 'assets/css/admin.css', ECOMWEB_WOO_FILE ), array(), ECOMWEB_WOO_VERSION );
		wp_enqueue_script( 'ecomweb-admin', plugins_url( 'assets/js/admin.js', ECOMWEB_WOO_FILE ), array(), ECOMWEB_WOO_VERSION, true );
		wp_localize_script(
			'ecomweb-admin',
			'ecomwebWoo',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE_ACTION ),
				'i18n'    => array(
					'starting' => __( 'Starting…', 'ecomweb-for-woocommerce' ),
					'waiting'  => __( 'Waiting for you to approve in ecomweb…', 'ecomweb-for-woocommerce' ),
					'approved' => __( 'Signed in. Loading…', 'ecomweb-for-woocommerce' ),
					'denied'   => __( 'Sign-in was denied. Click Sign in with ecomweb to try again.', 'ecomweb-for-woocommerce' ),
					'expired'  => __( 'The code expired. Click Sign in with ecomweb to try again.', 'ecomweb-for-woocommerce' ),
					'error'    => __( 'Something went wrong. Click Sign in with ecomweb to try again.', 'ecomweb-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Renders the page for the current state.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ecomweb-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		// is_signed_in() first: it may sign out a moved site and record the reason.
		$signed_in = $this->auth->is_signed_in();
		$reason    = $signed_in ? null : $this->auth->signed_out_reason();
		$resume    = ! $signed_in && false !== get_transient( Auth::DEVICE_TRANSIENT . get_current_user_id() );
		$notice    = $this->reason_message( $reason );
		$flashes   = $this->notices();

		echo '<div class="wrap ecomweb-wrap"><h1>' . esc_html__( 'ecomweb', 'ecomweb-for-woocommerce' ) . '</h1>';
		foreach ( $flashes as $flash ) {
			echo '<div class="notice notice-' . esc_attr( $flash[0] ) . ' inline"><p>' . esc_html( $flash[1] ) . '</p></div>';
		}
		if ( $signed_in && null === $this->connection->current() ) {
			$stores  = $this->connection->list_stores();
			$email   = (string) $this->auth->email();
			$changed = isset( $_GET['notice'] ) && 'changed_store' === sanitize_key( wp_unslash( $_GET['notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which warning to show.
			require ECOMWEB_WOO_DIR . 'includes/views/page-pick-store.php';
		} elseif ( $signed_in ) {
			// Connected (Task 11) and confirm (Task 9) add their views here.
			echo '<p>' . esc_html__( 'Not available yet', 'ecomweb-for-woocommerce' ) . '</p>';
		} else {
			require ECOMWEB_WOO_DIR . 'includes/views/page-signed-out.php';
		}
		echo '</div>';
	}

	/**
	 * Leaves a notice for the next page load, for messages a redirect can't carry (W20).
	 *
	 * @param string $key  Notice key.
	 * @param array  $args Values for the message.
	 * @return void
	 */
	public static function flash( string $key, array $args = array() ): void {
		set_transient(
			self::FLASH . get_current_user_id(),
			array(
				'key'  => $key,
				'args' => $args,
			),
			60
		);
	}

	/**
	 * Notices to show: the one in the redirect, then the stored one. The stored one is used once.
	 *
	 * @return array List of [class, message].
	 */
	private function notices(): array {
		$out     = array();
		$pending = array();
		if ( isset( $_GET['notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a fixed message.
			$pending[] = array(
				'key'  => sanitize_key( wp_unslash( $_GET['notice'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'args' => array(),
			);
		}
		$key    = self::FLASH . get_current_user_id();
		$stored = get_transient( $key );
		if ( is_array( $stored ) && isset( $stored['key'] ) ) {
			$pending[] = array(
				'key'  => (string) $stored['key'],
				'args' => (array) ( $stored['args'] ?? array() ),
			);
		}
		delete_transient( $key );

		foreach ( $pending as $item ) {
			$arg = (string) ( $item['args'][0] ?? '' );
			switch ( $item['key'] ) {
				case 'copy_running':
					$out[] = array( 'warning', __( 'Wait for the current copy to finish.', 'ecomweb-for-woocommerce' ) );
					break;
				case 'pick_store':
					$out[] = array( 'warning', __( 'Choose a store first.', 'ecomweb-for-woocommerce' ) );
					break;
				case 'no_access':
					$out[] = array( 'error', __( "You don't have access to that store in ecomweb.", 'ecomweb-for-woocommerce' ) );
					break;
				case 'lost_access':
					/* translators: %s: store name. */
					$out[] = array( 'error', sprintf( __( 'You no longer have access to %s in ecomweb.', 'ecomweb-for-woocommerce' ), $arg ) );
					break;
				case 'throttled':
					$out[] = array( 'warning', __( 'Checked too often. Try again in a minute.', 'ecomweb-for-woocommerce' ) );
					break;
				case 'images_fetch':
					$out[] = array( 'success', __( 'ecomweb can fetch your product images from this site.', 'ecomweb-for-woocommerce' ) );
					break;
				case 'images_upload':
					$out[] = array( 'success', __( 'Product images will be uploaded from this site.', 'ecomweb-for-woocommerce' ) );
					break;
				case 'api_error':
					$out[] = array( 'error', '' !== $arg ? $arg : __( 'Something went wrong. Try again.', 'ecomweb-for-woocommerce' ) );
					break;
			}
		}
		return $out;
	}

	/**
	 * Capability, then nonce. Either failure ends the request.
	 *
	 * @param string $action Action and nonce name.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ecomweb-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirects back to the page and ends the request.
	 *
	 * @param string $notice Notice key; empty for none.
	 * @return void
	 */
	private function finish( string $notice = '' ): void {
		wp_safe_redirect( self::url( '' === $notice ? array() : array( 'notice' => $notice ) ) );
		exit;
	}

	/**
	 * Redirects back, with the error as a message.
	 *
	 * @param \WP_Error $error Error.
	 * @return void
	 */
	private function finish_with_error( \WP_Error $error ): void {
		switch ( $error->get_error_code() ) {
			case 'ecomweb_no_access':
				$this->finish( 'no_access' );
				break;
			case 'ecomweb_throttled':
				$this->finish( 'throttled' );
				break;
			case 'ecomweb_signed_out':
				$this->finish();
				break;
		}
		self::flash( 'api_error', array( $error->get_error_message() ) );
		$this->finish();
	}

	/**
	 * Registers this site with the chosen store.
	 *
	 * @return void
	 */
	public function handle_select_store(): void {
		$this->guard( 'ecomweb_select_store' );
		$ref = isset( $_POST['store_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['store_ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		if ( '' === $ref ) {
			$this->finish( 'pick_store' );
		}
		$result = $this->connection->register( $ref );
		if ( is_wp_error( $result ) ) {
			$this->finish_with_error( $result );
		}
		$this->finish();
	}

	/**
	 * Back to the store picker. Only the connection is dropped: sign-in and copied-product meta stay.
	 *
	 * @return void
	 */
	public function handle_change_store(): void {
		$this->guard( 'ecomweb_change_store' );
		if ( Copy_State::is_running() ) {
			$this->finish( 'copy_running' );
		}
		$this->connection->forget();
		$this->finish( 'changed_store' );
	}

	/**
	 * Cancels a running copy, signs out and forgets the connection. Copied-product meta is kept.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		$this->guard( 'ecomweb_disconnect' );
		Copy_Runner::cancel();
		$this->auth->sign_out();
		$this->connection->forget();
		$this->finish();
	}

	/**
	 * Runs the image probe again.
	 *
	 * @return void
	 */
	public function handle_recheck_images(): void {
		$this->guard( 'ecomweb_recheck_images' );
		$result = $this->connection->recheck_images();
		if ( is_wp_error( $result ) ) {
			$this->finish_with_error( $result );
		}
		$this->finish( 'images_' . $result );
	}

	/**
	 * The notice for a signed-out reason.
	 *
	 * @param string|null $reason Reason.
	 * @return string
	 */
	private function reason_message( ?string $reason ): string {
		switch ( $reason ) {
			case 'revoked':
				return __( 'Signed out of ecomweb. Sign in again.', 'ecomweb-for-woocommerce' );
			case 'salts_changed':
				return __( "Your WordPress security keys changed, so the saved sign-in can't be read. Sign in again.", 'ecomweb-for-woocommerce' );
			case 'site_moved':
				return __( "This site's address changed, so you were signed out of ecomweb. Sign in again.", 'ecomweb-for-woocommerce' );
		}
		return '';
	}
}
