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
		unset( $GLOBALS['wp'] );
		parent::tearDown();
	}

	/**
	 * Exercises rewrite matching as WordPress does, including a non-matching mixed-case base.
	 *
	 * @return void
	 */
	public function test_unmatched_variants_are_recovered_and_product_name_is_mapped() {
		update_option( 'rewrite_rules', array( 'prodotto/([^/]+)/?$' => 'index.php?product=$matches[1]' ) );
		$routing = $this->routing();
		$this->assertSame( array( 'lmat_lang' => 'de', 'product' => 'open-day' ), $this->request( $routing, 'de/Produkt/open%2Dday%2Dde', array( 'error' => '404' ) ) );
		$this->assertSame( array( 'product' => 'open-day', 'name' => 'open-day', 'post_type' => 'product' ), $this->request( $routing, 'de/produkt/open-day-de', array( 'product' => 'open-day-de', 'name' => 'open-day-de', 'post_type' => 'product' ) ) );
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

		$this->assertSame( array( 'product' => 'open-day' ), $this->request( $routing, 'de/Produkt/Open-Day-DE', array( 'product' => 'Open-Day-DE' ) ) );
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
