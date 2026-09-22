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
 * Localization is suspended for the whole of order creation. The display text is then resolved
 * explicitly from the language recorded on the order, never from ambient request context: an admin,
 * a cron job or a webhook must not be able to decide what language an order was placed in.
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
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_language' ), 0, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'capture_item_text' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'resume_localization' ), 0 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'resume_localization' ), 0 );
		add_filter( 'woocommerce_order_item_name', array( $this, 'order_item_name' ), 10, 2 );
	}

	/**
	 * Records the purchase language and suspends localization for the rest of order creation.
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

		$this->gate->suspend( Context_Gate::CONTEXT_ORDER );
	}

	/**
	 * Lifts the order suspension once the order has been created.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function resume_localization() {
		$this->gate->resume( Context_Gate::CONTEXT_ORDER );
	}

	/**
	 * Freezes the text the customer saw onto the line item.
	 *
	 * @since 2.3.0
	 *
	 * @param object $item           The order line item.
	 * @param string $cart_item_key  Cart item key. Unused.
	 * @param array  $values         Cart item values. Unused.
	 * @param object $order          The order being created.
	 * @return void
	 */
	public function capture_item_text( $item, $cart_item_key = '', $values = array(), $order = null ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		$language = $this->purchase_language( $order );

		if ( '' === $language || $language === (string) linguator_default_language( 'slug' ) ) {
			return; // The canonical order item name already says it.
		}

		$object_id = method_exists( $item, 'get_variation_id' ) && (int) $item->get_variation_id() > 0
			? (int) $item->get_variation_id()
			: (int) $item->get_product_id();

		if ( $object_id <= 0 ) {
			return;
		}

		$record = $this->repository->get_record( $object_id, $language );

		if ( ! isset( $record['fields']['name']['value'] ) ) {
			return; // Nothing was translated: the canonical name is what the customer read.
		}

		$item->add_meta_data( self::ITEM_META_NAME, (string) $record['fields']['name']['value'], true );
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

	/**
	 * Returns the language recorded on an order.
	 *
	 * @since 2.3.0
	 *
	 * @param object $order The order.
	 * @return string Empty when it cannot be determined.
	 */
	protected function purchase_language( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}

		return (string) $order->get_meta( self::ORDER_META_LANGUAGE, true );
	}
}
