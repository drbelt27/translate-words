<?php
/**
 * The variation payload served to the variation form and to AJAX.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Localizes the text in a variation payload, and nothing else.
 *
 * `woocommerce_available_variation` builds what the variation form reads and what the AJAX
 * `get_variation` endpoint returns. Only `variation_description` is text; `attributes`,
 * `variation_id`, `sku` and every price and stock figure are canonical and are what WooCommerce
 * matches an add-to-cart request against. They are returned untouched, deliberately.
 *
 * @since 2.3.0
 */
class Variations {

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
		add_filter( 'woocommerce_available_variation', array( $this, 'available_variation' ), 10, 3 );
	}

	/**
	 * Localizes the text of one variation payload.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $data      The payload.
	 * @param object $product   The parent variable product.
	 * @param object $variation The variation.
	 * @return array
	 */
	public function available_variation( $data, $product = null, $variation = null ) {
		if ( ! is_array( $data ) || ! array_key_exists( 'variation_description', $data ) ) {
			return $data;
		}

		if ( ! $this->gate->should_localize() || $this->frontend->is_default_language() ) {
			return $data;
		}

		$language = $this->frontend->current_language();

		if ( '' === $language ) {
			return $data;
		}

		$variation_id = 0;

		if ( is_object( $variation ) && method_exists( $variation, 'get_id' ) ) {
			$variation_id = (int) $variation->get_id();
		} elseif ( isset( $data['variation_id'] ) ) {
			$variation_id = (int) $data['variation_id'];
		}

		if ( $variation_id <= 0 ) {
			return $data;
		}

		$data['variation_description'] = $this->repository->get_value(
			$variation_id,
			$language,
			'description',
			(string) $data['variation_description']
		);

		return $data;
	}
}
