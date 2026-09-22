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
	 * Order line-item creation genuinely suspends localization while WooCommerce would be reading
	 * the canonical product name, and resumes it once that item's own text has been frozen.
	 *
	 * This exercises the real gate consumer, Frontend::product_name(), through the actual
	 * suspend/resume pair the production hooks call — not Context_Gate::should_localize() in
	 * isolation. A version of this test that only poked should_localize() directly would keep
	 * passing even if the should_localize() check were deleted from Frontend::localize(), because
	 * it would never observe what a caller downstream of the gate actually sees.
	 *
	 * @return void
	 */
	public function test_order_creation_suspends_localization() {
		lmat_test_set( 'curlang', 'de' );

		$frontend = new Frontend( $this->repository, $this->gate );
		$product  = new Lmat_Test_Product( 42 );

		// Before any line item is built, localization works normally.
		$this->assertSame( 'Mitgliedsbeitrag', $frontend->product_name( 'Tassa di socio', $product ) );

		$item = $this->orders->suspend_for_line_item( new Lmat_Test_Order_Item( 42 ) );

		// Suspended: this is the exact moment WC_Checkout::create_order_line_items() reads
		// $product->get_name() to set the canonical order item name (WooCommerce core,
		// includes/class-wc-checkout.php, inside the loop in create_order_line_items()).
		$this->assertSame( 'Tassa di socio', $frontend->product_name( 'Tassa di socio', $product ) );

		$order = new Lmat_Test_Order();
		$this->orders->capture_item_text( $item, 'key1', array(), $order );

		// Resumed once this item's own text has been frozen.
		$this->assertSame( 'Mitgliedsbeitrag', $frontend->product_name( 'Tassa di socio', $product ) );

		// And the frozen text -- not the canonical one -- is what a viewer of this order sees.
		$this->assertSame( 'Mitgliedsbeitrag', $this->orders->order_item_name( 'Tassa di socio', $item ) );
	}

	/**
	 * The line item carries the text the customer saw, resolved from the language active while
	 * the item is built.
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
	 * capture_item_text() must resolve the purchase language itself, not through order meta:
	 * on the Store API / block checkout path, woocommerce_checkout_create_order (and so
	 * capture_language()) never fires at all for a given order, so this method cannot depend on
	 * meta that may not exist yet -- or ever -- at the moment it runs.
	 *
	 * @return void
	 */
	public function test_item_text_is_frozen_without_order_meta_recorded_first() {
		lmat_test_set( 'curlang', 'de' );

		$order = new Lmat_Test_Order(); // capture_language() is deliberately never called.
		$item  = new Lmat_Test_Order_Item( 42 );

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
	 * The hooks are registered, including the line-item-scoped suspend filter that closes the
	 * canonical-name corruption window, the block-checkout language hook, and the shutdown safety
	 * net that guarantees the order context is never left suspended past its own request.
	 *
	 * @return void
	 */
	public function test_init_registers_the_order_hooks() {
		$this->orders->init();

		$this->assertTrue( has_filter( 'woocommerce_checkout_create_order_line_item_object' ) );
		$this->assertTrue( has_filter( 'woocommerce_checkout_create_order' ) );
		$this->assertTrue( has_filter( 'woocommerce_checkout_create_order_line_item' ) );
		$this->assertTrue( has_filter( 'woocommerce_store_api_checkout_order_processed' ) );
		$this->assertTrue( has_filter( 'woocommerce_order_item_name' ) );
		$this->assertTrue( has_filter( 'shutdown' ) );
	}
}
