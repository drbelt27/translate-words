<?php
/**
 * Covers the WooCommerce integration coordinator's hook registration.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Linguator_WooCommerce;

class Coordinator_Test extends TestCase {

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
	 * The activation guard is registered whether or not shared mode is on, because it is what
	 * decides that shared mode may be turned on in the first place.
	 *
	 * @return void
	 */
	public function test_guard_is_always_registered() {
		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'lmat_wc_shared_identity_guard' ) );
	}

	/**
	 * With shared mode off, the switcher fix is registered and nothing else is.
	 *
	 * @return void
	 */
	public function test_shared_mode_off_registers_only_the_switcher_fix() {
		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'lmat_pre_translation_url' ) );
		$this->assertFalse( has_filter( 'lmat_get_post_types' ) );
		$this->assertFalse( has_filter( 'woocommerce_product_get_name' ) );
	}

	/**
	 * With shared mode on, the identity filters are registered.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_identity_filters() {
		$this->markTestIncomplete( 'Identity adapter arrives in Task 6.' );

		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'lmat_get_post_types' ) );
		$this->assertTrue( has_filter( 'lmat_get_taxonomies' ) );
	}
}
