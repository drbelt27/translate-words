<?php
/**
 * Turns a request for a translated product or product taxonomy URL into the canonical query.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * The inbound half of translated slugs.
 *
 * Rewrite rules route /<lang>/<translated-base>/ to the same query vars as the canonical base; the
 * `request` filter then swaps translated slugs for canonical ones so that WordPress resolves the
 * object normally, and carries out the resolver's 301 and 404 outcomes.
 *
 * @since 2.3.0
 */
class Slug_Routing {

	/**
	 * Query vars of the shared objects, with the base type each one is served under.
	 */
	const QUERY_VARS = array(
		'product'     => 'product',
		'product_cat' => 'category',
		'product_tag' => 'tag',
	);

	/**
	 * Slug rules.
	 *
	 * @since 2.3.0
	 *
	 * @var Slug_Resolver
	 */
	private $resolver;

	/**
	 * Slug storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Slug_Store
	 */
	private $store;

	/**
	 * Where the current request must be redirected, empty when it must not.
	 *
	 * @since 2.3.0
	 *
	 * @var string
	 */
	private $redirect_url = '';

	/**
	 * Whether resolution rejected this request.
	 *
	 * @since 2.3.0
	 * @var bool
	 */
	private $not_found = false;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Slug_Resolver $resolver Slug rules.
	 * @param Slug_Store    $store    Slug storage.
	 */
	public function __construct( Slug_Resolver $resolver, Slug_Store $store ) {
		$this->resolver = $resolver;
		$this->store    = $store;
	}

	/**
	 * Setups the hooks. Called in shared mode only.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		// `request` runs once the rewrite rules matched and before WP_Query, on every WordPress version supported.
		add_filter( 'request', array( $this, 'map_request' ), 10, 1 );
		// After Linguator's own rewrite filters (10), so that it does not prefix these rules a second time.
		add_filter( 'rewrite_rules_array', array( $this, 'rewrite_rules' ), 20, 1 );
		// Before Linguator's canonical check (4) and WordPress core's (10).
		add_action( 'template_redirect', array( $this, 'redirect' ), 1, 0 );
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical_redirect' ), 1, 1 );
		add_action( 'add_option_' . Slug_Store::BASES_OPTION, array( $this, 'flush_if_bases_changed' ), 10, 0 );
		add_action( 'update_option_' . Slug_Store::BASES_OPTION, array( $this, 'flush_if_bases_changed' ), 10, 0 );
	}

	/**
	 * Replaces a translated slug with the canonical one and records the redirect or 404 outcome.
	 *
	 * Only requests under a non-default language prefix whose base is known (canonical, translated or
	 * superseded) are touched; the language is read from the URL prefix only.
	 *
	 * @since 2.3.0
	 *
	 * @param array $query_vars Query vars the rewrite rules produced.
	 * @return array
	 */
	public function map_request( $query_vars ) {
		$this->redirect_url = '';
		$this->not_found    = false;
		$query_vars         = $this->recover_unmatched_request( (array) $query_vars );
		$segments           = $this->segments();
		$language = empty( $segments ) ? '' : strtolower( $segments[0] );
		$var      = $this->query_var( (array) $query_vars );

		if ( '' === $var || linguator_default_language( 'slug' ) === $language || ! in_array( $language, (array) linguator_languages_list(), true ) ) {
			return $query_vars;
		}

		$base_type = isset( self::QUERY_VARS[ $var ] ) ? self::QUERY_VARS[ $var ] : 'attribute';
		$base      = null;

		// The base may span several segments ("shop/produkt"): take the shortest prefix the resolver knows.
		for ( $length = 1; null === $base && $length < count( $segments ); $length++ ) {
			$outcome = $this->resolver->resolve_base( implode( '/', array_slice( $segments, 1, $length ) ), $base_type, $language );

			if ( 'not_found' !== $outcome['action'] ) {
				$base = $outcome;
				$rest = array_slice( $segments, $length + 1 );
			}
		}

		if ( null === $base ) {
			return $query_vars;
		}

		$type      = 'product' === $var ? Translation_Repository::TYPE_POST : Translation_Repository::TYPE_TERM;
		$taxonomy  = 'product' === $var ? '' : $var;
		$parent    = 0;
		$object_id = 0;
		$redirect  = false;
		$slugs     = array();

		// Hierarchical terms arrive as "parent/child": each segment is resolved under the previous one.
		foreach ( explode( '/', (string) $query_vars[ $var ] ) as $slug ) {
			$outcome = $this->resolver->resolve( $slug, $type, $language, $taxonomy, $parent );

			if ( 'not_found' === $outcome['action'] ) {
				$this->not_found = true;
				return array( 'error' => '404' ); // A real 404 that leaves WordPress nothing to guess a redirect from.
			}

			$redirect  = $redirect || 'redirect' === $outcome['action'];
			$object_id = $outcome['object_id'];
			$parent    = $object_id;
			$slugs[]   = $outcome['slug'];
		}

		$query_vars[ $var ] = implode( '/', $slugs );
		if ( 'product' === $var && isset( $query_vars['name'] ) ) {
			$query_vars['name'] = $query_vars[ $var ];
		}

		if ( $redirect ) {
			$url   = $this->resolver->permalink( $type, $object_id, $language, $taxonomy );
			$paged = isset( $query_vars['paged'] ) ? (int) $query_vars['paged'] : 0;

			$this->redirect_url = $paged > 1 && '' !== $url ? user_trailingslashit( trailingslashit( $url ) . 'page/' . $paged ) : $url;
		} elseif ( 'redirect' === $base['action'] && ( Translation_Repository::TYPE_TERM === $type || 'publish' === get_post_status( $object_id ) ) ) {
			// Only the base is retired: swap it in the requested path. Never for an unpublished product, which would disclose it.
			$this->redirect_url = home_url( user_trailingslashit( implode( '/', array_merge( array( $language, $base['url'] ), $rest ) ) ) );
		}

		return $query_vars;
	}

	/**
	 * Adds, for each non-default language with a translated base, the canonical base's rules under
	 * /<lang>/<translated-base>/ and under each superseded base (so that they can 301).
	 *
	 * @since 2.3.0
	 *
	 * @param array $rules Rewrite rules.
	 * @return array
	 */
	public function rewrite_rules( $rules ) {
		$new = array();

		foreach ( (array) get_option( Slug_Store::BASES_OPTION, array() ) as $language => $types ) {
			if ( linguator_default_language( 'slug' ) === $language ) {
				continue; // Rule 4.
			}

			foreach ( Slug_Store::BASE_TYPES as $base_type ) {
				$translated = $this->store::get_base( $language, $base_type );

				if ( '' === $translated ) {
					continue;
				}

				// A translated base maps to the canonical one, which the resolver returns as its slug.
				$canonical = $this->resolver->resolve_base( $translated, $base_type, $language )['slug'];
				$bases     = array_merge( array( $translated ), isset( $types['old'][ $base_type ] ) ? (array) $types['old'][ $base_type ] : array() );

				foreach ( $rules as $key => $rule ) {
					if ( '' === $canonical || ! is_string( $key ) || ! is_string( $rule ) || 0 !== strpos( $key, $canonical . '/' ) ) {
						continue;
					}

					// Linguator may already have tagged the canonical rule with the default language.
					$rule = preg_replace( '/([?&])lmat_lang=[^&]*&?/', '$1', $rule );
					$rule = preg_replace( '/\?/', '?lmat_lang=' . $language . '&', $rule, 1 );

					foreach ( $bases as $base ) {
						$new[ $language . '/' . $base . substr( $key, strlen( $canonical ) ) ] = $rule;
					}
				}
			}
		}

		return $new + (array) $rules;
	}

	/**
	 * Flushes the rewrite rules. Hooked to the bases option being added or updated, which WordPress
	 * fires only when its value changes.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function flush_if_bases_changed() {
		flush_rewrite_rules( false );
	}

	/**
	 * Returns where the current request must be redirected.
	 *
	 * @since 2.3.0
	 *
	 * @return string Empty when it must not.
	 */
	public function redirect_url() {
		return $this->redirect_url;
	}

	/**
	 * Issues the 301 decided by map_request(), on GET and HEAD only, like WordPress core's canonical redirect.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function redirect() {
		if ( $this->not_found ) {
			if ( isset( $GLOBALS['wp_query'] ) && is_object( $GLOBALS['wp_query'] ) && method_exists( $GLOBALS['wp_query'], 'set_404' ) ) {
				$GLOBALS['wp_query']->set_404();
			}
			status_header( 404 );
			nocache_headers();
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( '' !== $this->redirect_url && in_array( $method, array( 'GET', 'HEAD' ), true ) && wp_safe_redirect( $this->redirect_url, 301 ) ) {
			exit;
		}
	}

	/**
	 * Stops WordPress from guessing a redirect for a URL this router rejected.
	 *
	 * @since 2.3.0
	 *
	 * @param string|false $redirect_url Core's proposed redirect.
	 * @return string|false
	 */
	public function prevent_canonical_redirect( $redirect_url ) {
		return $this->not_found ? false : $redirect_url;
	}

	/**
	 * Re-parses a 404 whose rewrite match failed only because of case or percent-encoding.
	 *
	 * @since 2.3.0
	 *
	 * @param array $query_vars Query vars from WordPress.
	 * @return array
	 */
	private function recover_unmatched_request( $query_vars ) {
		if ( array( 'error' => '404' ) !== $query_vars ) {
			return $query_vars;
		}

		$path = strtolower( rawurldecode( implode( '/', $this->segments() ) ) );

		foreach ( $this->rewrite_rules( (array) get_option( 'rewrite_rules', array() ) ) as $match => $query ) {
			if ( ! is_string( $match ) || ! is_string( $query ) || ! preg_match( '#^' . $match . '#', $path, $matches ) ) {
				continue;
			}

			$query = substr( $query, (int) strpos( $query, '?' ) + 1 );
			$query = preg_replace_callback(
				'/\$matches\[(\d+)\]/',
				function ( $index ) use ( $matches ) {
					return isset( $matches[ $index[1] ] ) ? rawurlencode( $matches[ $index[1] ] ) : '';
				},
				$query
			);
			parse_str( $query, $vars );

			return $vars;
		}

		return $query_vars;
	}

	/**
	 * Returns the shared-object query var of a request.
	 *
	 * @since 2.3.0
	 *
	 * @param array $query_vars Query vars.
	 * @return string Empty when the request is for none.
	 */
	private function query_var( $query_vars ) {
		// In this order: a product URL may also carry its category (%product_cat%), an archive an attribute filter.
		$vars = array_merge( array_keys( self::QUERY_VARS ), preg_grep( '/^pa_/', array_keys( $query_vars ) ) );

		foreach ( $vars as $var ) {
			if ( isset( $query_vars[ $var ] ) && is_string( $query_vars[ $var ] ) && '' !== $query_vars[ $var ] ) {
				return (string) $var;
			}
		}

		return '';
	}

	/**
	 * Returns the segments of the requested path, as WordPress parsed it.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	private function segments() {
		$path = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->request ) ? (string) $GLOBALS['wp']->request : '';

		return array_values( array_filter( explode( '/', $path ), 'strlen' ) );
	}
}
