<?php
/**
 * Covers the inbound half of translated slugs: a translated URL becomes the canonical query,
 * a retired one 301s and an unknown one is a real 404.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Linguator_WooCommerce;
use Linguator\Integrations\woocommerce\Slug_Resolver;
use Linguator\Integrations\woocommerce\Slug_Routing;
use Linguator\Integrations\woocommerce\Slug_Store;

class Slug_Routing_Test extends TestCase {

	/**
	 * Resets the simulated request and describes product 5 (canonical slug "open-day", German slug
	 * "open-day-de", German product base "produkt") and categories 10 > 11.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		unset( $GLOBALS['wp'] );
		lmat_test_set( 'post_names', array( 5 => 'open-day' ) );
		lmat_test_set( 'permalinks', array( 5 => 'https://example.test/prodotto/open-day/' ) );
		lmat_test_set(
			'term_slugs',
			array(
				10 => array( 'slug' => 'parent-c', 'taxonomy' => 'product_cat', 'parent' => 0 ),
				11 => array( 'slug' => 'child-c', 'taxonomy' => 'product_cat', 'parent' => 10 ),
			)
		);
		lmat_test_set( 'term_links', array( 10 => 'https://example.test/categoria-prodotto/parent-c/', 11 => 'https://example.test/categoria-prodotto/parent-c/child-c/' ) );
		Slug_Store::set_base( 'de', 'product', 'produkt' );
		Slug_Store::set_base( 'de', 'category', 'kategorie' );
		Slug_Store::set( 5, 'de', 'open-day-de' );
	}

	/**
	 * Drops the simulated WP object.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp'], $GLOBALS['wp_query'], $_COOKIE['lmat_language'], $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		$_GET = array();
		if ( class_exists( 'WC_Query' ) ) {
			WC_Query::reset_chosen_attributes();
		}
		parent::tearDown();
	}

	/**
	 * A realistic rule set: canonical product rules, Linguator's prefixed product rules and the
	 * prefixed page catch-all, behind the translated rules this router adds.
	 *
	 * @param Slug_Routing $routing Router.
	 * @return array
	 */
	private function stored_rules( Slug_Routing $routing ) {
		$rules = $routing->rewrite_rules(
			array(
				'(de|fr|en)/prodotto/([^/]+)(?:/([0-9]+))?/?$' => 'index.php?lmat_lang=$matches[1]&product=$matches[2]&page=$matches[3]',
				'prodotto/([^/]+)(?:/([0-9]+))?/?$'           => 'index.php?lmat_lang=it&product=$matches[1]&page=$matches[2]',
				'(de|fr|en)/(.?.+?)(?:/([0-9]+))?/?$'         => 'index.php?lmat_lang=$matches[1]&pagename=$matches[2]&page=$matches[3]',
				'(.?.+?)(?:/([0-9]+))?/?$'                    => 'index.php?lmat_lang=it&pagename=$matches[1]&page=$matches[2]',
			)
		);
		update_option( 'rewrite_rules', $rules );

		return $rules;
	}

	/**
	 * Matches a path the way WP::parse_request() does: first rule wins, raw then decoded.
	 *
	 * @param array  $rules Rewrite rules.
	 * @param string $path  Request path.
	 * @return array
	 */
	private function wp_match( $rules, $path ) {
		foreach ( $rules as $match => $query ) {
			if ( preg_match( "#^$match#", $path, $matches ) || preg_match( "#^$match#", urldecode( $path ), $matches ) ) {
				$query = preg_replace_callback(
					'/\$matches\[(\d+)\]/',
					function ( $index ) use ( $matches ) {
						return isset( $matches[ $index[1] ] ) ? rawurlencode( $matches[ $index[1] ] ) : '';
					},
					substr( $query, strpos( $query, '?' ) + 1 )
				);
				parse_str( $query, $vars );

				return array_filter( $vars, 'strlen' );
			}
		}

		return array( 'error' => '404' );
	}

	/**
	 * Review Focus 2 with real rule order: a mixed-case translated base falls to the page catch-all
	 * in WordPress, and is recovered to the product; a percent-encoded slug is matched by WordPress.
	 *
	 * @return void
	 */
	public function test_mixed_case_base_caught_by_the_page_catch_all_is_recovered() {
		$routing = $this->routing();
		$rules   = $this->stored_rules( $routing );

		$caught = $this->wp_match( $rules, 'de/Produkt/Open-Day-DE' );
		$this->assertSame( array( 'lmat_lang' => 'de', 'pagename' => 'Produkt/Open-Day-DE' ), $caught );
		$this->assertSame( array( 'lmat_lang' => 'de', 'product' => 'open-day' ), $this->request( $routing, 'de/Produkt/Open-Day-DE', $caught ) );

		$encoded = $this->wp_match( $rules, 'de/produkt/open%2Dday%2Dde' );
		$this->assertSame( array( 'lmat_lang' => 'de', 'product' => 'open-day' ), $this->request( $routing, 'de/produkt/open%2Dday%2Dde', $encoded ) );
	}

	/**
	 * Recovery is limited to a known translated base under its language: other 404s and pages are untouched.
	 *
	 * @return void
	 */
	public function test_unrelated_requests_are_never_rerouted() {
		$routing = $this->routing();
		$rules   = $this->stored_rules( $routing );

		foreach ( array( 'de/Ueber-Uns', 'fr/Produkt/open-day-de', 'Produkt/open-day-de', 'de/Kategorie-X/foo' ) as $path ) {
			$caught = $this->wp_match( $rules, $path );
			$this->assertSame( $caught, $this->request( $routing, $path, $caught ), $path );
		}

		$this->assertSame( array( 'error' => '404' ), $this->request( $routing, 'de/produkt-x', array( 'error' => '404' ) ) );
	}

	/**
	 * The product query var WordPress derives (name) follows the canonical slug.
	 *
	 * @return void
	 */
	public function test_name_query_var_follows_the_canonical_product_slug() {
		$this->assertSame(
			array( 'product' => 'open-day', 'name' => 'open-day', 'post_type' => 'product' ),
			$this->request( $this->routing(), 'de/produkt/open-day-de', array( 'product' => 'open-day-de', 'name' => 'open-day-de', 'post_type' => 'product' ) )
		);
	}

	/**
	 * Switching shared mode on or off invalidates the stored rewrite rules once, through the settings route.
	 *
	 * @return void
	 */
	public function test_toggling_shared_mode_invalidates_rewrite_rules() {
		require_once dirname( __DIR__, 2 ) . '/modules/rest/abstract-controller.php';
		require_once dirname( __DIR__, 2 ) . '/modules/rest/v1/settings.php';

		$options = new class() {
			public $values = array( 'wc_shared_product_identity' => false, 'force_lang' => 1 );
			public function get_schema() {
				return array( 'wc_shared_product_identity' => array(), 'force_lang' => array() );
			}
			public function get( $name ) {
				return isset( $this->values[ $name ] ) ? $this->values[ $name ] : null;
			}
			public function set( $name, $value ) {
				$this->values[ $name ] = $value;
				return new WP_Error();
			}
			public function get_all() {
				return $this->values;
			}
		};
		$settings = ( new ReflectionClass( \Linguator\Modules\REST\V1\Settings::class ) )->newInstanceWithoutConstructor();
		$property = new ReflectionProperty( $settings, 'options' );
		$property->setAccessible( true );
		$property->setValue( $settings, $options );

		$settings->update_item( new Lmat_Test_REST_Request( array( 'wc_shared_product_identity' => true ) ) );
		$this->assertSame( array( 'rewrite_rules' ), lmat_test_get( 'deleted_options', array() ) );

		$settings->update_item( new Lmat_Test_REST_Request( array( 'wc_shared_product_identity' => true ) ) );
		$this->assertSame( array( 'rewrite_rules' ), lmat_test_get( 'deleted_options', array() ), 'Unchanged mode must not invalidate again.' );
	}

	/**
	 * Hierarchical resolution must start at parent zero, not an arbitrary matching descendant.
	 *
	 * @return void
	 */
	public function test_a_child_cannot_be_used_as_a_root_category() {
		$routing = $this->routing();
		$this->assertSame( array( 'error' => '404' ), $this->request( $routing, 'de/kategorie/child-c', array( 'product_cat' => 'child-c' ) ) );
	}

	/**
	 * Unknown slugs send HTTP 404 and cannot be guessed by core; redirects issue one safe 301.
	 *
	 * @return void
	 */
	public function test_http_outcomes_are_executed() {
		$routing = $this->routing();
		$routing->init();
		$this->request( $routing, 'de/produkt/unknown', array( 'product' => 'unknown' ) );
		$GLOBALS['wp_query'] = new Lmat_Test_Query();
		$routing->redirect();
		$this->assertTrue( $GLOBALS['wp_query']->is_404 );
		$this->assertSame( 404, lmat_test_get( 'status_header' ) );
		$this->assertFalse( apply_filters( 'redirect_canonical', 'https://example.test/', 'https://example.test/de/produkt/unknown/' ) );
		unset( $GLOBALS['wp_query'] );

		$this->request( $routing, 'de/prodotto/open-day', array( 'product' => 'open-day' ) );
		try {
			$routing->redirect();
		} catch ( Lmat_Test_Redirect $redirect ) {
			$this->assertSame( array( 'https://example.test/de/produkt/open-day-de/', 301 ), lmat_test_get( 'safe_redirect' ) );
			return;
		}
		$this->fail( 'Expected wp_safe_redirect().' );
	}

	/**
	 * Layered-nav and attribute query strings take the translated and the canonical value, a list maps
	 * item by item, unknown values stay as given (no 404, no redirect) and $_GET is never rewritten.
	 * WooCommerce reads filter_* twice: from the query vars (product filter blocks) and from $_GET
	 * (WC_Query's layered nav); both readers get the canonical slugs.
	 *
	 * @return void
	 */
	public function test_filter_query_accepts_translated_and_canonical_values() {
		lmat_test_set(
			'term_slugs',
			array(
				10 => array( 'slug' => 'parent-c', 'taxonomy' => 'product_cat', 'parent' => 0 ),
				20 => array( 'slug' => 'red', 'taxonomy' => 'pa_color', 'parent' => 0 ),
				21 => array( 'slug' => 'blue', 'taxonomy' => 'pa_color', 'parent' => 0 ),
				22 => array( 'slug' => 'green', 'taxonomy' => 'pa_color', 'parent' => 0 ),
			)
		);
		Slug_Store::set( 20, 'de', 'rot', 'term', 'pa_color' );
		Slug_Store::set( 22, 'de', 'gruen-alt', 'term', 'pa_color' );
		Slug_Store::set( 22, 'de', 'gruen', 'term', 'pa_color' );
		$routing = $this->routing();

		$this->assertSame( 'red', $routing->map_attribute_value( 'rot', 'pa_color', 'de' ) );
		$this->assertSame( 'red', $routing->map_attribute_value( 'red', 'pa_color', 'de' ) );
		$this->assertSame( 'red,blue,green,green,nope', $routing->map_attribute_value( 'rot,blue,gruen-alt,gruen,nope', 'pa_color', 'de' ) );
		$this->assertSame( 'rot', $routing->map_attribute_value( 'rot', 'pa_color', 'it' ), 'The default language only knows canonical slugs.' );

		$_GET  = array( 'filter_color' => 'rot,blue', 'query_type_color' => 'or' );
		$query = array(
			'pagename'            => 'shop',
			'filter_color'        => 'rot,blue',
			'pa_color'            => 'rot',
			'filter_stock_status' => 'instock',
			'attribute_pa_color'  => 'rot', // Variation matching stays canonical-only.
		);

		$this->assertSame(
			array(
				'pagename'            => 'shop',
				'filter_color'        => 'red,blue',
				'pa_color'            => 'red',
				'filter_stock_status' => 'instock',
				'attribute_pa_color'  => 'rot',
			),
			$this->request( $routing, 'de/shop', $query )
		);
		$this->assertSame( '', $routing->redirect_url() );
		$this->assertSame( array( 'filter_color' => 'rot,blue', 'query_type_color' => 'or' ), $_GET );

		if ( class_exists( 'WC_Query' ) ) {
			$this->assertSame( array( 'pa_color' => array( 'terms' => array( 'red', 'blue' ), 'query_type' => 'and' ) ), WC_Query::get_layered_nav_chosen_attributes() );
		}

		$this->assertSame( array( 'pagename' => 'shop', 'filter_color' => 'red' ), $this->request( $routing, 'de/shop', array( 'pagename' => 'shop', 'filter_color' => 'rot' ) ) );
		$this->assertSame( array( 'product_cat' => 'parent-c', 'filter_color' => 'red' ), $this->request( $routing, 'de/kategorie/parent-c', array( 'product_cat' => 'parent-c', 'filter_color' => 'rot' ) ), 'A category archive with a layered-nav filter.' );
		$this->assertSame( array( 'pa_color' => 'red' ), $this->request( $routing, 'de/color/rot', array( 'pa_color' => 'rot' ) ), 'An attribute archive without an attribute base (the WooCommerce default).' );
		$this->assertSame( array( 'filter_color' => 'rot' ), $this->request( $routing, '', array( 'filter_color' => 'rot' ) ), 'Unprefixed (default language) requests are untouched.' );
	}

	/**
	 * A long or made-up filter list costs no per-item canonical-slug query: only values found in the
	 * cached slug maps reach the resolver.
	 *
	 * @return void
	 */
	public function test_filter_values_unknown_to_the_slug_maps_cost_no_term_query() {
		lmat_test_set( 'term_slugs', array( 20 => array( 'slug' => 'red', 'taxonomy' => 'pa_color', 'parent' => 0 ) ) );
		Slug_Store::set( 20, 'de', 'rot', 'term', 'pa_color' );
		$values = implode( ',', array_map( 'strval', range( 1, 50 ) ) );
		lmat_test_set( 'get_terms_calls', 0 );

		$this->assertSame( 'red,' . $values, $this->routing()->map_attribute_value( 'rot,' . $values, 'pa_color', 'de' ) );
		$this->assertSame( 0, lmat_test_get( 'get_terms_calls' ) );
	}

	/**
	 * An attribute archive slug is resolved once, by the path rules: a German slug that equals another
	 * term's canonical slug (allowed once that term has its own German slug) is not resolved a second time.
	 *
	 * @return void
	 */
	public function test_attribute_archive_slug_is_resolved_once() {
		lmat_test_set( 'permalink_structure', array( 'attribute_base' => 'attributo' ) );
		lmat_test_set(
			'term_slugs',
			array(
				20 => array( 'slug' => 'rosso', 'taxonomy' => 'pa_color', 'parent' => 0 ),
				23 => array( 'slug' => 'blu', 'taxonomy' => 'pa_color', 'parent' => 0 ),
				24 => array( 'slug' => 'verde', 'taxonomy' => 'pa_color', 'parent' => 0 ),
			)
		);
		Slug_Store::set( 20, 'de', 'rot', 'term', 'pa_color' );
		Slug_Store::set( 23, 'de', 'rosso', 'term', 'pa_color' );
		Slug_Store::set( 24, 'de', 'blu', 'term', 'pa_color' );

		$this->assertSame( array( 'pa_color' => 'blu' ), $this->request( $this->routing(), 'de/attributo/color/rosso', array( 'pa_color' => 'rosso' ) ) );
	}

	/**
	 * A base-less attribute archive path (the WooCommerce default) follows the strict path rules: canonical
	 * 301s once, unknown is a real 404, translated is served, superseded 301s once. The same values in a
	 * query string stay lenient, and a translated attribute base changes nothing when the canonical one is empty.
	 *
	 * @return void
	 */
	public function test_baseless_attribute_archive_path_follows_the_strict_rules() {
		lmat_test_set( 'term_slugs', array( 20 => array( 'slug' => 'red', 'taxonomy' => 'pa_color', 'parent' => 0 ) ) );
		lmat_test_set( 'term_links', array( 20 => 'https://example.test/color/red/' ) );
		Slug_Store::set( 20, 'de', 'rot-alt', 'term', 'pa_color' );
		Slug_Store::set( 20, 'de', 'rot', 'term', 'pa_color' );

		foreach ( array( false, true ) as $translated_base ) {
			if ( $translated_base ) {
				Slug_Store::set_base( 'de', 'attribute', 'attribut' );
			}

			foreach ( array( 'red', 'rot-alt' ) as $retired ) {
				$routing = $this->routing();
				$this->request( $routing, 'de/color/' . $retired, array( 'pa_color' => $retired ) );
				$this->assertSame( 'https://example.test/de/color/rot/', $routing->redirect_url(), "$translated_base $retired" );
				$this->assertSame( array( 'pa_color' => 'red' ), $this->request( $routing, 'de/color/rot', array( 'pa_color' => 'rot' ) ) );
				$this->assertSame( '', $routing->redirect_url(), 'Redirect must stop at its target.' );
			}

			$routing = $this->routing();
			$this->assertSame( array( 'error' => '404' ), $this->request( $routing, 'de/color/nope', array( 'pa_color' => 'nope' ) ), $translated_base );
			$GLOBALS['wp_query'] = new Lmat_Test_Query();
			$routing->redirect();
			$this->assertSame( 404, lmat_test_get( 'status_header' ), $translated_base );
			$this->assertTrue( $GLOBALS['wp_query']->is_404, $translated_base );

			$routing = $this->routing();
			$this->assertSame( array( 'pa_color' => 'red' ), $this->request( $routing, 'de/color/rot', array( 'pa_color' => 'rot' ) ), $translated_base );
			$this->assertSame( '', $routing->redirect_url(), $translated_base );

			// The same values in a query string are lenient: no 404, no redirect, $_GET untouched.
			$_GET    = array( 'pa_color' => 'red' );
			$routing = $this->routing();
			$this->assertSame( array( 'pagename' => 'shop', 'pa_color' => 'red' ), $this->request( $routing, 'de/shop', array( 'pagename' => 'shop', 'pa_color' => 'red' ) ), $translated_base );
			$this->assertSame( '', $routing->redirect_url(), $translated_base );
			$this->assertSame( array( 'pa_color' => 'red' ), $this->request( $routing, 'de/color/rot', array( 'pa_color' => 'red' ) ), 'Query-string pa_color must not become a path slug.' );
			$this->assertSame( '', $routing->redirect_url(), $translated_base );
			$this->assertSame( array( 'pa_color' => 'red' ), $_GET );
			$this->assertSame( array( 'pagename' => 'shop', 'pa_color' => 'nope' ), $this->request( $this->routing(), 'de/shop', array( 'pagename' => 'shop', 'pa_color' => 'nope' ) ), $translated_base );
			$_GET = array();
		}
	}

	/**
	 * Reflection into WooCommerce's private layered-nav cache must never fatal: a cache whose terms are
	 * not an array is left alone.
	 *
	 * @return void
	 */
	public function test_layered_nav_cache_with_non_array_terms_is_left_alone() {
		if ( ! class_exists( 'WC_Query' ) ) {
			$this->assertSame( array( 'pagename' => 'shop' ), $this->request( $this->routing(), 'de/shop', array( 'pagename' => 'shop' ) ) );
			return;
		}

		$property = new \ReflectionProperty( 'WC_Query', 'chosen_attributes' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, array( 'pa_color' => array( 'terms' => 'rot', 'query_type' => 'and' ) ) );

		$this->assertSame( array( 'pagename' => 'shop' ), $this->request( $this->routing(), 'de/shop', array( 'pagename' => 'shop' ) ) );
		$this->assertSame( array( 'pa_color' => array( 'terms' => 'rot', 'query_type' => 'and' ) ), $property->getValue() );
	}

	/**
	 * Review Focus 4: a crawler sends no cookie and no Accept-Language. The language comes from the URL
	 * prefix alone, so the translated URL is served (no redirect) and an untranslated product still opens;
	 * a cookie or header for another language changes nothing.
	 *
	 * @return void
	 */
	public function test_prefixed_url_without_cookie_or_accept_language_is_not_redirected() {
		lmat_test_set( 'post_names', array( 5 => 'open-day', 6 => 'plain' ) );
		$routing = $this->routing();
		$routing->init();
		$visitors = array(
			'crawler' => array(),
			'browser' => array( 'cookie' => 'fr', 'accept' => 'fr-FR,fr;q=0.9' ),
		);

		foreach ( $visitors as $visitor => $sends ) {
			unset( $_COOKIE['lmat_language'], $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
			if ( ! empty( $sends ) ) {
				$_COOKIE['lmat_language']        = $sends['cookie'];
				$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $sends['accept'];
			}

			foreach ( array( 'de/produkt/open-day-de' => 'open-day', 'de/produkt/plain' => 'plain' ) as $path => $product ) {
				lmat_test_set( 'safe_redirect', null );
				lmat_test_set( 'status_header', null );
				$GLOBALS['wp']       = (object) array( 'request' => $path );
				$GLOBALS['wp_query'] = new Lmat_Test_Query();

				$this->assertSame( array( 'product' => $product ), apply_filters( 'request', array( 'product' => basename( $path ) ) ), "$visitor $path" );
				do_action( 'template_redirect' ); // Throws Lmat_Test_Redirect on any redirect.
				$this->assertNull( lmat_test_get( 'safe_redirect' ), "$visitor $path" );
				$this->assertNull( lmat_test_get( 'status_header' ), "$visitor $path" );
				$this->assertFalse( $GLOBALS['wp_query']->is_404, "$visitor $path" );
			}
		}
	}

	/**
	 * A slug that never belonged to anything is a real 404 (status, query flag, and no core guess that
	 * could send it home), and every retired URL 301s straight to the final one: the target itself does
	 * not redirect again.
	 *
	 * @return void
	 */
	public function test_unknown_slug_returns_a_real_404_status_and_a_redirected_url_never_chains() {
		Slug_Store::set_base( 'de', 'product', 'produkt-alt' );
		Slug_Store::set_base( 'de', 'product', 'produkt' );
		Slug_Store::set( 5, 'de', 'open-day-alt' );
		Slug_Store::set( 5, 'de', 'open-day-de' );
		Slug_Store::set( 10, 'de', 'eltern', 'term', 'product_cat' );
		$routing = $this->routing();
		$routing->init();
		$rules = $this->stored_rules( $routing );

		foreach ( array( 'de/produkt/unknown' => array( 'product' => 'unknown' ), 'de/kategorie/unknown' => array( 'product_cat' => 'unknown' ) ) as $path => $query_vars ) {
			$GLOBALS['wp']       = (object) array( 'request' => $path );
			$GLOBALS['wp_query'] = new Lmat_Test_Query();
			lmat_test_set( 'safe_redirect', null );

			$this->assertSame( array( 'error' => '404' ), apply_filters( 'request', $query_vars ), $path );
			do_action( 'template_redirect' );
			$this->assertSame( 404, lmat_test_get( 'status_header' ), $path );
			$this->assertTrue( $GLOBALS['wp_query']->is_404, $path );
			$this->assertNull( lmat_test_get( 'safe_redirect' ), $path );
			$this->assertFalse( apply_filters( 'redirect_canonical', home_url( '/' ), home_url( '/' . $path . '/' ) ), $path );
		}

		$retired = array( 'de/prodotto/open-day', 'de/produkt/open-day', 'de/prodotto/open-day-de', 'de/produkt-alt/open-day-alt', 'de/produkt/open-day-alt', 'de/produkt-alt/open-day-de' );

		foreach ( $retired as $path ) {
			$this->request( $routing, $path, $this->wp_match( $rules, $path ) );
			$target = $routing->redirect_url();
			$this->assertSame( 'https://example.test/de/produkt/open-day-de/', $target, $path );

			$hop = trim( (string) parse_url( $target, PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress in the harness.
			$this->assertSame( array( 'lmat_lang' => 'de', 'product' => 'open-day' ), $this->request( $routing, $hop, $this->wp_match( $rules, $hop ) ), $path );
			$this->assertSame( '', $routing->redirect_url(), "$path must reach its target in one hop." );
		}
	}

	/**
	 * Builds the router.
	 *
	 * @return Slug_Routing
	 */
	private function routing() {
		return new Slug_Routing( new Slug_Resolver( new Slug_Store() ), new Slug_Store() );
	}

	/**
	 * Simulates WordPress parsing a request path into query vars, then runs the router on them.
	 *
	 * @param Slug_Routing $routing    Router.
	 * @param string       $path       Request path, as in WP::$request.
	 * @param array        $query_vars Query vars the rewrite rules produced.
	 * @return array
	 */
	private function request( Slug_Routing $routing, $path, $query_vars ) {
		$GLOBALS['wp'] = (object) array( 'request' => $path );

		return $routing->map_request( $query_vars );
	}

	/**
	 * A translated slug under its translated base is served as the canonical product.
	 *
	 * @return void
	 */
	public function test_translated_product_slug_is_mapped_to_canonical_query_var() {
		$routing = $this->routing();

		$query_vars = $this->request( $routing, 'de/produkt/open-day-de', array( 'product' => 'open-day-de' ) );

		$this->assertSame( array( 'product' => 'open-day' ), $query_vars );
		$this->assertSame( '', $routing->redirect_url() );
	}

	/**
	 * Rule 1: the canonical slug under the prefix 301s straight to the translated URL, which then
	 * resolves without a further redirect.
	 *
	 * @return void
	 */
	public function test_canonical_slug_under_a_prefix_301s_to_the_translated_url_in_one_hop() {
		$routing = $this->routing();
		$this->request( $routing, 'de/prodotto/open-day', array( 'product' => 'open-day' ) );

		$this->assertSame( 'https://example.test/de/produkt/open-day-de/', $routing->redirect_url() );

		$second = $this->routing();
		$this->request( $second, 'de/produkt/open-day-de', array( 'product' => 'open-day-de' ) );

		$this->assertSame( '', $second->redirect_url() );
	}

	/**
	 * A slug that never belonged to anything is a real 404: no query to guess from, no redirect.
	 *
	 * @return void
	 */
	public function test_unknown_slug_sets_404_and_never_redirects_home() {
		$routing = $this->routing();

		$query_vars = $this->request( $routing, 'de/produkt/nothing-here', array( 'product' => 'nothing-here' ) );

		$this->assertSame( array( 'error' => '404' ), $query_vars );
		$this->assertSame( '', $routing->redirect_url() );
	}

	/**
	 * A superseded slug, and a superseded base, each 301 once to the current URL.
	 *
	 * @return void
	 */
	public function test_superseded_slug_produces_one_301_to_the_current_url() {
		Slug_Store::set( 5, 'de', 'open-day-alt' );
		Slug_Store::set( 5, 'de', 'open-day-de' );
		Slug_Store::set_base( 'de', 'product', 'produkt-alt' );
		Slug_Store::set_base( 'de', 'product', 'produkt' );

		$by_slug = $this->routing();
		$this->request( $by_slug, 'de/produkt/open-day-alt', array( 'product' => 'open-day-alt' ) );
		$this->assertSame( 'https://example.test/de/produkt/open-day-de/', $by_slug->redirect_url() );

		$by_base = $this->routing();
		$this->request( $by_base, 'de/produkt-alt/open-day-de', array( 'product' => 'open-day-de' ) );
		$this->assertSame( 'https://example.test/de/produkt/open-day-de/', $by_base->redirect_url() );

		$target = $this->routing();
		$this->request( $target, 'de/produkt/open-day-de', array( 'product' => 'open-day-de' ) );
		$this->assertSame( '', $target->redirect_url() );
	}

	/**
	 * Review Focus 2: case, trailing slash, percent-encoding and pagination do not change the outcome.
	 *
	 * @return void
	 */
	public function test_request_variants_resolve_like_the_plain_form() {
		$routing = $this->routing();

		// A mixed-case slug is matched by the translated rule's ([^/]+); a mixed-case base is covered by the catch-all test.
		$this->assertSame( array( 'product' => 'open-day' ), $this->request( $routing, 'de/produkt/Open-Day-DE', array( 'product' => 'Open-Day-DE' ) ) );
		$this->assertSame( array( 'product' => 'open-day' ), $this->request( $routing, 'de/produk%74/open%2Dday%2Dde', array( 'product' => 'open%2Dday%2Dde' ) ) );

		// The trailing slash is optional in the rule WordPress matches the path against.
		$rule = (string) key( $routing->rewrite_rules( array( 'prodotto/([^/]+)/?$' => 'index.php?product=$matches[1]' ) ) );
		$this->assertSame( 'de/produkt/([^/]+)/?$', $rule );
		$this->assertSame( 1, preg_match( "#^$rule#", 'de/produkt/open-day-de' ) );
		$this->assertSame( 1, preg_match( "#^$rule#", 'de/produkt/open-day-de/' ) );

		Slug_Store::set( 10, 'de', 'eltern', 'term', 'product_cat' );
		$this->assertSame(
			array(
				'product_cat' => 'parent-c',
				'paged'       => '2',
			),
			$this->request( $routing, 'de/kategorie/eltern/page/2', array( 'product_cat' => 'eltern', 'paged' => '2' ) )
		);
		$this->assertSame( '', $routing->redirect_url() );
	}

	/**
	 * Review Focus 5: a translated parent with an untranslated child.
	 *
	 * @return void
	 */
	public function test_translated_parent_with_untranslated_child_resolves() {
		Slug_Store::set( 10, 'de', 'eltern', 'term', 'product_cat' );
		$routing = $this->routing();

		$query_vars = $this->request( $routing, 'de/kategorie/eltern/child-c', array( 'product_cat' => 'eltern/child-c' ) );

		$this->assertSame( array( 'product_cat' => 'parent-c/child-c' ), $query_vars );
		$this->assertSame( '', $routing->redirect_url() );
	}

	/**
	 * Review Focus 5, reverse: an untranslated parent with a translated child.
	 *
	 * @return void
	 */
	public function test_untranslated_parent_with_translated_child_resolves() {
		Slug_Store::set( 11, 'de', 'kind', 'term', 'product_cat' );
		$routing = $this->routing();

		$query_vars = $this->request( $routing, 'de/kategorie/parent-c/kind', array( 'product_cat' => 'parent-c/kind' ) );

		$this->assertSame( array( 'product_cat' => 'parent-c/child-c' ), $query_vars );
		$this->assertSame( '', $routing->redirect_url() );
	}

	/**
	 * A canonical parent segment whose term has a German slug 301s to the term's full German URL.
	 *
	 * @return void
	 */
	public function test_canonical_parent_segment_301s_to_the_full_translated_term_url() {
		Slug_Store::set( 10, 'de', 'eltern', 'term', 'product_cat' );
		$routing = $this->routing();

		$this->request( $routing, 'de/kategorie/parent-c/child-c/page/2', array( 'product_cat' => 'parent-c/child-c', 'paged' => '2' ) );

		$this->assertSame( 'https://example.test/de/kategorie/eltern/child-c/page/2/', $routing->redirect_url() );
	}

	/**
	 * Rules exist for each non-default language with a translated (or superseded) base, and for no other.
	 *
	 * @return void
	 */
	public function test_rewrite_rules_exist_only_for_languages_with_a_translated_base() {
		Slug_Store::set_base( 'de', 'product', 'produkt-alt' );
		Slug_Store::set_base( 'de', 'product', 'produkt' );
		Slug_Store::set_base( 'it', 'product', 'prodotto-it' );
		$canonical = array(
			'prodotto/([^/]+)/?$'                    => 'index.php?lmat_lang=it&product=$matches[1]',
			'categoria-prodotto/(.+?)/page/?([0-9]{1,})/?$' => 'index.php?product_cat=$matches[1]&paged=$matches[2]',
			'tag-prodotto/([^/]+)/?$'                => 'index.php?product_tag=$matches[1]',
		);

		$rules = $this->routing()->rewrite_rules( $canonical );

		$this->assertSame( 'index.php?lmat_lang=de&product=$matches[1]', $rules['de/produkt/([^/]+)/?$'] );
		$this->assertSame( 'index.php?lmat_lang=de&product=$matches[1]', $rules['de/produkt-alt/([^/]+)/?$'] );
		$this->assertSame( 'index.php?lmat_lang=de&product_cat=$matches[1]&paged=$matches[2]', $rules['de/kategorie/(.+?)/page/?([0-9]{1,})/?$'] );
		$this->assertSame( array( 'de/produkt/([^/]+)/?$', 'de/produkt-alt/([^/]+)/?$', 'de/kategorie/(.+?)/page/?([0-9]{1,})/?$' ), array_slice( array_keys( $rules ), 0, 3 ) );
		$this->assertCount( count( $canonical ) + 3, $rules );
		$this->assertEmpty( preg_grep( '#^(it|fr|en)/#', array_keys( $rules ) ) );
	}

	/**
	 * Rules are flushed when the bases option changes, never while serving a request.
	 *
	 * @return void
	 */
	public function test_rules_are_flushed_only_when_the_bases_option_changes() {
		$routing = $this->routing();
		$routing->init();

		$this->request( $routing, 'de/produkt/open-day-de', array( 'product' => 'open-day-de' ) );
		$routing->rewrite_rules( array() );
		$this->assertSame( 0, lmat_test_get( 'flushes', 0 ) );

		do_action( 'update_option_' . Slug_Store::BASES_OPTION, array(), array( 'de' => array() ), Slug_Store::BASES_OPTION );
		do_action( 'add_option_' . Slug_Store::BASES_OPTION, Slug_Store::BASES_OPTION, array() );
		$this->assertSame( 2, lmat_test_get( 'flushes', 0 ) );
	}

	/**
	 * With shared mode off nothing is routed; with it on, the router is wired in.
	 *
	 * @return void
	 */
	public function test_nothing_changes_when_shared_mode_is_off() {
		$hooks = array( 'request', 'rewrite_rules_array', 'template_redirect', 'update_option_' . Slug_Store::BASES_OPTION );

		( new Linguator_WooCommerce() )->init();
		foreach ( $hooks as $hook ) {
			$this->assertFalse( has_filter( $hook ), $hook );
		}

		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );
		( new Linguator_WooCommerce() )->init();
		foreach ( $hooks as $hook ) {
			$this->assertTrue( has_filter( $hook ), $hook );
		}
	}
}
