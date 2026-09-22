<?php
/**
 * Localized product and variation text.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Serves translated text through WooCommerce's own view getters.
 *
 * Templates, blocks and the Store API all end up calling these getters, so filtering them covers
 * far more surface than filtering templates would. Every call is gated: see Context_Gate.
 *
 * @since 2.3.0
 */
class Frontend {

	/**
	 * Post types whose post-level text belongs to a shared product.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	const POST_TYPES = array( 'product', 'product_variation' );

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
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 * @param Context_Gate           $gate       The localization context gate.
	 */
	public function __construct( Translation_Repository $repository, Context_Gate $gate ) {
		$this->repository = $repository;
		$this->gate       = $gate;
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_product_get_name', array( $this, 'product_name' ), 10, 2 );
		add_filter( 'woocommerce_product_get_description', array( $this, 'product_description' ), 10, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'product_short_description' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_description', array( $this, 'variation_description' ), 10, 2 );
		add_filter( 'the_title', array( $this, 'post_title' ), 10, 2 );
		add_filter( 'get_the_excerpt', array( $this, 'post_excerpt' ), 10, 2 );
	}

	/**
	 * Returns the current language slug.
	 *
	 * @since 2.3.0
	 *
	 * @return string Empty when Linguator cannot tell.
	 */
	public function current_language() {
		return function_exists( 'linguator_current_language' ) ? (string) linguator_current_language( 'slug' ) : '';
	}

	/**
	 * Tells whether the current language is the site's default.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	public function is_default_language() {
		if ( ! function_exists( 'linguator_default_language' ) ) {
			return true;
		}

		return (string) linguator_default_language( 'slug' ) === $this->current_language();
	}

	/**
	 * Localizes the product name.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value   Canonical name.
	 * @param object $product The product object.
	 * @return string
	 */
	public function product_name( $value, $product ) {
		return $this->localize_object( $value, $product, 'name' );
	}

	/**
	 * Localizes the product description.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value   Canonical description.
	 * @param object $product The product object.
	 * @return string
	 */
	public function product_description( $value, $product ) {
		return $this->localize_object( $value, $product, 'description' );
	}

	/**
	 * Localizes the product short description.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value   Canonical short description.
	 * @param object $product The product object.
	 * @return string
	 */
	public function product_short_description( $value, $product ) {
		return $this->localize_object( $value, $product, 'short_description' );
	}

	/**
	 * Localizes a variation's own description, against the variation ID.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value     Canonical description.
	 * @param object $variation The variation object.
	 * @return string
	 */
	public function variation_description( $value, $variation ) {
		return $this->localize_object( $value, $variation, 'description' );
	}

	/**
	 * Localizes a title read straight from the post rather than from the product object.
	 *
	 * @since 2.3.0
	 *
	 * @param string $title   Canonical title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function post_title( $title, $post_id = 0 ) {
		return $this->localize_post( $title, $post_id, 'name' );
	}

	/**
	 * Localizes an excerpt read straight from the post.
	 *
	 * @since 2.3.0
	 *
	 * @param string $excerpt Canonical excerpt.
	 * @param object $post    Post object.
	 * @return string
	 */
	public function post_excerpt( $excerpt, $post = null ) {
		$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;

		return $this->localize_post( $excerpt, $post_id, 'short_description' );
	}

	/**
	 * Localizes one field of a product or variation object.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value  Canonical value.
	 * @param object $object The product or variation.
	 * @param string $field  Field name in the repository.
	 * @return string
	 */
	protected function localize_object( $value, $object, $field ) {
		if ( ! is_object( $object ) || ! method_exists( $object, 'get_id' ) ) {
			return $value;
		}

		return $this->localize( $value, (int) $object->get_id(), $field );
	}

	/**
	 * Localizes one field of a post, when that post is a product or a variation.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value   Canonical value.
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name in the repository.
	 * @return string
	 */
	protected function localize_post( $value, $post_id, $field ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 || ! in_array( get_post_type( $post_id ), self::POST_TYPES, true ) ) {
			return $value;
		}

		return $this->localize( $value, $post_id, $field );
	}

	/**
	 * The common path: gate, default-language short circuit, then storage.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value     Canonical value.
	 * @param int    $object_id Post ID of the product or variation.
	 * @param string $field     Field name in the repository.
	 * @return string
	 */
	protected function localize( $value, $object_id, $field ) {
		if ( ! $this->gate->should_localize() ) {
			return $value;
		}

		if ( $this->is_default_language() ) {
			return $value; // Short path: the repository is not consulted at all.
		}

		$language = $this->current_language();

		if ( '' === $language ) {
			return $value;
		}

		return $this->repository->get_value( (int) $object_id, $language, $field, (string) $value );
	}
}
