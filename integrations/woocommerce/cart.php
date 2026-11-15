<?php
/**
 * Meta cache priming for cart and catalogue loops.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Warms WordPress's meta cache for the objects a page is about to render.
 *
 * The cart line and the checkout review table are assembled from the parent product name and the
 * formatted attribute pairs, which the attribute hooks already localize. What those hooks must not
 * do is read storage one product at a time, so the ID set is primed before the loop starts.
 *
 * @since 2.3.0
 */
class Cart {

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 */
	public function __construct( Translation_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'the_posts', array( $this, 'prime_loop' ), 10, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'prime_cart' ), 10, 1 );
	}

	/**
	 * Primes every product and variation currently in the cart.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $cart The cart, when WooCommerce passes one. Unused: the session cart is read.
	 * @return void
	 */
	public function prime_cart( $cart = null ) {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();

		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! method_exists( $wc->cart, 'get_cart' ) ) {
			return;
		}

		$ids = array();

		foreach ( (array) $wc->cart->get_cart() as $item ) {
			if ( ! empty( $item['product_id'] ) ) {
				$ids[] = (int) $item['product_id'];
			}

			if ( ! empty( $item['variation_id'] ) ) {
				$ids[] = (int) $item['variation_id'];
			}
		}

		$this->repository->prime( $ids );
	}

	/**
	 * Primes a query's whole result set and returns it unchanged.
	 *
	 * @since 2.3.0
	 *
	 * @param array $posts The posts the query found.
	 * @param mixed $query The query. Unused.
	 * @return array The posts, untouched.
	 */
	public function prime_loop( $posts, $query = null ) {
		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}

		$ids = array();

		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$ids[] = (int) $post->ID;
			}
		}

		$this->repository->prime( $ids );

		return $posts;
	}
}
