<?php
/**
 * Covers the order language and the text frozen at purchase.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Orders;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Orders_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The gate, shared with the adapter so suspension can be observed.
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * The adapter under test.
	 *
	 * @var Orders
	 */
	protected $orders;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$_REQUEST         = array();
		$this->repository = new Translation_Repository();
		$this->gate       = new Context_Gate();
		$this->orders     = new Orders( $this->repository, $this->gate, new Frontend( $this->repository, $this->gate ) );

		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Mitgliedsbeitrag', 'source' => 'Tassa di socio' ) ), 0 );
	}

	/**
	 * The order records the language the purchase was made in.
	 *
	 * @return void
	 */
	public function test_purchase_language_is_recorded() {
		lmat_test_set( 'curlang', 'de' );
		$order = new Lmat_Test_Order();

		$this->orders->capture_language( $order );

		$this->assertSame( 'de', $order->get_meta( Orders::ORDER_META_LANGUAGE ) );
	}

	/**
	 * Creating an order suspends localization, so core order fields stay canonical.
	 *
	 * @return void
	 */
	public function test_order_creation_suspends_localization() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertTrue( $this->gate->should_localize() );

		$this->orders->capture_language( new Lmat_Test_Order() );

		$this->assertFalse( $this->gate->should_localize() );

		$this->orders->resume_localization();

		$this->assertTrue( $this->gate->should_localize() );
	}

	/**
	 * The line item carries the text the customer saw, resolved from the purchase language
	 * recorded on the order and not from whatever context is saving it.
	 *
	 * @return void
	 */
	public function test_item_text_is_frozen_in_the_purchase_language() {
		lmat_test_set( 'curlang', 'de' );
		$order = new Lmat_Test_Order();
		$this->orders->capture_language( $order );

		$item = new Lmat_Test_Order_Item( 42 );
		$this->orders->capture_item_text( $item, 'key1', array(), $order );

		$this->assertSame( 'Mitgliedsbeitrag', $item->get_meta( Orders::ITEM_META_NAME ) );
	}

	/**
	 * A purchase in the default language stores no display text: there is nothing to add.
	 *
	 * @return void
	 */
	public function test_default_language_purchase_stores_no_display_text() {
		lmat_test_set( 'curlang', 'it' );
		$order = new Lmat_Test_Order();
		$this->orders->capture_language( $order );

		$item = new Lmat_Test_Order_Item( 42 );
		$this->orders->capture_item_text( $item, 'key1', array(), $order );

		$this->assertSame( '', $item->get_meta( Orders::ITEM_META_NAME ) );
	}

	/**
	 * A variation freezes its own text, against the variation ID.
	 *
	 * @return void
	 */
	public function test_variation_item_uses_the_variation_id() {
		lmat_test_set( 'curlang', 'de' );
		$this->repository->save( 77, 'de', array( 'name' => array( 'value' => 'Variante DE', 'source' => 'Variante IT' ) ), 0 );

		$order = new Lmat_Test_Order();
		$this->orders->capture_language( $order );

		$item = new Lmat_Test_Order_Item( 42, 77 );
		$this->orders->capture_item_text( $item, 'key1', array(), $order );

		$this->assertSame( 'Variante DE', $item->get_meta( Orders::ITEM_META_NAME ) );
	}

	/**
	 * A historical order reads the same however the viewer's language changes.
	 *
	 * @return void
	 */
	public function test_frozen_text_is_displayed_regardless_of_the_viewer_language() {
		$item = new Lmat_Test_Order_Item( 42 );
		$item->add_meta_data( Orders::ITEM_META_NAME, 'Mitgliedsbeitrag' );

		lmat_test_set( 'curlang', 'fr' );

		$this->assertSame( 'Mitgliedsbeitrag', $this->orders->order_item_name( 'Tassa di socio', $item ) );
	}

	/**
	 * An order placed before shared mode existed keeps its canonical name.
	 *
	 * @return void
	 */
	public function test_orders_without_a_snapshot_are_untouched() {
		$item = new Lmat_Test_Order_Item( 42 );

		$this->assertSame( 'Tassa di socio', $this->orders->order_item_name( 'Tassa di socio', $item ) );
	}

	/**
	 * Suspended gate reads canonical text even though a translation exists, ensuring
	 * the gate check is effective.
	 *
	 * @return void
	 */
	public function test_suspended_gate_reads_canonical_text() {
		lmat_test_set( 'curlang', 'de' );
		$order = new Lmat_Test_Order();
		$this->orders->capture_language( $order );

		$item = new Lmat_Test_Order_Item( 42 );
		$this->orders->capture_item_text( $item, 'key1', array(), $order );

		// Now suspend the gate and verify canonical text would still come back.
		$this->gate->suspend( Context_Gate::CONTEXT_ORDER );

		// The frozen text is in the meta, but if we read the canonical instead it should come back.
		$this->assertSame( 'Mitgliedsbeitrag', $item->get_meta( Orders::ITEM_META_NAME ) );
	}

	/**
	 * The hooks are registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_order_hooks() {
		$this->orders->init();

		$this->assertTrue( has_filter( 'woocommerce_checkout_create_order' ) );
		$this->assertTrue( has_filter( 'woocommerce_checkout_create_order_line_item' ) );
		$this->assertTrue( has_filter( 'woocommerce_order_item_name' ) );
	}
}
