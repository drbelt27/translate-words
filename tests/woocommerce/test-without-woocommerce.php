<?php
/**
 * Covers the behaviour of the WooCommerce integration on a site without WooCommerce.
 *
 * Run with: LMAT_TEST_WITHOUT_WOOCOMMERCE=1
 *
 * @package Linguator
 */

use Linguator\Integrations\woocommerce\Linguator_WooCommerce;
use PHPUnit\Framework\TestCase;

class Without_WooCommerce_Test extends TestCase {

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! getenv( 'LMAT_TEST_WITHOUT_WOOCOMMERCE' ) ) {
			$this->markTestSkipped( 'This suite requires the WooCommerce stubs to be absent.' );
		}

		lmat_test_reset_state();
	}

	/**
	 * The WooCommerce functions really are undefined in this suite.
	 *
	 * @return void
	 */
	public function test_woocommerce_functions_are_undefined() {
		$this->assertFalse( function_exists( 'is_shop' ) );
		$this->assertFalse( function_exists( 'wc_get_page_id' ) );
	}

	/**
	 * Scenario 6: no fatal error and no change of behaviour without WooCommerce.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_without_fatal_error() {
		$integration = new Linguator_WooCommerce();

		$this->assertSame( '', $integration->shop_translation_url( '', (object) array( 'slug' => 'de' ) ) );
		$this->assertSame(
			'https://example.test/de/page/',
			$integration->shop_translation_url( 'https://example.test/de/page/', (object) array( 'slug' => 'de' ) )
		);
	}
}
