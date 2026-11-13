<?php
/**
 * Translates outbound WooCommerce product and term links in shared mode.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Applies the resolver's slug and base rules to WordPress-generated links.
 *
 * @since 2.3.0
 */
class Slug_Links {

	/** @var Slug_Resolver URL rules. */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 * @param Slug_Resolver $resolver URL rules.
	 */
	public function __construct( Slug_Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Registers outbound link filters and catalogue priming.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function init() {
		add_filter( 'post_type_link', array( $this, 'post_link' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'term_link' ), 10, 3 );
		add_filter( 'the_posts', array( $this, 'prime_loop' ), 10, 2 );
	}

	/**
	 * Emits the current language's product URL, never a variation URL.
	 *
	 * @since 2.3.0
	 * @param string $url  Canonical permalink.
	 * @param object $post Post.
	 * @return string
	 */
	public function post_link( $url, $post ) {
		if ( Slug_Resolver::reading_canonical() || ! Shared_Mode::is_enabled() || ! is_object( $post ) || ! isset( $post->ID ) || 'product' !== get_post_type( $post ) ) {
			return $url;
		}

		$language = function_exists( 'linguator_current_language' ) ? linguator_current_language( 'slug' ) : '';
		return '' === $language ? $url : $this->resolver->from_url( $url, 'post', $post->ID, $language );
	}

	/**
	 * Emits the current language's product taxonomy URL.
	 *
	 * @since 2.3.0
	 * @param string $url      Canonical term URL.
	 * @param mixed  $term     Term ID or object.
	 * @param string $taxonomy Term taxonomy.
	 * @return string
	 */
	public function term_link( $url, $term, $taxonomy ) {
		if ( Slug_Resolver::reading_canonical() || ! Shared_Mode::is_enabled() || ! in_array( $taxonomy, array( 'product_cat', 'product_tag', 'product_brand' ), true ) && 0 !== strpos( $taxonomy, 'pa_' ) ) {
			return $url;
		}

		$language = function_exists( 'linguator_current_language' ) ? linguator_current_language( 'slug' ) : '';
		$id       = is_object( $term ) ? $term->term_id : (int) $term;
		return '' === $language ? $url : $this->resolver->from_url( $url, 'term', $id, $language, $taxonomy );
	}

	/**
	 * Warms slug meta for the products of a catalogue page in one operation.
	 *
	 * @since 2.3.0
	 * @param array $posts Query posts.
	 * @param mixed $query Query, unused.
	 * @return array
	 */
	public function prime_loop( $posts, $query = null ) {
		if ( ! Shared_Mode::is_enabled() || ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}

		$ids = array();
		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) && 'product' === get_post_type( $post ) ) {
				$ids[] = (int) $post->ID;
			}
		}

		Slug_Store::prime( $ids, (string) linguator_current_language( 'slug' ) );
		return $posts;
	}
}
