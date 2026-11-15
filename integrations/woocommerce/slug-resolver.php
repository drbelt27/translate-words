<?php
/**
 * Decides which URL an object has in each language and what an inbound slug or base means.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * The one place that owns the translated-slug rules, so that no caller reimplements them.
 *
 * 1. An object with a translated slug in a language: its canonical slug under that language prefix is
 *    retired (301 by default, see CANONICAL_UNDER_PREFIX).
 * 2. An object without one: the canonical slug keeps working.
 * 3. The same two rules apply to each permalink base.
 * 4. The default language always uses the canonical slug and base.
 *
 * @since 2.3.0
 */
final class Slug_Resolver {

	/**
	 * What a canonical slug or base under a language prefix does once it has a translation:
	 * 'redirect' (301 to the translated URL) or 'not_found'.
	 */
	const CANONICAL_UNDER_PREFIX = 'redirect';

	/**
	 * Slug storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Slug_Store
	 */
	private $store;

	/**
	 * Outcome of rules 1 and 3.
	 *
	 * @since 2.3.0
	 *
	 * @var string
	 */
	private $canonical_under_prefix;

	/** @var bool Whether a canonical URL is being read before applying translated links. */
	private static $reading_canonical = false;

	/**
	 * Allows outbound link filters to leave the canonical URL alone while permalink() reads it.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	public static function reading_canonical() {
		return self::$reading_canonical;
	}

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Slug_Store $store                  Slug storage.
	 * @param string     $canonical_under_prefix 'redirect' or 'not_found'.
	 */
	public function __construct( Slug_Store $store, $canonical_under_prefix = self::CANONICAL_UNDER_PREFIX ) {
		$this->store                  = $store;
		$this->canonical_under_prefix = 'not_found' === $canonical_under_prefix ? 'not_found' : 'redirect';
	}

	/**
	 * Returns the full URL of an object in a language.
	 *
	 * Translated slugs and bases are used where they exist, canonical ones otherwise, and the default
	 * language always gets the canonical URL. It reads get_permalink() / get_term_link(), so a caller
	 * that filters those must not call it from inside the filter without a re-entry guard.
	 *
	 * @since 2.3.0
	 *
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Target language slug.
	 * @param string $taxonomy  Taxonomy of a term.
	 * @return string Empty when the object has no URL.
	 */
	public function permalink( $type, $object_id, $language, $taxonomy = '' ) {
		$object_id = (int) $object_id;
		$previous                = self::$reading_canonical;
		self::$reading_canonical = true;
		try {
			$url = Translation_Repository::TYPE_TERM === $type ? get_term_link( $object_id, $taxonomy ) : get_permalink( $object_id );
		} finally {
			self::$reading_canonical = $previous;
		}

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		return $this->from_url( $url, $type, $object_id, $language, $taxonomy );
	}

	/**
	 * Translates an already supplied canonical URL without reading a permalink inside its filter.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url       Canonical URL.
	 * @param string $type      Post or term.
	 * @param int    $object_id Object ID.
	 * @param string $language  Target language slug.
	 * @param string $taxonomy  Term taxonomy.
	 * @return string
	 */
	public function from_url( $url, $type, $object_id, $language, $taxonomy = '' ) {
		$url = $this->switch_language( $url, $language );

		return $this->is_default( $language ) ? $url : $this->translate_path( $url, $type, $object_id, $language, $taxonomy );
	}

	/**
	 * Tells what to do with a slug received under a language prefix.
	 *
	 * @since 2.3.0
	 *
	 * @param string   $slug     Slug as found in the request.
	 * @param string   $type     Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string   $language Language slug.
	 * @param string   $taxonomy Taxonomy of a term.
	 * @param int|null $parent   Parent term ID of a term, null for any.
	 * @return array{action:string,object_id:int,slug:string,url:string} Action is 'pass', 'map',
	 *               'redirect' or 'not_found'; slug is the canonical slug to hand to WordPress.
	 */
	public function resolve( $slug, $type, $language, $taxonomy = '', $parent = null ) {
		$slug = sanitize_title( urldecode( (string) $slug ) ); // Same pipeline as stored slugs.

		if ( $this->is_default( $language ) ) {
			return $this->outcome( 'pass', 0, $slug );
		}

		$id = $this->store::find( $slug, $language, $type, $taxonomy, $parent );

		if ( $id > 0 ) {
			return $this->outcome( 'map', $id, $this->canonical_slug( $type, $id, $taxonomy ) );
		}

		$id = $this->find_canonical( $slug, $type, $taxonomy, $parent );

		if ( $id > 0 ) {
			// An unpublished object is never redirected: that would confirm it exists and disclose its slug.
			if ( '' === $this->store::get( $id, $language, $type ) || ! $this->is_public( $type, $id ) ) {
				return $this->outcome( 'pass', $id, $slug );
			}

			return $this->retire( $id, $slug, $this->permalink( $type, $id, $language, $taxonomy ) );
		}

		// Last, because history is unbounded and a retired slug may now belong to another object.
		$id = $this->store::find_superseded( $slug, $language, $type, $taxonomy, $parent );

		if ( $id > 0 ) {
			if ( ! $this->is_public( $type, $id ) ) {
				return $this->outcome( 'not_found', 0, $slug );
			}

			return $this->outcome( 'redirect', $id, $this->canonical_slug( $type, $id, $taxonomy ), $this->permalink( $type, $id, $language, $taxonomy ) );
		}

		return $this->outcome( 'not_found', 0, $slug );
	}

	/**
	 * Tells what to do with a permalink base received under a language prefix.
	 *
	 * Same shape as resolve(), except that a redirect carries the target base in 'url' (there is no
	 * object to build a full URL from) and 'slug' is the canonical base.
	 *
	 * @since 2.3.0
	 *
	 * @param string $base      Base as found in the request.
	 * @param string $base_type One of Slug_Store::BASE_TYPES.
	 * @param string $language  Language slug.
	 * @return array{action:string,object_id:int,slug:string,url:string}
	 */
	public function resolve_base( $base, $base_type, $language ) {
		$base = trim( strtolower( urldecode( (string) $base ) ), '/' );

		if ( $this->is_default( $language ) ) {
			return $this->outcome( 'pass', 0, $base );
		}

		$canonical  = $this->canonical_base( $base_type );
		$translated = $this->store::get_base( $language, $base_type );

		if ( '' !== $translated && $base === $translated ) {
			return $this->outcome( 'map', 0, $canonical );
		}

		// An empty canonical base (WooCommerce's default for attributes) is only ever met as the empty request base.
		if ( $base === $canonical && ( '' === $translated || '' === $canonical ) ) {
			return $this->outcome( 'pass', 0, $base );
		}

		// An unknown canonical base ('') matches nothing: it must not redirect an empty request base.
		if ( $base === $canonical && '' !== $canonical ) {
			return $this->retire( 0, $canonical, $translated );
		}

		if ( '' !== $translated && $this->store::is_superseded_base( $base, $language, $base_type ) ) {
			return $this->outcome( 'redirect', 0, $canonical, $translated );
		}

		return $this->outcome( 'not_found', 0, $base );
	}

	/**
	 * Builds a resolution outcome.
	 *
	 * @since 2.3.0
	 *
	 * @param string $action    'pass', 'map', 'redirect' or 'not_found'.
	 * @param int    $object_id Object ID, 0 when unknown.
	 * @param string $slug      Canonical slug (or base) to hand to WordPress.
	 * @param string $url       Target of a redirect.
	 * @return array
	 */
	private function outcome( $action, $object_id, $slug, $url = '' ) {
		// A redirect without a target is useless: let WordPress decide.
		if ( 'redirect' === $action && '' === $url ) {
			$action = 'pass';
		}

		return array(
			'action'    => $action,
			'object_id' => (int) $object_id,
			'slug'      => $slug,
			'url'       => $url,
		);
	}

	/**
	 * Outcome of a canonical slug or base that has a translation (rules 1 and 3).
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Object ID, 0 for a base.
	 * @param string $slug      Canonical slug or base.
	 * @param string $url       Translated URL, or translated base.
	 * @return array
	 */
	private function retire( $object_id, $slug, $url ) {
		return $this->outcome( $this->canonical_under_prefix, $object_id, $slug, 'redirect' === $this->canonical_under_prefix ? $url : '' );
	}

	/**
	 * Tells whether an object may be redirected to without disclosing anything: published posts, any term.
	 *
	 * @since 2.3.0
	 *
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param int    $object_id Post or term ID.
	 * @return bool
	 */
	public function is_public( $type, $object_id ) {
		return Translation_Repository::TYPE_TERM === $type || 'publish' === get_post_status( $object_id );
	}

	/**
	 * Tells whether a language is the default one.
	 *
	 * @since 2.3.0
	 *
	 * @param string $language Language slug.
	 * @return bool
	 */
	private function is_default( $language ) {
		return linguator_default_language( 'slug' ) === $language;
	}

	/**
	 * Returns an object's canonical slug.
	 *
	 * @since 2.3.0
	 *
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param int    $object_id Post or term ID.
	 * @param string $taxonomy  Taxonomy of a term.
	 * @return string
	 */
	private function canonical_slug( $type, $object_id, $taxonomy ) {
		$slug = Translation_Repository::TYPE_TERM === $type ? get_term_field( 'slug', $object_id, $taxonomy ) : get_post_field( 'post_name', $object_id );

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * Finds the object whose canonical slug is the given one, whatever its status.
	 *
	 * With a null $parent and the same slug under several parents, the first match is returned.
	 *
	 * @since 2.3.0
	 *
	 * @param string   $slug     Canonical slug.
	 * @param string   $type     Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string   $taxonomy Taxonomy of a term.
	 * @param int|null $parent   Parent term ID, null for any.
	 * @return int Object ID, 0 when none.
	 */
	private function find_canonical( $slug, $type, $taxonomy, $parent ) {
		if ( Translation_Repository::TYPE_TERM === $type ) {
			if ( '' === $taxonomy ) {
				return 0;
			}

			$args = array(
				'taxonomy'   => $taxonomy,
				'slug'       => $slug,
				'hide_empty' => false,
				'fields'     => 'ids',
				'lang'       => '', // So that Linguator's language filter does not hide other languages' terms.
			);

			if ( null !== $parent ) {
				$args['parent'] = (int) $parent;
			}

			$ids = get_terms( $args );
		} else {
			$ids = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => 'product',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'lang'           => '',
				)
			);
		}

		return is_array( $ids ) && ! empty( $ids ) ? (int) reset( $ids ) : 0;
	}

	/**
	 * Returns the canonical permalink base of a base type, without the placeholders WooCommerce allows.
	 *
	 * @since 2.3.0
	 *
	 * @param string $base_type One of Slug_Store::BASE_TYPES.
	 * @return string Empty when unknown.
	 */
	private function canonical_base( $base_type ) {
		if ( ! function_exists( 'wc_get_permalink_structure' ) ) {
			return '';
		}

		$structure = wc_get_permalink_structure();
		$base      = isset( $structure[ $base_type . '_base' ] ) ? (string) $structure[ $base_type . '_base' ] : '';
		$cut       = strpos( $base, '%' );

		return trim( false === $cut ? $base : substr( $base, 0, $cut ), '/' );
	}

	/**
	 * Returns the base type that applies to a taxonomy.
	 *
	 * @since 2.3.0
	 *
	 * @param string $type     Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param string $taxonomy Taxonomy of a term.
	 * @return string One of Slug_Store::BASE_TYPES, empty when the taxonomy has no translatable base.
	 */
	private function base_type( $type, $taxonomy ) {
		if ( Translation_Repository::TYPE_TERM !== $type ) {
			return 'product';
		}

		if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
			return 'attribute';
		}

		$types = array(
			'product_cat' => 'category',
			'product_tag' => 'tag',
		);

		return isset( $types[ $taxonomy ] ) ? $types[ $taxonomy ] : '';
	}

	/**
	 * Replaces the slug segments and the base of a canonical URL with their translations.
	 *
	 * Terms are resolved per path segment: a translated parent with an untranslated child gives
	 * /<base>/<translated-parent>/<canonical-child>/. A URL whose shape is not the expected one is
	 * returned untouched. A %placeholder% after the base (such as %product_cat%) stays canonical.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url       Canonical URL, already in the target language.
	 * @param string $type      Translation_Repository::TYPE_POST or TYPE_TERM.
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Language slug.
	 * @param string $taxonomy  Taxonomy of a term.
	 * @return string
	 */
	private function translate_path( $url, $type, $object_id, $language, $taxonomy ) {
		$ids = array( $object_id );

		if ( Translation_Repository::TYPE_TERM === $type ) {
			for ( $id = $object_id; count( $ids ) < 10; ) { // Bounded against a parent loop.
				$id = get_term_field( 'parent', $id, $taxonomy );

				if ( ! is_numeric( $id ) || (int) $id <= 0 || in_array( (int) $id, $ids, true ) ) {
					break;
				}

				$id = (int) $id;
				array_unshift( $ids, $id );
			}
		}

		$canonical  = array();
		$translated = array();

		foreach ( $ids as $id ) {
			$slug         = $this->canonical_slug( $type, $id, $taxonomy );
			$canonical[]  = $slug;
			$custom       = $this->store::get( $id, $language, $type );
			$translated[] = '' === $custom ? $slug : $custom;
		}

		$parts    = explode( '?', $url, 2 );
		$path     = rtrim( $parts[0], '/' );
		$trailing = substr( $parts[0], strlen( $path ) );
		$segments = explode( '/', $path );
		$count    = count( $canonical );

		if ( in_array( '', $canonical, true ) || array_slice( $segments, -$count ) !== $canonical ) {
			return $url;
		}

		array_splice( $segments, -$count, $count, $translated );

		$base_type = $this->base_type( $type, $taxonomy );
		$new_base  = '' === $base_type ? '' : $this->store::get_base( $language, $base_type );
		$old_base  = '' === $new_base ? '' : $this->canonical_base( $base_type );

		if ( '' !== $old_base ) {
			$old_base = explode( '/', $old_base );
			$length   = count( $old_base );

			// From the right, down to index 3 so that scheme and host are never touched.
			for ( $i = count( $segments ) - $count - $length; $i >= 3; $i-- ) {
				if ( array_slice( $segments, $i, $length ) === $old_base ) {
					array_splice( $segments, $i, $length, explode( '/', $new_base ) );
					break;
				}
			}
		}

		return implode( '/', $segments ) . $trailing . ( isset( $parts[1] ) ? '?' . $parts[1] : '' );
	}

	/**
	 * Puts a URL in a language through Linguator's links model, the same way the WooCommerce
	 * integration does.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url      URL.
	 * @param string $language Language slug.
	 * @return string The URL unchanged when the links model is unavailable.
	 */
	private function switch_language( $url, $language ) {
		if ( ! function_exists( 'LMAT' ) ) {
			return $url;
		}

		$linguator = LMAT();

		if ( ! is_object( $linguator ) || ! isset( $linguator->links_model ) || ! method_exists( $linguator->links_model, 'switch_language_in_link' ) ) {
			return $url;
		}

		return (string) $linguator->links_model->switch_language_in_link( $url, $language );
	}
}
