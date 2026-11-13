<?php
/**
 * Per-language translated slugs and permalink bases for shared WooCommerce objects.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use WP_Error;

/**
 * Stores and looks up translated slugs and permalink bases.
 *
 * A slug lives in its own plain meta key (post meta for products, term meta for product taxonomies)
 * so that it can be found by value, which the serialized record of Translation_Repository cannot do.
 * Slugs replaced by a new one are kept in a history meta so that their URLs can still be redirected.
 * Uniqueness is enforced here, in code, per language and object type.
 *
 * @since 2.3.0
 */
class Slug_Store {

	const META_PREFIX     = '_lmat_wc_slug_';
	const META_OLD_PREFIX = '_lmat_wc_slug_old_';
	const BASES_OPTION    = 'lmat_wc_permalink_bases';
	const MAX_LENGTH      = 200;
	const RESERVED        = array( 'feed', 'page', 'embed', 'amp', 'attachment', 'comments', 'trackback' );
	const BASE_TYPES      = array( 'product', 'category', 'tag', 'attribute' );
	const CACHE_GROUP     = 'lmat_wc_slugs';

	/**
	 * Returns an object's current slug in a language.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Language slug.
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @return string Empty when the object has none.
	 */
	public static function get( $object_id, $language, $type = Translation_Repository::TYPE_POST ) {
		$fn = self::meta_function( 'get', $type );

		return (string) $fn( (int) $object_id, self::key( $language ), true );
	}

	/**
	 * Primes WordPress's meta cache for a catalogue or term loop in one batch.
	 *
	 * @since 2.3.0
	 *
	 * @param int[]  $object_ids Post or term IDs.
	 * @param string $language   Language slug (the cache contains all languages).
	 * @param string $type       Post or term.
	 * @return void
	 */
	public static function prime( array $object_ids, $language, $type = Translation_Repository::TYPE_POST ) {
		$object_ids = array_values( array_unique( array_map( 'intval', $object_ids ) ) );

		if ( empty( $object_ids ) ) {
			return;
		}

		if ( Translation_Repository::TYPE_TERM === $type ) {
			update_termmeta_cache( $object_ids );
			return;
		}

		update_postmeta_cache( $object_ids );
	}

	/**
	 * Stores an object's slug in a language and keeps the previous one as superseded.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Language slug.
	 * @param string $slug      Slug to store. It is sanitized and bounded.
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string $taxonomy  Taxonomy of a term, required for terms.
	 * @return true|WP_Error
	 */
	public static function set( $object_id, $language, $slug, $type = Translation_Repository::TYPE_POST, $taxonomy = '' ) {
		$object_id = (int) $object_id;
		$slug      = self::clean( $slug );
		$error     = self::check( $object_id, $language, $slug, $type, $taxonomy );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$previous = self::get( $object_id, $language, $type );

		if ( $previous !== $slug ) {
			$old = array_diff( self::history( $object_id, $language, $type ), array( $slug ) );

			if ( '' !== $previous ) {
				$old[] = $previous;
			}

			$update = self::meta_function( 'update', $type );
			$update( $object_id, self::key( $language, true ), array_values( array_unique( $old ) ) );
			$update( $object_id, self::key( $language ), $slug );
			self::flush( $language, $type );
		}

		return true;
	}

	/**
	 * Builds a unique slug from a name, appending -2, -3 and so on while it is taken.
	 *
	 * @since 2.3.0
	 *
	 * @param string $name      Source name.
	 * @param int    $object_id Post or term ID the slug is for.
	 * @param string $language  Language slug.
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string $taxonomy  Taxonomy of a term, required for terms.
	 * @return string Empty when nothing usable can be derived.
	 */
	public static function derive( $name, $object_id, $language, $type = Translation_Repository::TYPE_POST, $taxonomy = '' ) {
		$base = self::clean( $name );
		$slug = $base;

		for ( $i = 2; ; $i++ ) {
			$error = self::check( (int) $object_id, $language, $slug, $type, $taxonomy );

			if ( ! is_wp_error( $error ) ) {
				return $slug;
			}

			if ( 'lmat_wc_slug_conflict' !== $error->get_error_code() ) {
				return '';
			}

			$suffix = '-' . $i;
			$slug   = substr( $base, 0, self::MAX_LENGTH - strlen( $suffix ) ) . $suffix;
		}
	}

	/**
	 * Finds the object that currently has a slug in a language.
	 *
	 * @since 2.3.0
	 *
	 * @param string $slug     Translated slug.
	 * @param string $language Language slug.
	 * @param string $type     Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string $taxonomy Taxonomy to restrict a term lookup to.
	 * @param int    $parent   Parent term ID to restrict a term lookup to, null for any parent.
	 * @return int Object ID, 0 when none.
	 */
	public static function find( $slug, $language, $type = Translation_Repository::TYPE_POST, $taxonomy = '', $parent = null ) {
		$ids = self::matches( $slug, $language, $type, $taxonomy, false, $parent );

		return empty( $ids ) ? 0 : $ids[0];
	}

	/**
	 * Finds the object that used to have a slug in a language.
	 *
	 * @since 2.3.0
	 *
	 * @param string $slug     Superseded slug.
	 * @param string $language Language slug.
	 * @param string $type     Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string $taxonomy Taxonomy to restrict a term lookup to.
	 * @param int    $parent   Parent term ID to restrict a term lookup to, null for any parent.
	 * @return int Object ID, 0 when none.
	 */
	public static function find_superseded( $slug, $language, $type = Translation_Repository::TYPE_POST, $taxonomy = '', $parent = null ) {
		$ids = self::matches( $slug, $language, $type, $taxonomy, true, $parent );

		return empty( $ids ) ? 0 : $ids[0];
	}

	/**
	 * Finds every object that currently has a slug in a language, for callers that must tell
	 * same-slug terms under different parents apart.
	 *
	 * @since 2.3.0
	 *
	 * @param string $slug     Translated slug.
	 * @param string $language Language slug.
	 * @param string $type     Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string $taxonomy Taxonomy to restrict a term lookup to.
	 * @return int[] Object IDs.
	 */
	public static function find_all( $slug, $language, $type = Translation_Repository::TYPE_POST, $taxonomy = '' ) {
		return self::matches( $slug, $language, $type, $taxonomy, false );
	}

	/**
	 * Returns the translated permalink base of a language.
	 *
	 * @since 2.3.0
	 *
	 * @param string $language  Language slug.
	 * @param string $base_type One of BASE_TYPES.
	 * @return string Empty when the language has none.
	 */
	public static function get_base( $language, $base_type ) {
		$bases = (array) get_option( self::BASES_OPTION, array() );
		$key   = sanitize_key( $language );

		return isset( $bases[ $key ][ $base_type ] ) ? (string) $bases[ $key ][ $base_type ] : '';
	}

	/**
	 * Stores the translated permalink base of a language and remembers the one it replaces.
	 *
	 * The caller flushes the rewrite rules.
	 *
	 * @since 2.3.0
	 *
	 * @param string $language  Language slug.
	 * @param string $base_type One of BASE_TYPES.
	 * @param string $base      Base, such as "produkt" or "shop/produkt".
	 * @return true|WP_Error
	 */
	public static function set_base( $language, $base_type, $base ) {
		$base = implode( '/', array_filter( array_map( 'sanitize_title', explode( '/', (string) $base ) ) ) );

		if ( ! in_array( $base_type, self::BASE_TYPES, true ) || '' === $base ) {
			return new WP_Error( 'lmat_wc_base_invalid', __( 'This permalink base is not valid.', 'translate-words' ) );
		}

		$previous = self::get_base( $language, $base_type );

		if ( $previous === $base ) {
			return true;
		}

		$bases = (array) get_option( self::BASES_OPTION, array() );
		$key   = sanitize_key( $language );
		$old   = isset( $bases[ $key ]['old'][ $base_type ] ) ? (array) $bases[ $key ]['old'][ $base_type ] : array();
		$old   = array_diff( $old, array( $base ) );

		if ( '' !== $previous ) {
			$old[] = $previous;
		}

		$bases[ $key ][ $base_type ]        = $base;
		$bases[ $key ]['old'][ $base_type ] = array_values( array_unique( $old ) );
		update_option( self::BASES_OPTION, $bases );

		return true;
	}

	/**
	 * Tells whether a base was replaced by a newer one in a language.
	 *
	 * @since 2.3.0
	 *
	 * @param string $base      Base to test.
	 * @param string $language  Language slug.
	 * @param string $base_type One of BASE_TYPES.
	 * @return bool
	 */
	public static function is_superseded_base( $base, $language, $base_type ) {
		$bases = (array) get_option( self::BASES_OPTION, array() );
		$key   = sanitize_key( $language );

		return isset( $bases[ $key ]['old'][ $base_type ] ) && in_array( $base, (array) $bases[ $key ]['old'][ $base_type ], true );
	}

	/**
	 * Removes every slug of an object, current and superseded, so that they can be reused.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @return void
	 */
	public static function forget( $object_id, $type ) {
		$all    = self::meta_function( 'get', $type );
		$delete = self::meta_function( 'delete', $type );

		foreach ( array_keys( (array) $all( (int) $object_id ) ) as $key ) {
			if ( 0 !== strpos( $key, self::META_PREFIX ) ) {
				continue;
			}

			$delete( (int) $object_id, $key );
			self::flush( substr( $key, strlen( 0 === strpos( $key, self::META_OLD_PREFIX ) ? self::META_OLD_PREFIX : self::META_PREFIX ) ), $type );
		}
	}

	/**
	 * Returns the meta key of a language.
	 *
	 * @since 2.3.0
	 *
	 * @param string $language Language slug.
	 * @param bool   $old      True for the history of superseded slugs.
	 * @return string
	 */
	private static function key( $language, $old = false ) {
		return ( $old ? self::META_OLD_PREFIX : self::META_PREFIX ) . sanitize_key( $language );
	}

	/**
	 * Returns the name of the WordPress meta function for an object type.
	 *
	 * @since 2.3.0
	 *
	 * @param string $operation get, update or delete.
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @return string
	 */
	private static function meta_function( $operation, $type ) {
		return $operation . '_' . ( Translation_Repository::TYPE_TERM === $type ? 'term' : 'post' ) . '_meta';
	}

	/**
	 * Sanitizes a slug and cuts it to the maximum length.
	 *
	 * @since 2.3.0
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	private static function clean( $slug ) {
		return trim( substr( sanitize_title( $slug ), 0, self::MAX_LENGTH ), '-' );
	}

	/**
	 * Returns the superseded slugs of an object in a language.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Language slug.
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @return string[]
	 */
	private static function history( $object_id, $language, $type ) {
		$fn = self::meta_function( 'get', $type );

		return array_values( (array) $fn( $object_id, self::key( $language, true ), true ) );
	}

	/**
	 * Returns the IDs that hold a slug, from the per-request slug map of the language.
	 *
	 * @since 2.3.0
	 *
	 * @param string $slug     Slug to look up.
	 * @param string $language Language slug.
	 * @param string $type     Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string $taxonomy Taxonomy to restrict term IDs to, empty for any.
	 * @param bool   $old      True to search superseded slugs instead of current ones.
	 * @param int    $parent   Parent term ID to restrict term IDs to, null for any.
	 * @return int[]
	 */
	private static function matches( $slug, $language, $type, $taxonomy, $old, $parent = null ) {
		$map = self::map( $language, $type, $old );
		$ids = isset( $map[ $slug ] ) ? $map[ $slug ] : array();

		if ( Translation_Repository::TYPE_TERM === $type && ( '' !== $taxonomy || null !== $parent ) ) {
			$ids = array_filter(
				$ids,
				function ( $id ) use ( $taxonomy, $parent ) {
					$term_taxonomy = get_term_field( 'taxonomy', $id );
					$term_parent   = get_term_field( 'parent', $id, $taxonomy );

					return ! is_wp_error( $term_taxonomy ) && ! is_wp_error( $term_parent )
						&& ( '' === $taxonomy || $term_taxonomy === $taxonomy )
						&& ( null === $parent || (int) $term_parent === (int) $parent );
				}
			);
		}

		return array_values( $ids );
	}

	/**
	 * Returns the slug to object IDs map of a language, read once per request.
	 *
	 * @since 2.3.0
	 *
	 * @param string $language Language slug.
	 * @param string $type     Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param bool   $old      True for superseded slugs.
	 * @return array<string,int[]>
	 */
	private static function map( $language, $type, $old ) {
		$cache_key = $type . ':' . ( $old ? 'old:' : '' ) . sanitize_key( $language );
		// Per-request only: an external object cache would go stale on writes made outside this class.
		if ( function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
			wp_cache_add_non_persistent_groups( self::CACHE_GROUP );
		}

		$map = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $map ) ) {
			return $map;
		}

		global $wpdb;

		$is_term = Translation_Repository::TYPE_TERM === $type;
		$table   = $is_term ? $wpdb->termmeta : $wpdb->postmeta;
		$column  = $is_term ? 'term_id' : 'post_id';
		$rows    = $wpdb->get_results(
			$wpdb->prepare( "SELECT {$column} AS id, meta_value FROM {$table} WHERE meta_key = %s", self::key( $language, $old ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column are fixed above.
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array(); // A failed query must not be cached as an empty map.
		}

		$map = array();

		foreach ( $rows as $row ) {
			$values = $old ? (array) maybe_unserialize( $row['meta_value'] ) : array( $row['meta_value'] );

			foreach ( $values as $value ) {
				$map[ (string) $value ][] = (int) $row['id'];
			}
		}

		wp_cache_set( $cache_key, $map, self::CACHE_GROUP );

		return $map;
	}

	/**
	 * Drops the cached slug maps of a language.
	 *
	 * @since 2.3.0
	 *
	 * @param string $language Language slug.
	 * @param string $type     Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @return void
	 */
	private static function flush( $language, $type ) {
		wp_cache_delete( $type . ':' . sanitize_key( $language ), self::CACHE_GROUP );
		wp_cache_delete( $type . ':old:' . sanitize_key( $language ), self::CACHE_GROUP );
	}

	/**
	 * Validates a clean slug for an object: usable, not reserved, and not taken.
	 *
	 * Taken means another object's translated slug in the language, or the canonical slug of another
	 * object that has no translated slug there (it would stay reachable under the language prefix).
	 * Terms are compared within one taxonomy and one parent.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Language slug.
	 * @param string $slug      Clean slug.
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string $taxonomy  Taxonomy of a term.
	 * @return true|WP_Error
	 */
	private static function check( $object_id, $language, $slug, $type, $taxonomy ) {
		if ( '' === $slug || ctype_digit( $slug ) || in_array( $slug, self::RESERVED, true ) ) {
			return new WP_Error( 'lmat_wc_slug_invalid', __( 'This slug is empty, numeric or reserved.', 'translate-words' ) );
		}

		$is_term = Translation_Repository::TYPE_TERM === $type;
		$parent  = $is_term ? get_term_field( 'parent', $object_id, $taxonomy ) : 0;

		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		$parent = (int) $parent;
		$taken  = self::matches( $slug, $language, $type, $taxonomy, false, $is_term ? $parent : null );

		// Lang is emptied so that Linguator's language filter does not hide other languages' objects.
		if ( $is_term ) {
			$canonical = '' === $taxonomy ? array() : get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'slug'       => $slug,
					'parent'     => $parent,
					'hide_empty' => false,
					'fields'     => 'ids',
					'lang'       => '',
				)
			);
		} else {
			$canonical = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => 'product',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'lang'           => '',
				)
			);
		}

		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		foreach ( (array) $canonical as $id ) {
			if ( '' === self::get( (int) $id, $language, $type ) ) {
				$taken[] = (int) $id;
			}
		}

		if ( array_diff( array_map( 'intval', $taken ), array( $object_id ) ) ) {
			return new WP_Error( 'lmat_wc_slug_conflict', __( 'This slug is already used by another item in this language.', 'translate-words' ) );
		}

		return true;
	}
}
