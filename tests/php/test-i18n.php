<?php
/**
 * Translation tests.
 *
 * @package ecomweb-for-woocommerce
 */

/**
 * Class Test_I18n
 */
class Test_I18n extends WP_UnitTestCase {

	const DOMAIN = 'ecomweb-for-woocommerce';

	/**
	 * The fixed UI labels (en => vi).
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			array( 'ecomweb', 'ecomweb' ),
			array( 'Sign in with ecomweb', 'Đăng nhập bằng ecomweb' ),
			array( "ecomweb will show your web host's location, not yours.", 'ecomweb sẽ hiển thị vị trí của nhà cung cấp hosting, không phải vị trí của bạn.' ),
			array( 'Copy all products not copied yet', 'Sao chép tất cả sản phẩm chưa sao chép' ),
			array( 'Overwrite products already copied', 'Ghi đè sản phẩm đã sao chép' ),
			array( 'Change store', 'Đổi cửa hàng' ),
			array( 'Disconnect', 'Ngắt kết nối' ),
			array( 'Stop copy', 'Dừng sao chép' ),
			array( 'Recheck images', 'Kiểm tra lại hình ảnh' ),
			array( 'Copy to ecomweb', 'Sao chép sang ecomweb' ),
			array( 'Copied', 'Đã sao chép' ),
			array( 'Not copied', 'Chưa sao chép' ),
			array( 'Only the store owner or an admin can copy products', 'Chỉ chủ cửa hàng hoặc quản trị viên mới có thể sao chép sản phẩm' ),
			array( 'Signed out of ecomweb. Sign in again.', 'Đã đăng xuất khỏi ecomweb. Hãy đăng nhập lại.' ),
			array( 'Signed in as %s', 'Đã đăng nhập với tài khoản %s' ),
			array( 'Everyone who can manage WooCommerce on this site can copy products as this ecomweb account.', 'Mọi người có quyền quản lý WooCommerce trên trang này đều có thể sao chép sản phẩm bằng tài khoản ecomweb này.' ),
			array( "Your WordPress security keys changed, so the saved sign-in can't be read. Sign in again.", 'Khóa bảo mật WordPress đã thay đổi nên không đọc được phiên đăng nhập đã lưu. Hãy đăng nhập lại.' ),
			array( "This site's address changed, so you were signed out of ecomweb. Sign in again.", 'Địa chỉ trang đã thay đổi nên bạn đã bị đăng xuất khỏi ecomweb. Hãy đăng nhập lại.' ),
			array( 'You no longer have access to %s in ecomweb.', 'Bạn không còn quyền truy cập %s trên ecomweb.' ),
			array( 'Copy the first %d', 'Sao chép %d sản phẩm đầu tiên' ),
			array( 'On sale in WooCommerce; regular price %s was not copied', 'Đang giảm giá trên WooCommerce; giá gốc %s chưa được sao chép' ),
			array( 'Refresh', 'Làm mới' ),
			array( 'Use this store', 'Dùng cửa hàng này' ),
			array( 'Create a store', 'Tạo cửa hàng' ),
			array( 'Start copying', 'Bắt đầu sao chép' ),
			array( 'Upgrade your plan', 'Nâng cấp gói' ),
			array( 'Cancel', 'Hủy' ),
		);
	}

	/**
	 * Path of a languages file.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function lang_file( $name ) {
		return dirname( __DIR__, 2 ) . '/languages/' . $name;
	}

	/**
	 * Msgids and msgstrs of a .po/.pot file.
	 *
	 * @param string $name File name.
	 * @return array msgid => msgstr (header entry excluded).
	 */
	private function entries( $name ) {
		$text    = (string) file_get_contents( $this->lang_file( $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$entries = array();
		preg_match_all( '/^msgid "(.*)"\nmsgstr "(.*)"$/m', $text, $m, PREG_SET_ORDER );
		foreach ( $m as $row ) {
			if ( '' !== $row[1] ) {
				$entries[ stripcslashes( $row[1] ) ] = stripcslashes( $row[2] );
			}
		}
		return $entries;
	}

	/**
	 * The vi labels come through the compiled .mo.
	 */
	public function test_vi_labels() {
		$mo = $this->lang_file( 'ecomweb-for-woocommerce-vi.mo' );
		$this->assertFileExists( $mo );
		$this->assertTrue( load_textdomain( self::DOMAIN, $mo, 'vi' ) );
		switch_to_locale( 'vi' );
		foreach ( self::labels() as $row ) {
			$this->assertSame( $row[1], __( $row[0], self::DOMAIN ), $row[0] ); // phpcs:ignore WordPress.WP.I18n
		}
		restore_previous_locale();
		unload_textdomain( self::DOMAIN );
	}

	/**
	 * The .po pins the same labels as the table.
	 */
	public function test_po_matches_label_table() {
		$po = $this->entries( 'ecomweb-for-woocommerce-vi.po' );
		foreach ( self::labels() as $row ) {
			$this->assertArrayHasKey( $row[0], $po, $row[0] );
			$this->assertSame( $row[1], $po[ $row[0] ], $row[0] );
		}
	}

	/**
	 * Every entry is translated, and the printf placeholders survive.
	 */
	public function test_po_has_no_untranslated_entries() {
		$po = $this->entries( 'ecomweb-for-woocommerce-vi.po' );
		$this->assertNotEmpty( $po );
		foreach ( $po as $id => $str ) {
			$this->assertNotSame( '', $str, $id );
			preg_match_all( '/%(?:\d+\$)?[sd]/', $id, $a );
			preg_match_all( '/%(?:\d+\$)?[sd]/', $str, $b );
			$this->assertEqualsCanonicalizing( $a[0], $b[0], $id );
		}
	}

	/**
	 * The .pot and the .po list the same strings, and the .pot has every label.
	 */
	public function test_pot_contains_every_label() {
		$pot = $this->entries( 'ecomweb-for-woocommerce.pot' );
		foreach ( self::labels() as $row ) {
			$this->assertArrayHasKey( $row[0], $pot, $row[0] );
		}
		$this->assertSame( array_keys( $pot ), array_keys( $this->entries( 'ecomweb-for-woocommerce-vi.po' ) ) );
	}
}
