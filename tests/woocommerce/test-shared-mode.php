<?php
/**
 * Covers the shared mode state reader and its activation guard.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Shared_Mode;

class Shared_Mode_Test extends TestCase {

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
	 * Shared mode is off when the option was never set.
	 *
	 * @return void
	 */
	public function test_disabled_by_default() {
		$this->assertFalse( Shared_Mode::is_enabled() );
	}

	/**
	 * Shared mode reads the option.
	 *
	 * @return void
	 */
	public function test_enabled_when_option_is_true() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$this->assertTrue( Shared_Mode::is_enabled() );
	}

	/**
	 * Shared mode is off when Linguator is not available.
	 *
	 * @return void
	 */
	public function test_disabled_without_linguator() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );
		lmat_test_set( 'linguator', false );

		$this->assertFalse( Shared_Mode::is_enabled() );
	}

	/**
	 * A catalogue with no translated product produces no blockers.
	 *
	 * @return void
	 */
	public function test_no_blockers_on_a_clean_catalogue() {
		lmat_test_set( 'product_ids', array( 101, 102, 103 ) );
		lmat_test_set(
			'post_translations',
			array(
				101 => array( 'it' => 101 ),
				102 => array( 'it' => 102 ),
				103 => array(),
			)
		);

		$this->assertSame( array(), Shared_Mode::blockers() );
	}

	/**
	 * A product with a translation in another language is a blocker.
	 *
	 * @return void
	 */
	public function test_translated_products_are_blockers() {
		lmat_test_set( 'product_ids', array( 101, 102 ) );
		lmat_test_set(
			'post_translations',
			array(
				101 => array( 'it' => 101, 'de' => 999 ),
				102 => array( 'it' => 102 ),
			)
		);

		$this->assertSame( array( 101 ), Shared_Mode::blockers() );
	}

	/**
	 * A translation entry pointing back at the product itself is not a blocker.
	 *
	 * @return void
	 */
	public function test_self_reference_is_not_a_blocker() {
		lmat_test_set( 'product_ids', array( 101 ) );
		lmat_test_set( 'post_translations', array( 101 => array( 'it' => 101, 'de' => 101 ) ) );

		$this->assertSame( array(), Shared_Mode::blockers() );
	}

	/**
	 * The guard lets a disabling write through without scanning.
	 *
	 * @return void
	 */
	public function test_guard_allows_disabling() {
		lmat_test_set( 'product_ids', array( 101 ) );
		lmat_test_set( 'post_translations', array( 101 => array( 'it' => 101, 'de' => 999 ) ) );

		$this->assertTrue( Shared_Mode::guard( false ) );
	}

	/**
	 * The guard refuses to enable when blockers exist, and names them.
	 *
	 * @return void
	 */
	public function test_guard_refuses_enabling_with_blockers() {
		lmat_test_set( 'product_ids', array( 101 ) );
		lmat_test_set( 'post_translations', array( 101 => array( 'it' => 101, 'de' => 999 ) ) );

		$result = Shared_Mode::guard( true );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'lmat_wc_shared_identity_blocked', $result->get_error_code() );
		$this->assertSame( array( 'products' => array( 101 ) ), $result->get_error_data() );
	}

	/**
	 * The guard allows enabling on a clean catalogue.
	 *
	 * @return void
	 */
	public function test_guard_allows_enabling_without_blockers() {
		lmat_test_set( 'product_ids', array( 101 ) );
		lmat_test_set( 'post_translations', array( 101 => array( 'it' => 101 ) ) );

		$this->assertTrue( Shared_Mode::guard( true ) );
	}
}
