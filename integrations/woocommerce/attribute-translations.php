<?php
/**
 * Translated attribute labels and option labels.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Localizes what an attribute is called, never what it is.
 *
 * Product-local attributes carry a canonical name and canonical option values. Those are what
 * WooCommerce matches on, in the variation form, in `woocommerce_available_variation`, in the AJAX
 * variation response and in add-to-cart. This class stores a label map beside them and localizes
 * only what is rendered. The field key of an option is derived from the canonical value, so a
 * translated label can never shift the key it is stored under.
 *
 * @since 2.3.0
 */
class Attribute_Translations {

	const FIELD_LABEL_PREFIX  = 'attr_label::';
	const FIELD_OPTION_PREFIX = 'attr_option::';

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
	 * Returns the repository field key holding an attribute's label.
	 *
	 * @since 2.3.0
	 *
	 * @param string $attribute Canonical attribute name.
	 * @return string
	 */
	public static function label_field( $attribute ) {
		return self::FIELD_LABEL_PREFIX . self::normalize_attribute( $attribute );
	}

	/**
	 * Reduces the spellings of one attribute to a single key.
	 *
	 * WooCommerce hands the same attribute to the display hooks under different names depending on
	 * the call site: the variation form passes the raw attribute key, while the cart passes the
	 * value of `wc_attribute_taxonomy_name()`, which prefixes `pa_`. Order item meta adds
	 * `attribute_`. Keying on the raw string would store one label per call site.
	 *
	 * On a site that has both a global `pa_size` taxonomy and a product-local attribute named
	 * "Size", the two collapse to the same key. The target site has zero global attribute
	 * taxonomies, so this cannot happen there; it is recorded as a known limitation rather than
	 * solved with a longer key that would still be ambiguous.
	 *
	 * @since 2.3.0
	 *
	 * @param string $attribute Attribute name in any of its spellings.
	 * @return string
	 */
	public static function normalize_attribute( $attribute ) {
		$attribute = (string) $attribute;

		if ( 0 === strpos( $attribute, 'attribute_' ) ) {
			$attribute = substr( $attribute, strlen( 'attribute_' ) );
		}

		if ( 0 === strpos( $attribute, 'pa_' ) ) {
			$attribute = substr( $attribute, strlen( 'pa_' ) );
		}

		return sanitize_title( urldecode( $attribute ) );
	}

	/**
	 * Returns the repository field key holding one option's label.
	 *
	 * @since 2.3.0
	 *
	 * @param string $attribute    Canonical attribute name.
	 * @param string $option_value Canonical option value.
	 * @return string
	 */
	public static function option_field( $attribute, $option_value ) {
		return self::FIELD_OPTION_PREFIX . self::normalize_attribute( $attribute ) . '::' . Translation_Repository::hash( (string) $option_value );
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_attribute_label', array( $this, 'attribute_label' ), 10, 3 );
		add_filter( 'woocommerce_variation_option_name', array( $this, 'option_name' ), 10, 4 );
	}

	/**
	 * Localizes the label of an attribute.
	 *
	 * @since 2.3.0
	 *
	 * @param string $label   Canonical label.
	 * @param string $name    Canonical attribute name.
	 * @param object $product The product the attribute belongs to, when WooCommerce knows it.
	 * @return string
	 */
	public function attribute_label( $label, $name = '', $product = null ) {
		$product_id = $this->owner_id( $product );

		if ( $product_id <= 0 ) {
			return $label;
		}

		$attribute = '' === $name ? $label : $name;

		return $this->localize( $label, $product_id, self::label_field( $attribute ) );
	}

	/**
	 * Localizes the label of one option value.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value    Canonical option value.
	 * @param mixed  $term     The term, for taxonomy-based attributes. Null for product-local ones.
	 * @param string $taxonomy Attribute name or taxonomy.
	 * @param object $product  The product or variation being rendered.
	 * @return string
	 */
	public function option_name( $value, $term = null, $taxonomy = '', $product = null ) {
		$product_id = $this->owner_id( $product );

		if ( $product_id <= 0 || '' === (string) $taxonomy ) {
			return $value;
		}

		return $this->localize( $value, $product_id, self::option_field( $taxonomy, $value ) );
	}

	/**
	 * Returns the product ID that owns the label map.
	 *
	 * A variation borrows its parent's map: the attribute belongs to the parent product.
	 *
	 * @since 2.3.0
	 *
	 * @param object $product Product or variation.
	 * @return int 0 when it cannot be determined.
	 */
	protected function owner_id( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return 0;
		}

		if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			return (int) $product->get_parent_id();
		}

		return (int) $product->get_id();
	}

	/**
	 * Gate, default-language short circuit, then storage.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value      Canonical value.
	 * @param int    $product_id Product that owns the map.
	 * @param string $field      Repository field key.
	 * @return string
	 */
	protected function localize( $value, $product_id, $field ) {
		if ( ! $this->gate->should_localize() || $this->frontend->is_default_language() ) {
			return $value;
		}

		$language = $this->frontend->current_language();

		if ( '' === $language ) {
			return $value;
		}

		return $this->repository->get_value( (int) $product_id, $language, $field, (string) $value );
	}
}
