<?php
/**
 * Removes products and product taxonomies from Linguator's ordinary translation model.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Takes the WooCommerce object types out of translate-by-duplication.
 *
 * Nothing is deleted. The `lmat_language` terms already attached to existing products are left in
 * place, so turning shared mode off restores today's behaviour with no recovery step.
 *
 * Registered from `lmat_init`, which runs inside Linguator's own `plugins_loaded` priority 1 boot.
 * Linguator caches both lists only once `after_setup_theme` has fired, so this is early enough.
 *
 * @since 2.3.0
 */
class Translated_Object_Types {

	/**
	 * Post types that stop being translated by duplication.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	const POST_TYPES = array( 'product', 'product_variation' );

	/**
	 * Taxonomies that stop being translated by duplication.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'lmat_get_post_types', array( $this, 'remove_post_types' ), 10, 2 );
		add_filter( 'lmat_get_taxonomies', array( $this, 'remove_taxonomies' ), 10, 2 );
	}

	/**
	 * Removes the WooCommerce product post types from the translated list.
	 *
	 * @since 2.3.0
	 *
	 * @param array $post_types  List of post type names.
	 * @param bool  $is_settings True when the Linguator settings screen is building its list.
	 * @return array
	 */
	public function remove_post_types( $post_types, $is_settings = false ) {
		return $this->remove( (array) $post_types, self::POST_TYPES );
	}

	/**
	 * Removes the WooCommerce product taxonomies from the translated list.
	 *
	 * @since 2.3.0
	 *
	 * @param array $taxonomies  List of taxonomy names.
	 * @param bool  $is_settings True when the Linguator settings screen is building its list.
	 * @return array
	 */
	public function remove_taxonomies( $taxonomies, $is_settings = false ) {
		return $this->remove( (array) $taxonomies, self::TAXONOMIES );
	}

	/**
	 * Removes a set of names from a list, whether the list is keyed by name or by position.
	 *
	 * @since 2.3.0
	 *
	 * @param array    $list  The list to filter.
	 * @param string[] $names The names to remove.
	 * @return array
	 */
	protected function remove( array $list, array $names ) {
		$list = array_diff( $list, $names );

		foreach ( $names as $name ) {
			unset( $list[ $name ] );
		}

		return $list;
	}
}
