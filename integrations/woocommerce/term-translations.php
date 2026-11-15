<?php
/**
 * Translated labels for product categories, tags and brands.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use WP_Term;

/**
 * Localizes what a product term is called, never what it is.
 *
 * Term IDs and slugs are canonical: they are what queries, permalinks and product relationships
 * are built on. Only the name and the description are translated, and only on read.
 *
 * Every localized term is a clone. WordPress caches term objects and hands the same instance to
 * every caller in a request, so translating one in place would poison canonical reads later in the
 * same request.
 *
 * `get_term`, `get_terms` and `wp_get_object_terms` are general WordPress filters: they fire for
 * every taxonomy, on every request, admin included, not just for the product taxonomies this
 * class cares about. Every entry point here checks the TAXONOMIES allow-list and the gate before
 * doing any other work, so a call for an unrelated taxonomy (categories, nav menus, post tags...)
 * costs one array membership check and returns.
 *
 * @since 2.3.0
 */
class Term_Translations {

	/**
	 * Taxonomies whose labels are translated.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

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
		add_filter( 'get_term', array( $this, 'localize_term' ), 10, 2 );
		add_filter( 'get_terms', array( $this, 'localize_terms' ), 10, 4 );
		add_filter( 'wp_get_object_terms', array( $this, 'localize_object_terms' ), 10, 4 );
	}

	/**
	 * Localizes one term.
	 *
	 * `get_term` fires for every taxonomy on every request, so the taxonomy allow-list is checked
	 * before anything else: a call for an unrelated taxonomy (categories, nav menus, post tags...)
	 * costs one membership check and returns the term unchanged.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed  $term     The term, usually a WP_Term.
	 * @param string $taxonomy Taxonomy name.
	 * @return mixed A localized clone, or the term unchanged.
	 */
	public function localize_term( $term, $taxonomy = '' ) {
		if ( ! $term instanceof WP_Term ) {
			return $term;
		}

		$taxonomy = '' === $taxonomy ? $term->taxonomy : $taxonomy;

		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			return $term;
		}

		$language = $this->language();

		if ( '' === $language ) {
			return $term;
		}

		$localized = clone $term;

		$localized->name = $this->repository->get_value(
			(int) $term->term_id,
			$language,
			'name',
			(string) $term->name,
			Translation_Repository::TYPE_TERM
		);

		$localized->description = $this->repository->get_value(
			(int) $term->term_id,
			$language,
			'description',
			(string) $term->description,
			Translation_Repository::TYPE_TERM
		);

		return $localized;
	}

	/**
	 * Localizes a list of terms.
	 *
	 * `get_terms()` can return an array of WP_Term objects, an array of term IDs, an array of
	 * slugs, or an array of counts, depending on the query's `fields` argument (see
	 * WP_Term_Query::get_terms() in wp-includes/class-wp-term-query.php). Only WP_Term instances
	 * are ever translated; every other entry — an int, a string slug, a numeric count — is passed
	 * straight through unchanged, so the shape the caller receives always matches the shape it
	 * asked for.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $terms      The query result.
	 * @param mixed $taxonomies Taxonomies queried. Unused.
	 * @param mixed $args       Query arguments. Unused.
	 * @param mixed $query      The term query. Unused.
	 * @return mixed
	 */
	public function localize_terms( $terms, $taxonomies = array(), $args = array(), $query = null ) {
		if ( ! is_array( $terms ) ) {
			return $terms;
		}

		foreach ( $terms as $index => $term ) {
			if ( $term instanceof WP_Term ) {
				$terms[ $index ] = $this->localize_term( $term );
			}
		}

		return $terms;
	}

	/**
	 * Localizes the terms attached to an object.
	 *
	 * `wp_get_object_terms` can likewise return WP_Term objects, IDs or slugs depending on the
	 * `fields` argument; localize_terms() already handles each shape safely.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $terms      The terms found.
	 * @param mixed $object_ids Object IDs. Unused.
	 * @param mixed $taxonomies Taxonomies queried. Unused.
	 * @param mixed $args       Query arguments. Unused.
	 * @return mixed
	 */
	public function localize_object_terms( $terms, $object_ids = array(), $taxonomies = array(), $args = array() ) {
		return $this->localize_terms( $terms );
	}

	/**
	 * Returns the language to translate into, or an empty string when there is none.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	protected function language() {
		if ( ! $this->gate->should_localize() || $this->frontend->is_default_language() ) {
			return '';
		}

		return $this->frontend->current_language();
	}
}
