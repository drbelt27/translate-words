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
		$segments           = $this->segments();
		$language           = empty( $segments ) ? '' : $segments[0]; // Exact, like Linguator's own prefix rules.

		if ( linguator_default_language( 'slug' ) === $language || ! in_array( $language, (array) linguator_languages_list(), true ) ) {
			return $query_vars;
		}

		$query_vars = $this->recover_mixed_case_base( (array) $query_vars, $segments, $language );
		$structure  = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : array();
		foreach ( self::QUERY_VARS as $name => $base_type ) {
			$base_key = 'product' === $name ? 'product_base' : $base_type . '_base';
			$base     = isset( $structure[ $base_key ] ) ? trim( (string) $structure[ $base_key ], '/' ) : '';

			if ( isset( $query_vars['attachment'] ) && '' !== $base && count( $segments ) === 3 && $segments[1] === $base && $segments[2] === $query_vars['attachment'] ) {
				$query_vars[ $name ] = $query_vars['attachment'];
				if ( 'product' === $name ) {
					$query_vars['post_type'] = 'product';
					$query_vars['name']      = $query_vars['attachment'];
				}
				unset( $query_vars['attachment'] );
				break;
			}
		}

		$var = $this->query_var( $query_vars );

		if ( '' === $var ) {
			return $this->map_attribute_query( $query_vars, $language );
		}

		$base_type = isset( self::QUERY_VARS[ $var ] ) ? self::QUERY_VARS[ $var ] : 'attribute';
		$base      = null;

		// The base may span several segments ("shop/produkt"): take the shortest prefix the resolver knows.
		// WooCommerce's default attribute base is empty. Only a path-origin pa_* archive tries it;
		// query-string pa_* remains lenient even on an attribute archive.
		$first = 0 === strpos( $var, 'pa_' ) && ! isset( $_GET[ $var ] ) && isset( $segments[1] ) && substr( $var, 3 ) === $segments[1] ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence only.

		for ( $length = $first; null === $base && $length < count( $segments ); $length++ ) {
			$outcome = $this->resolver->resolve_base( implode( '/', array_slice( $segments, 1, $length ) ), $base_type, $language );

			if ( 'not_found' !== $outcome['action'] ) {
				$base = $outcome;
				$rest = array_slice( $segments, $length + 1 );
			}
		}

		if ( null === $base ) {
			return $this->map_attribute_query( $query_vars, $language );
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

		if ( '' !== $this->redirect_url && ! empty( $_SERVER['QUERY_STRING'] ) ) {
			$query    = explode( '#', wp_unslash( $_SERVER['QUERY_STRING'] ), 2 )[0]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve encoded query parameters for the redirect.
			$existing = parse_url( $this->redirect_url, PHP_URL_QUERY );
			$keys     = array();

			foreach ( explode( '&', (string) $existing ) as $part ) {
				$keys[ rawurldecode( explode( '=', $part, 2 )[0] ) ] = true;
			}

			$append = array();
			foreach ( explode( '&', $query ) as $part ) {
				if ( '' !== $part && ! isset( $keys[ rawurldecode( explode( '=', $part, 2 )[0] ) ] ) ) {
					$append[] = $part;
				}
			}

			if ( $append ) {
				$this->redirect_url .= ( null === $existing ? '?' : '&' ) . implode( '&', $append );
			}
		}

		return $this->map_attribute_query( $query_vars, $language, $var );
	}

	/**
	 * Returns the canonical slugs of attribute term values from a query string.
	 *
	 * Translated, superseded and canonical values are all accepted, and an unknown one is returned as
	 * given: query strings have no 404 or redirect rule.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value    One slug, or a comma-separated list as in `filter_color=red,blue`.
	 * @param string $taxonomy Attribute taxonomy (pa_*).
	 * @param string $language Language slug.
	 * @return string
	 */
	public function map_attribute_value( $value, $taxonomy, $language ) {
		$slugs = explode( ',', (string) $value );

		foreach ( $slugs as $index => $slug ) {
			$known = sanitize_title( urldecode( $slug ) ); // Same pipeline as resolve().

			// Only a stored translated or superseded slug can change; checking the cached maps first keeps
			// a long or made-up list from costing one canonical-slug query per item.
			if ( $this->store::find( $known, $language, Translation_Repository::TYPE_TERM, $taxonomy ) > 0 || $this->store::find_superseded( $known, $language, Translation_Repository::TYPE_TERM, $taxonomy ) > 0 ) {
				$slugs[ $index ] = $this->resolver->resolve( $slug, Translation_Repository::TYPE_TERM, $language, $taxonomy )['slug'];
			}
		}

		return implode( ',', $slugs );
	}

	/**
	 * Maps the `filter_<attribute>` and `pa_*` query string values to canonical slugs where WooCommerce
	 * reads them: in the query vars (product filter blocks, attribute taxonomy queries) and in WC_Query's
	 * layered-nav cache, which WooCommerce fills from $_GET. $_GET itself is left as received.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $query_vars Query vars.
	 * @param string $language   Language of the prefix.
	 * @param string $path_var   Query var already resolved from the path, with its own 404 and 301 rules.
	 * @return array
	 */
	private function map_attribute_query( $query_vars, $language, $path_var = '' ) {
		foreach ( $query_vars as $key => $value ) {
			$taxonomy = 0 === strpos( (string) $key, 'filter_' ) ? 'pa_' . substr( $key, strlen( 'filter_' ) ) : (string) $key;

			if ( $key !== $path_var && is_string( $value ) && 0 === strpos( $taxonomy, 'pa_' ) ) {
				$query_vars[ $key ] = $this->map_attribute_value( $value, $taxonomy, $language );
			}
		}

		// WooCommerce 11.1 reads $_GET once in WC_Query::get_layered_nav_chosen_attributes() and offers no filter on it.
		// If WooCommerce changes the private static cache's shape, leave it alone.
		if ( class_exists( 'WC_Query' ) && property_exists( 'WC_Query', 'chosen_attributes' ) ) {
			$property = new \ReflectionProperty( 'WC_Query', 'chosen_attributes' );
			if ( ! $property->isStatic() ) {
				return $query_vars;
			}

			$chosen = \WC_Query::get_layered_nav_chosen_attributes();
			if ( ! is_array( $chosen ) ) {
				return $query_vars;
			}

			foreach ( $chosen as $taxonomy => $data ) {
				if ( ! is_array( $data ) || ! isset( $data['terms'] ) || ! is_array( $data['terms'] ) ) {
					return $query_vars;
				}

				$chosen[ $taxonomy ]['terms'] = explode( ',', $this->map_attribute_value( implode( ',', $data['terms'] ), $taxonomy, $language ) );
			}

			if ( ! empty( $chosen ) ) {
				if ( PHP_VERSION_ID < 80100 ) {
					$property->setAccessible( true ); // No-op since 8.1 and deprecated in 8.5.
				}

				$property->setValue( null, $chosen );
			}
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
		return $this->translated_rules( (array) $rules ) + (array) $rules;
	}

	/**
	 * Returns only the rules this router adds for translated and superseded bases.
	 *
	 * @since 2.3.0
	 *
	 * @param array $rules Rewrite rules holding the canonical ones.
	 * @return array
	 */
	private function translated_rules( $rules ) {
		$new = array();

		foreach ( array_keys( (array) get_option( Slug_Store::BASES_OPTION, array() ) ) as $language ) {
			$language = (string) $language;

			if ( linguator_default_language( 'slug' ) === $language ) {
				continue; // Rule 4.
			}

			foreach ( $this->bases( $language ) as $base_type => $bases ) {
				// A translated base maps to the canonical one, which the resolver returns as its slug.
				$canonical = $this->resolver->resolve_base( $bases[0], $base_type, $language )['slug'];

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

		return $new;
	}

	/**
	 * Returns a language's bases per base type: the translated one first, then the superseded ones.
	 *
	 * @since 2.3.0
	 *
	 * @param string $language Language slug.
	 * @return array<string,string[]> Only the base types that have a translated base.
	 */
	private function bases( $language ) {
		$option = (array) get_option( Slug_Store::BASES_OPTION, array() );
		$old    = isset( $option[ $language ]['old'] ) ? (array) $option[ $language ]['old'] : array();
		$bases  = array();

		foreach ( Slug_Store::BASE_TYPES as $base_type ) {
			$translated = $this->store::get_base( $language, $base_type );

			if ( '' !== $translated ) {
				$bases[ $base_type ] = array_merge( array( $translated ), isset( $old[ $base_type ] ) ? (array) $old[ $base_type ] : array() );
			}
		}

		return $bases;
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
	 * Re-parses a request whose translated base differs only by case, which WordPress's case-sensitive
	 * rules send to a catch-all. Anything else, including other 404s, is returned untouched.
	 *
	 * @since 2.3.0
	 *
	 * @param array    $query_vars Query vars from WordPress.
	 * @param string[] $segments   Request path segments.
	 * @param string   $language   Language of the prefix.
	 * @return array
	 */
	private function recover_mixed_case_base( $query_vars, $segments, $language ) {
		foreach ( $this->bases( $language ) as $bases ) {
			foreach ( $bases as $base ) {
				$length    = substr_count( $base, '/' ) + 1;
				$requested = implode( '/', array_slice( $segments, 1, $length ) );

				if ( $requested !== $base && strtolower( $requested ) === $base ) {
					$path = implode( '/', array_merge( array( $language, $base ), array_slice( $segments, 1 + $length ) ) );

					return $this->match( $this->translated_rules( (array) get_option( 'rewrite_rules', array() ) ), $path, $query_vars );
				}
			}
		}

		return $query_vars;
	}

	/**
	 * Matches a path against rewrite rules the way WP::parse_request() does: first rule wins, raw then decoded.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $rules      Rewrite rules.
	 * @param string $path       Request path.
	 * @param array  $query_vars Returned when no rule matches.
	 * @return array
	 */
	private function match( $rules, $path, $query_vars ) {
		foreach ( $rules as $match => $query ) {
			if ( ! preg_match( '#^' . $match . '#', $path, $matches ) && ! preg_match( '#^' . $match . '#', urldecode( $path ), $matches ) ) {
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

			return array_filter( $vars, 'strlen' );
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
