<?php
/**
 * Covers language switching on shared products and product term archives.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Linguator_WooCommerce;
use Linguator\Integrations\woocommerce\Slug_Links;
use Linguator\Integrations\woocommerce\Slug_Resolver;
use Linguator\Integrations\woocommerce\Slug_Store;

class Object_Translation_Url_Test extends TestCase {

	/**
	 * The integration under test.
	 *
	 * @var Linguator_WooCommerce
	 */
	protected $integration;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );
		$this->integration = new Linguator_WooCommerce();
	}

	/**
	 * Switching language on a shared product stays on that product.
	 *
	 * @return void
	 */
	public function test_product_keeps_the_visitor_on_the_product() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		lmat_test_set( 'permalinks', array( 42 => 'https://example.test/prodotto/tassa-di-socio/' ) );

		$url = $this->integration->product_translation_url( '', new Lmat_Test_Language( 'de' ) );

		$this->assertSame( 'https://example.test/de/prodotto/tassa-di-socio/', $url );
	}

	/**
	 * Switching from an already translated URL resolves the target slug and base, not the source ones.
	 *
	 * @return void
	 */
	public function test_switcher_url_for_a_product_targets_the_translated_slug_of_the_other_language() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'post_names', array( 42 => 'open-day' ) );
		lmat_test_set( 'post_types', array( 42 => 'product' ) );
		lmat_test_set( 'permalinks', array( 42 => 'https://example.test/prodotto/open-day/' ) );
		Slug_Store::set( 42, 'de', 'open-day-de' );
		Slug_Store::set( 42, 'fr', 'jour-ouvert' );
		Slug_Store::set_base( 'de', 'product', 'produkt' );
		Slug_Store::set_base( 'fr', 'product', 'produit' );
		( new Slug_Links( new Slug_Resolver( new Slug_Store() ) ) )->init(); // The outbound filters are live, as in production.

		$this->assertSame( 'https://example.test/fr/produit/jour-ouvert/', $this->integration->product_translation_url( '', new Lmat_Test_Language( 'fr' ) ) );
	}

	/**
	 * The default language gets the unprefixed URL, because its prefix is hidden.
	 *
	 * @return void
	 */
	public function test_default_language_gets_the_canonical_url() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		lmat_test_set( 'permalinks', array( 42 => 'https://example.test/prodotto/tassa-di-socio/' ) );

		$url = $this->integration->product_translation_url( '', new Lmat_Test_Language( 'it' ) );

		$this->assertSame( 'https://example.test/prodotto/tassa-di-socio/', $url );
	}

	/**
	 * Every language resolves, which is what makes the alternates loop print hreflang at all:
	 * it prints nothing unless more than one URL comes back.
	 *
	 * @return void
	 */
	public function test_every_language_resolves_so_hreflang_is_emitted() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		lmat_test_set( 'permalinks', array( 42 => 'https://example.test/prodotto/tassa-di-socio/' ) );

		$urls = array();

		foreach ( array( 'it', 'de', 'fr', 'en' ) as $slug ) {
			$urls[ $slug ] = $this->integration->product_translation_url( '', new Lmat_Test_Language( $slug ) );
		}

		$this->assertCount( 4, array_filter( $urls ) );
		$this->assertCount( 4, array_unique( $urls ) );
	}

	/**
	 * Switching language on a product category archive stays on that archive.
	 *
	 * @return void
	 */
	public function test_product_category_archive_stays_on_the_archive() {
		lmat_test_set( 'is_product_tax', 'product_cat' );
		lmat_test_set( 'queried_term', new WP_Term( 9, 'product_cat', 'Abbigliamento' ) );
		lmat_test_set( 'term_links', array( 9 => 'https://example.test/categoria-prodotto/abbigliamento/' ) );

		$url = $this->integration->term_archive_translation_url( '', new Lmat_Test_Language( 'de' ) );

		$this->assertSame( 'https://example.test/de/categoria-prodotto/abbigliamento/', $url );
	}

	/**
	 * The archive switcher uses the target language's slug and base, including from a translated URL.
	 *
	 * @return void
	 */
	public function test_term_archive_switcher_url_uses_the_translated_term_slug_and_base() {
		lmat_test_set( 'is_product_tax', 'product_cat' );
		lmat_test_set( 'queried_term', new WP_Term( 9, 'product_cat', 'Abbigliamento' ) );
		lmat_test_set( 'term_slugs', array( 9 => array( 'slug' => 'abbigliamento', 'taxonomy' => 'product_cat', 'parent' => 0 ) ) );
		lmat_test_set( 'term_links', array( 9 => 'https://example.test/categoria-prodotto/abbigliamento/' ) );
		Slug_Store::set( 9, 'de', 'bekleidung', 'term', 'product_cat' );
		Slug_Store::set( 9, 'fr', 'vetements', 'term', 'product_cat' );
		Slug_Store::set_base( 'de', 'category', 'kategorie' );
		Slug_Store::set_base( 'fr', 'category', 'categorie' );
		lmat_test_set( 'curlang', 'de' );
		( new Slug_Links( new Slug_Resolver( new Slug_Store() ) ) )->init(); // The outbound filters are live, as in production.

		$this->assertSame( 'https://example.test/fr/categorie/vetements/', $this->integration->term_archive_translation_url( '', new Lmat_Test_Language( 'fr' ) ) );
	}

	/**
	 * With shared mode off, both handlers stand aside and Linguator resolves as it does today.
	 *
	 * @return void
	 */
	public function test_shared_mode_off_changes_nothing() {
		lmat_test_set( 'options', array() );
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		lmat_test_set( 'permalinks', array( 42 => 'https://example.test/prodotto/tassa-di-socio/' ) );

		$this->assertSame( '', $this->integration->product_translation_url( '', new Lmat_Test_Language( 'de' ) ) );
	}

	/**
	 * A URL another handler already resolved is never overwritten.
	 *
	 * @return void
	 */
	public function test_an_already_resolved_url_wins() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );

		$this->assertSame(
			'https://example.test/de/other/',
			$this->integration->product_translation_url( 'https://example.test/de/other/', new Lmat_Test_Language( 'de' ) )
		);
	}

	/**
	 * A request that is neither a product nor a product term archive is left alone.
	 *
	 * @return void
	 */
	public function test_other_requests_are_left_alone() {
		$this->assertSame( '', $this->integration->product_translation_url( '', new Lmat_Test_Language( 'de' ) ) );
		$this->assertSame( '', $this->integration->term_archive_translation_url( '', new Lmat_Test_Language( 'de' ) ) );
	}
}
