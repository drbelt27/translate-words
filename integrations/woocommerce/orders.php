<?php
/**
 * Purchase language and the product text frozen at order creation.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Freezes what the customer read into the order, once, at creation.
 *
 * Order data is written once and never rewritten by a later translation edit, so refunds, emails,
 * exports and CRM reads keep referring to the same canonical IDs and to the same words the
 * customer agreed to buy.
 *
 * Localization is suspended around the construction of every order line item, on both the legacy
 * checkout (`WC_Checkout::create_order()`) and the Store API / block checkout (which never calls
 * `create_order()` at all, but reuses `WC_Checkout::create_order_line_items()` through
 * `OrderController::update_line_items_from_cart()`). That method is the one place, shared by both
 * paths, that reads `$product->get_name()` to set the canonical item name
 * (`includes/class-wc-checkout.php`, WooCommerce core) — so suspension is bracketed tightly around
 * it instead of around the whole request: `woocommerce_checkout_create_order_line_item_object`
 * fires immediately before that read, `woocommerce_checkout_create_order_line_item` immediately
 * after. The display text is resolved from the language active at that exact moment
 * (`Frontend::current_language()`), never from order meta, which does not exist yet this early and,
 * on the block checkout path, may never be written by `capture_language()` at all.
 *
 * @since 2.3.0
 */
class Orders {

	const ORDER_META_LANGUAGE = '_lmat_purchase_language';
	const ITEM_META_NAME      = '_lmat_display_name';

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The localization context gate.
	 *
	 * @since 2.3.0
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * Language helper.
	 *
	 * @since 2.3.0
	 *
	 * @var Frontend
	 */
	protected $frontend;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 * @param Context_Gate           $gate       The localization context gate.
	 * @param Frontend               $frontend   Language helper.
	 */
	public function __construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend ) {
		$this->repository = $repository;
		$this->gate       = $gate;
		$this->frontend   = $frontend;
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		// Priority 0: must run before any other callback on this filter could read the item's
		// product, and strictly before WC_Checkout::create_order_line_items() sets the item's name
		// from $product->get_name() a few lines later in the same loop iteration. Fires on both the
		// legacy checkout and the Store API / block checkout (draft order sync and final order),
		// since both call that same WooCommerce core method.
		add_filter( 'woocommerce_checkout_create_order_line_item_object', array( $this, 'suspend_for_line_item' ), 0, 1 );

		// Fires immediately after the canonical name has been set for this same item, still inside
		// the same loop iteration. Freezes the display text and resumes localization, so the
		// suspend/resume bracket never crosses a request boundary.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'capture_item_text' ), 10, 4 );

		// Records the permanent language-of-purchase on the order itself. Independent of the
		// suspend/resume bracket above: capture_item_text() no longer depends on this having run.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_language' ), 10, 2 );
		// The Store API / block checkout path never fires woocommerce_checkout_create_order at all,
		// so the order-language record needs its own hook on that path too.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_language' ), 10, 1 );

		add_filter( 'woocommerce_order_item_name', array( $this, 'order_item_name' ), 10, 2 );

		// Safety net: if something throws between suspend_for_line_item() and capture_item_text()
		// for a given item (WooCommerce's own set_props()/set_backorder_meta() do not, but a
		// third-party callback hooked in between could), the order context would otherwise stay
		// suspended for whatever remains of the request. `shutdown` always fires once per request,
		// including after an uncaught exception, so this guarantees the gate is never left suspended
		// past the request that suspended it.
		add_action( 'shutdown', array( $this, 'resume_localization' ), 0 );
	}

	/**
	 * Suspends localization for the line item about to be built. Hook callback.
	 *
	 * Runs on `woocommerce_checkout_create_order_line_item_object`, which WooCommerce fires
	 * immediately before it reads `$product->get_name()` to set the item's canonical name. Must
	 * return the item unchanged: this is a filter only because that is the earliest hook available,
	 * not because it changes anything.
	 *
	 * @since 2.3.0
	 *
	 * @param object $item The order line item about to be populated.
	 * @return object The item, unchanged.
	 */
	public function suspend_for_line_item( $item ) {
		$this->gate->suspend( Context_Gate::CONTEXT_ORDER );

		return $item;
	}

	/**
	 * Records the purchase language on the order. Hook callback.
	 *
	 * Independent of gate suspension: this is bookkeeping for the permanent record, not part of the
	 * canonical-name protection, which is handled entirely by suspend_for_line_item() and
	 * capture_item_text().
	 *
	 * @since 2.3.0
	 *
	 * @param object $order The order being created.
	 * @param array  $data  Checkout data. Unused.
	 * @return void
	 */
	public function capture_language( $order, $data = array() ) {
		if ( is_object( $order ) && method_exists( $order, 'add_meta_data' ) ) {
			$order->add_meta_data( self::ORDER_META_LANGUAGE, $this->frontend->current_language(), true );
		}
	}

	/**
	 * Lifts the order suspension. Hook callback.
	 *
	 * Called once per item, right after that item's text has been frozen, and again unconditionally
	 * on `shutdown` as a safety net. Both calls are idempotent: resuming a context that is not
	 * suspended is a no-op.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function resume_localization() {
		$this->gate->resume( Context_Gate::CONTEXT_ORDER );
	}

	/**
	 * Freezes the text the customer saw onto the line item, and resumes localization. Hook callback.
	 *
	 * Runs on `woocommerce_checkout_create_order_line_item`, immediately after the canonical name has
	 * been set for this same item. The purchase language is read live, from whatever language is
	 * active right now, rather than from order meta: at this point in either checkout path, order
	 * meta written by capture_language() may not exist yet (legacy: it runs later; Store API: it may
	 * never run for this order at all). This is deliberately the same source capture_language() itself
	 * reads from, so the two stay consistent without one depending on the other's timing.
	 *
	 * @since 2.3.0
	 *
	 * @param object $item           The order line item.
	 * @param string $cart_item_key  Cart item key. Unused.
	 * @param array  $values         Cart item values. Unused.
	 * @param object $order          The order being created. Unused.
	 * @return void
	 */
	public function capture_item_text( $item, $cart_item_key = '', $values = array(), $order = null ) {
		try {
			if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
				return;
			}

			$language = $this->frontend->current_language();

			if ( '' === $language || $language === (string) linguator_default_language( 'slug' ) ) {
				return; // The canonical order item name already says it.
			}

			$object_id = method_exists( $item, 'get_variation_id' ) && (int) $item->get_variation_id() > 0
				? (int) $item->get_variation_id()
				: ( method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0 );

			if ( $object_id <= 0 ) {
				return;
			}

			$record = $this->repository->get_record( $object_id, $language );

			if ( ! isset( $record['fields']['name']['value'] ) ) {
				return; // Nothing was translated: the canonical name is what the customer read.
			}

			$item->add_meta_data( self::ITEM_META_NAME, (string) $record['fields']['name']['value'], true );
		} finally {
			// Always resumes, even if the lookup above throws: this item's suspension must not leak
			// into whatever WooCommerce does with the next cart item, or beyond.
			$this->gate->resume( Context_Gate::CONTEXT_ORDER );
		}
	}

	/**
	 * Serves the frozen text whenever the order has one.
	 *
	 * Deliberately independent of the viewer's language: a historical order must read the same to
	 * everyone, forever.
	 *
	 * @since 2.3.0
	 *
	 * @param string $name Canonical order item name.
	 * @param object $item The order line item.
	 * @return string
	 */
	public function order_item_name( $name, $item = null ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return $name;
		}

		$frozen = (string) $item->get_meta( self::ITEM_META_NAME, true );

		return '' === $frozen ? $name : $frozen;
	}
}
