<?php
/**
 * Covers the single decision every display filter consults.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;

class Context_Gate_Test extends TestCase {

	/**
	 * The gate under test.
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$_REQUEST = array();
		$this->gate = new Context_Gate();
	}

	/**
	 * An ordinary front end request localizes.
	 *
	 * @return void
	 */
	public function test_frontend_request_localizes() {
		$this->assertTrue( $this->gate->should_localize() );
	}

	/**
	 * The admin reads canonical text, or an editor renames a product by accident.
	 *
	 * @return void
	 */
	public function test_admin_does_not_localize() {
		lmat_test_set( 'is_admin', true );

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * An admin-ajax request that is not a known WooCommerce front end action stays canonical.
	 *
	 * @return void
	 */
	public function test_admin_ajax_without_a_known_action_does_not_localize() {
		lmat_test_set( 'is_admin', true );
		lmat_test_set( 'doing_ajax', true );
		$_REQUEST['action'] = 'woocommerce_do_ajax_product_export';

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * WooCommerce front end AJAX localizes, because the customer is looking at the result.
	 *
	 * @return void
	 */
	public function test_woocommerce_frontend_ajax_localizes() {
		lmat_test_set( 'is_admin', true );
		lmat_test_set( 'doing_ajax', true );
		$_REQUEST['action'] = 'woocommerce_add_to_cart';

		$this->assertTrue( $this->gate->should_localize() );
	}

	/**
	 * Cron has no viewer, therefore no display language.
	 *
	 * @return void
	 */
	public function test_cron_does_not_localize() {
		lmat_test_set( 'doing_cron', true );

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * Order creation is suspended: the snapshot is written from the purchase language,
	 * resolved explicitly, never from ambient context.
	 *
	 * @return void
	 */
	public function test_order_context_does_not_localize() {
		$this->gate->suspend( Context_Gate::CONTEXT_ORDER );

		$this->assertFalse( $this->gate->should_localize() );

		$this->gate->resume( Context_Gate::CONTEXT_ORDER );

		$this->assertTrue( $this->gate->should_localize() );
	}

	/**
	 * Transactional email reads the frozen snapshot, not live translations.
	 *
	 * @return void
	 */
	public function test_email_context_does_not_localize() {
		$this->gate->suspend( Context_Gate::CONTEXT_EMAIL );

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * Suspensions nest: resuming one context does not lift another.
	 *
	 * @return void
	 */
	public function test_suspensions_are_independent() {
		$this->gate->suspend( Context_Gate::CONTEXT_ORDER );
		$this->gate->suspend( Context_Gate::CONTEXT_EMAIL );
		$this->gate->resume( Context_Gate::CONTEXT_ORDER );

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * REST stays canonical until Phase 3 exposes translations deliberately.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_rest_request_does_not_localize() {
		define( 'REST_REQUEST', true );

		$gate = new Context_Gate();

		$this->assertFalse( $gate->should_localize() );
	}

	/**
	 * WP-CLI has no viewer either.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_wp_cli_does_not_localize() {
		define( 'WP_CLI', true );

		$gate = new Context_Gate();

		$this->assertFalse( $gate->should_localize() );
	}
}
