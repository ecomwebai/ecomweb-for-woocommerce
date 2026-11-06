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

	/**
	 * Auth.
	 *
	 * @var Auth
	 */
	private $auth;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook = self::HOOK;

	/**
	 * Constructor.
	 *
	 * @param Auth $auth Auth.
	 */
	public function __construct( Auth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Hooks the menu and the assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
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

		echo '<div class="wrap ecomweb-wrap"><h1>' . esc_html__( 'ecomweb', 'ecomweb-for-woocommerce' ) . '</h1>';
		if ( $signed_in ) {
			// Pick store (Task 6), connected (Task 11) and confirm (Task 9) add their views here.
			echo '<p>' . esc_html__( 'Not available yet', 'ecomweb-for-woocommerce' ) . '</p>';
		} else {
			require ECOMWEB_WOO_DIR . 'includes/views/page-signed-out.php';
		}
		echo '</div>';
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
