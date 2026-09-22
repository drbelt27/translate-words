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
	 * With shared mode on, the product name getter is filtered: this is the on/off half of the
	 * guard, paired with the absence assertion in test_shared_mode_off_registers_only_the_switcher_fix().
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_frontend() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'woocommerce_product_get_name' ) );
	}

	/**
	 * With shared mode on, the identity filters are registered.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_identity_filters() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'lmat_get_post_types' ) );
		$this->assertTrue( has_filter( 'lmat_get_taxonomies' ) );
	}

	/**
	 * With shared mode on, the localization context gate is wired in too: Context_Gate::init()
	 * registers `woocommerce_email_header`, and a regression that dropped `$this->gate->init()`
	 * from register_shared_mode() would defeat email-context suspension silently.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_context_gate() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'woocommerce_email_header' ) );
	}
}
