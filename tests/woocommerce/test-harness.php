<?php
/**
 * Verifies that the isolated unit harness itself behaves as the tests assume.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;

class Harness_Test extends TestCase {

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
	}

	/**
	 * The stub state is readable and writable.
	 *
	 * @return void
	 */
	public function test_state_round_trip() {
		lmat_test_set( 'shop_page_id', 42 );

		$this->assertSame( 42, lmat_test_get( 'shop_page_id' ) );
	}

	/**
	 * Permalinks are resolved from the simulated state and fail closed.
	 *
	 * @return void
	 */
	public function test_permalink_stub() {
		lmat_test_set( 'permalinks', array( 7 => 'https://example.test/de/shop-page/' ) );

		$this->assertSame( 'https://example.test/de/shop-page/', get_permalink( 7 ) );
		$this->assertFalse( get_permalink( 8 ) );
	}

	/**
	 * Post meta round-trips through the stub store.
	 *
	 * @return void
	 */
	public function test_post_meta_round_trip() {
		update_post_meta( 12, '_lmat_wc_i18n_de', array( 'revision' => 1 ) );

		$this->assertSame( array( 'revision' => 1 ), get_post_meta( 12, '_lmat_wc_i18n_de', true ) );
		$this->assertSame( '', get_post_meta( 12, '_lmat_wc_i18n_fr', true ) );
	}

	/**
	 * Term meta round-trips through the stub store.
	 *
	 * @return void
	 */
	public function test_term_meta_round_trip() {
		update_term_meta( 5, '_lmat_wc_i18n_de', array( 'revision' => 2 ) );

		$this->assertSame( array( 'revision' => 2 ), get_term_meta( 5, '_lmat_wc_i18n_de', true ) );
	}

	/**
	 * apply_filters() runs the callbacks add_filter() recorded, in priority order.
	 *
	 * @return void
	 */
	public function test_filters_execute_in_priority_order() {
		add_filter( 'lmat_test_hook', function ( $value ) { return $value . 'b'; }, 20 );
		add_filter( 'lmat_test_hook', function ( $value ) { return $value . 'a'; }, 10 );

		$this->assertSame( 'xab', apply_filters( 'lmat_test_hook', 'x' ) );
	}

	/**
	 * The simulated context flags drive the WordPress context functions.
	 *
	 * @return void
	 */
	public function test_context_flags() {
		$this->assertFalse( is_admin() );

		lmat_test_set( 'is_admin', true );

		$this->assertTrue( is_admin() );
	}

	/**
	 * The current language and the options are readable through the Linguator stub.
	 *
	 * @return void
	 */
	public function test_linguator_stub_exposes_language_and_options() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$this->assertSame( 'de', LMAT()->curlang->slug );
		$this->assertTrue( LMAT()->options['wc_shared_product_identity'] );
	}
}
