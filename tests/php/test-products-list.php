<?php
/**
 * Products_List tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Connection;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Product_Meta;
use Ecomweb\Woo\Products_List;
use Ecomweb\Woo\Selection;

/**
 * Class Test_Products_List
 */
class Test_Products_List extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Products_List
	 */
	private $list;

	/**
	 * Fake API.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Set up: admin user, connection c1.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->fake = Fake_Api::start();
		$this->connect( true );
		$this->list = new Products_List( Plugin::instance()->connection() );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		unset( $_GET['ecomweb_copied'] );
		parent::tear_down();
	}

	/**
	 * Saves or removes the connection.
	 *
	 * @param bool $on Connected.
	 */
	private function connect( bool $on ) {
		if ( $on ) {
			update_option(
				Connection::OPTION,
				array(
					'connection_id' => 'c1',
					'store'         => array(
						'id'  => 's1',
						'ref' => 's1',
					),
				)
			);
		} else {
			delete_option( Connection::OPTION );
		}
	}

	/**
	 * A product.
	 *
	 * @param string $conn Connection meta, or empty for none.
	 * @param string $url  URL meta.
	 * @return int
	 */
	private function product( string $conn = '', string $url = '' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Tee' );
		$id = $product->save();
		if ( '' !== $conn ) {
			update_post_meta( $id, Product_Meta::PRODUCT_ID, 'E' . $id );
			update_post_meta( $id, Product_Meta::CONNECTION_ID, $conn );
			update_post_meta( $id, Product_Meta::PRODUCT_URL, $url );
		}
		return $id;
	}

	/**
	 * Column output.
	 *
	 * @param int $id Product.
	 * @return string
	 */
	private function column( int $id ): string {
		ob_start();
		$this->list->render_column( 'ecomweb', $id );
		return (string) ob_get_clean();
	}

	/**
	 * Column places itself after the name.
	 */
	public function test_column_added_after_name() {
		$cols = $this->list->add_column(
			array(
				'cb'    => '',
				'name'  => 'Name',
				'price' => 'Price',
			)
		);
		$this->assertSame( array( 'cb', 'name', 'ecomweb', 'price' ), array_keys( $cols ) );
	}

	/**
	 * Only the current connection counts.
	 */
	public function test_column_shows_copied_link_for_current_connection_only() {
		$a = $this->product( 'c1', 'https://ecomweb.app/store/s1/products/E1' );
		$b = $this->product( 'c0', 'https://ecomweb.app/store/s1/products/E2' );
		$c = $this->product();

		$html = $this->column( $a );
		$this->assertStringContainsString( 'Copied', $html );
		$this->assertStringContainsString( 'href="https://ecomweb.app/store/s1/products/E1"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertSame( '&ndash;', $this->column( $b ) );
		$this->assertSame( '&ndash;', $this->column( $c ) );
		$this->connect( false );
		$this->assertSame( '&ndash;', $this->column( $a ) );
	}

	/**
	 * Only http(s) links.
	 */
	public function test_column_links_limited_to_http() {
		$id = $this->product( 'c1', 'javascript:alert(1)' );
		update_post_meta( $id, Product_Meta::PRODUCT_URL, 'javascript:alert(1)' );
		$html = $this->column( $id );
		$this->assertStringNotContainsString( 'href', $html );
		$this->assertStringContainsString( 'Copied', $html );
	}

	/**
	 * No HTTP.
	 */
	public function test_column_makes_no_http_calls() {
		$ids = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$ids[] = $this->product( 0 === $i % 2 ? 'c1' : 'c0', 'https://ecomweb.app/p/' . $i );
		}
		foreach ( $ids as $id ) {
			$this->column( $id );
		}
		ob_start();
		$this->list->render_filter( 'product' );
		ob_end_clean();
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * Filter select.
	 */
	public function test_filter_select_needs_connection() {
		ob_start();
		$this->list->render_filter( 'product' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="ecomweb_copied"', $html );
		$this->assertStringContainsString( 'value="yes"', $html );
		$this->assertStringContainsString( 'Not copied', $html );

		$this->connect( false );
		ob_start();
		$this->list->render_filter( 'product' );
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * IDs the main product list query returns for a filter value.
	 *
	 * @param string $value Filter value.
	 * @return int[]
	 */
	private function filtered( string $value ): array {
		global $pagenow, $wp_the_query;
		$pagenow = 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		set_current_screen( 'edit-product' );
		$_GET['ecomweb_copied'] = $value;
		add_action( 'pre_get_posts', array( $this->list, 'filter_query' ) );
		$q            = new WP_Query();
		$wp_the_query = $q; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$ids          = $q->query(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		remove_action( 'pre_get_posts', array( $this->list, 'filter_query' ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Yes and no.
	 */
	public function test_filter_yes_and_no() {
		$a = $this->product( 'c1', 'https://ecomweb.app/a' );
		$b = $this->product( 'c0', 'https://ecomweb.app/b' );
		$c = $this->product();

		$this->assertSame( array( $a ), $this->filtered( 'yes' ) );
		$this->assertSame( array( $b, $c ), $this->filtered( 'no' ) );
		$this->assertSame( array( $a, $b, $c ), $this->filtered( 'bogus' ) );
		$this->connect( false );
		$this->assertSame( array( $a, $b, $c ), $this->filtered( 'yes' ) );
	}

	/**
	 * Bulk action listed.
	 */
	public function test_bulk_action_listed() {
		$this->assertSame( 'Copy to EcomWeb', $this->list->add_bulk_action( array() )['ecomweb_copy'] );
	}

	/**
	 * Users who can't manage WooCommerce don't see the bulk action.
	 */
	public function test_bulk_action_hidden_without_manage_woocommerce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( array( 'trash' => 'Move to Trash' ), $this->list->add_bulk_action( array( 'trash' => 'Move to Trash' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertArrayHasKey( 'ecomweb_copy', $this->list->add_bulk_action( array() ) );
	}

	/**
	 * Explicit selection and redirect.
	 */
	public function test_bulk_action_saves_explicit_selection_and_redirects_to_confirm() {
		$simple  = $this->product();
		$grouped = ( new WC_Product_Grouped() );
		$gid     = $grouped->save();

		$url = $this->list->handle_bulk_action( 'edit.php', 'ecomweb_copy', array( (string) $simple, (string) $gid ) );
		$this->assertStringContainsString( 'page=ecomweb', $url );
		$this->assertStringContainsString( 'view=confirm', $url );
		$this->assertSame(
			array(
				'ids'           => array( $simple ),
				'explicit'      => true,
				'not_supported' => 1,
			),
			Selection::get( get_current_user_id() )
		);
	}

	/**
	 * Capability.
	 */
	public function test_bulk_action_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		$this->list->handle_bulk_action( 'edit.php', 'ecomweb_copy', array( $this->product() ) );
	}

	/**
	 * No connection.
	 */
	public function test_bulk_action_without_connection_redirects_with_notice() {
		$this->connect( false );
		$url = $this->list->handle_bulk_action( 'edit.php', 'ecomweb_copy', array( $this->product() ) );
		$this->assertStringContainsString( 'notice=not_connected', $url );
		$this->assertStringContainsString( 'page=ecomweb', $url );
		$this->assertNull( Selection::get( get_current_user_id() ) );
	}

	/**
	 * Other actions.
	 */
	public function test_other_bulk_actions_untouched() {
		$this->assertSame( 'edit.php?x=1', $this->list->handle_bulk_action( 'edit.php?x=1', 'trash', array( $this->product() ) ) );
		$this->assertNull( Selection::get( get_current_user_id() ) );
	}
}
