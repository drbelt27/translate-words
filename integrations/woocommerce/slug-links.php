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

use WP_Term;

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
		add_filter( 'lmat_rel_hreflang_attributes', array( $this, 'hreflang' ), 10, 1 );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'sitemap_entry' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_entry', array( $this, 'sitemap_term_entry' ), 10, 4 );
		add_filter( 'lmat_sitemap_subtype_per_language', array( $this, 'sitemap_per_language' ), 10, 3 );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'schema_url' ), 10, 2 );
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
		if ( Slug_Resolver::reading_canonical() || ! Shared_Mode::is_enabled() || ! $this->is_shared_taxonomy( $taxonomy ) ) {
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

	/**
	 * Returns the canonical link target of the current product or product term archive, in the
	 * current language.
	 *
	 * Self-referencing: a crawler reading `/de/` gets the translated `/de/` URL, never the canonical
	 * slug. Paged archives and other pages keep the SEO plugin's own URL.
	 *
	 * @since 2.3.0
	 * @param string $url URL the SEO plugin computed.
	 * @return string
	 */
	public function canonical_url( $url ) {
		$object = $this->queried();
		return null === $object ? $url : $this->language_url( $url, $object[0], $object[1], $object[2] );
	}

	/**
	 * Adds an x-default alternate on a shared product or term archive, which Linguator only prints on
	 * the front page. It is the default language's (canonical) URL.
	 *
	 * @since 2.3.0
	 * @param array $hreflangs Alternate URLs keyed by language code.
	 * @return array
	 */
	public function hreflang( $hreflangs ) {
		$object = $this->queried();

		if ( null === $object || empty( $hreflangs ) || isset( $hreflangs['x-default'] ) ) {
			return $hreflangs;
		}

		$url = $this->language_url( '', $object[0], $object[1], $object[2], (string) linguator_default_language( 'slug' ) );

		if ( '' !== $url ) {
			$hreflangs['x-default'] = $url;
		}

		return $hreflangs;
	}

	/**
	 * Returns the current language's URL of a sitemap object.
	 *
	 * @since 2.3.0
	 * @param string $url    URL WordPress computed for the entry.
	 * @param object $object Product post or product term.
	 * @return string
	 */
	public function sitemap_url( $url, $object ) {
		if ( $object instanceof WP_Term && $this->is_shared_taxonomy( $object->taxonomy ) ) {
			return $this->language_url( $url, 'term', $object->term_id, $object->taxonomy );
		}

		if ( is_object( $object ) && isset( $object->ID ) && 'product' === get_post_type( $object ) ) {
			return $this->language_url( $url, 'post', $object->ID );
		}

		return $url;
	}

	/**
	 * Core sitemap entry filter for posts.
	 *
	 * @since 2.3.0
	 * @param array  $entry Sitemap entry.
	 * @param object $post  Post.
	 * @return array
	 */
	public function sitemap_entry( $entry, $post ) {
		$entry['loc'] = $this->sitemap_url( $entry['loc'], $post );
		return $entry;
	}

	/**
	 * Core sitemap entry filter for terms.
	 *
	 * @since 2.3.0
	 * @param array   $entry    Sitemap entry.
	 * @param int     $term_id  Term ID, unused.
	 * @param string  $taxonomy Taxonomy, unused.
	 * @param WP_Term $term     Term.
	 * @return array
	 */
	public function sitemap_term_entry( $entry, $term_id, $taxonomy, $term ) {
		$entry['loc'] = $this->sitemap_url( $entry['loc'], $term );
		return $entry;
	}

	/**
	 * Gives shared products and terms one sitemap per language, so each language lists only its own URL.
	 *
	 * Linguator splits a sitemap by language only for translated types, and shared types are not.
	 *
	 * @since 2.3.0
	 * @param bool   $per_language Whether Linguator already splits this subtype by language.
	 * @param string $provider     Sitemap provider name.
	 * @param string $subtype      Post type or taxonomy.
	 * @return bool
	 */
	public function sitemap_per_language( $per_language, $provider, $subtype ) {
		if ( $per_language || ! Shared_Mode::is_enabled() ) {
			return $per_language;
		}

		return ( 'posts' === $provider && 'product' === $subtype ) || ( 'taxonomies' === $provider && $this->is_shared_taxonomy( $subtype ) );
	}

	/**
	 * Puts the current language's URL in WooCommerce's Product JSON-LD.
	 *
	 * @since 2.3.0
	 * @param array  $markup  Structured data.
	 * @param object $product WC_Product.
	 * @return array
	 */
	public function schema_url( $markup, $product = null ) {
		if ( ! is_array( $markup ) || empty( $markup['url'] ) || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || 'product' !== get_post_type( $product->get_id() ) ) {
			return $markup;
		}

		$url = $this->language_url( $markup['url'], 'post', $product->get_id() );

		if ( $url !== $markup['url'] ) {
			$markup['url'] = $url;

			if ( isset( $markup['@id'] ) ) {
				$markup['@id'] = $url . '#product'; // WooCommerce's own suffix.
			}
		}

		return $markup;
	}

	/**
	 * Returns the URL of an object in a language, or the given one when it must not change.
	 *
	 * An unpublished product keeps the URL it was given, so a crawler never reads a translated slug
	 * the resolver would not redirect to either.
	 *
	 * @since 2.3.0
	 * @param string $url       URL to keep when the object cannot be translated.
	 * @param string $type      Post or term.
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Term taxonomy.
	 * @param string $language  Language slug, the current one when empty.
	 * @return string
	 */
	private function language_url( $url, $type, $object_id, $taxonomy = '', $language = '' ) {
		$language  = '' === $language ? (string) linguator_current_language( 'slug' ) : $language;
		$object_id = (int) $object_id;

		if ( '' === $language || $object_id <= 0 || ! Shared_Mode::is_enabled() || ! $this->resolver->is_public( $type, $object_id ) ) {
			return $url;
		}

		$link = $this->resolver->permalink( $type, $object_id, $language, $taxonomy );
		return '' === $link ? $url : $link;
	}

	/**
	 * Identifies the shared product or term archive being displayed.
	 *
	 * @since 2.3.0
	 * @return array|null Type, ID and taxonomy, null on any other request and on paged archives.
	 */
	private function queried() {
		if ( function_exists( 'is_paged' ) && is_paged() ) {
			return null;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return array( 'post', get_queried_object_id(), '' );
		}

		if ( function_exists( 'is_tax' ) && is_tax() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term && self::is_shared_taxonomy( $term->taxonomy ) ) {
				return array( 'term', $term->term_id, $term->taxonomy );
			}
		}

		return null;
	}

	/**
	 * Tells whether a taxonomy takes translated term slugs: product category, tag, brand and pa_*.
	 *
	 * @since 2.3.0
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public static function is_shared_taxonomy( $taxonomy ) {
		return in_array( $taxonomy, Term_Translations::TAXONOMIES, true ) || 0 === strpos( (string) $taxonomy, 'pa_' );
	}
}
