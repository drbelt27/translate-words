<?php
/**
 * Outbound translated links and catalogue cache priming.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Linguator_WooCommerce;
use Linguator\Integrations\woocommerce\Slug_Links;
use Linguator\Integrations\woocommerce\Slug_Resolver;
use Linguator\Integrations\woocommerce\Slug_Store;

class Slug_Links_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );
		lmat_test_set( 'post_types', array( 5 => 'product', 6 => 'product', 7 => 'product', 8 => 'product_variation' ) );
		lmat_test_set( 'post_names', array( 5 => 'open-day', 6 => 'other', 7 => 'third' ) );
		lmat_test_set( 'permalinks', array( 5 => 'https://example.test/prodotto/open-day/' ) );
	}

	private function links() {
		return new Slug_Links( new Slug_Resolver( new Slug_Store() ) );
	}

	public function test_seopress_sitemap_adds_each_product_language_once() {
		lmat_test_set( 'curlang', 'it' );
		Slug_Store::set( 5, 'de', 'offener-tag' );
		$links = $this->links();
		$links->init();
		$post = new Lmat_Test_Post( 5 );
		$entry = apply_filters( 'seopress_sitemaps_single_url', array( 'loc' => 'https://example.test/prodotto/open-day/', 'mod' => '2026-11-14' ), $post );
		$xml = apply_filters( 'seopress_sitemaps_url', '<url><loc>https://example.test/prodotto/open-day/</loc><lastmod>2026-11-14</lastmod></url>', $entry );

		$this->assertSame( 1, substr_count( $xml, '<loc>https://example.test/prodotto/open-day/</loc>' ) );
		$this->assertSame( 1, substr_count( $xml, '<loc>https://example.test/de/prodotto/offener-tag/</loc>' ) );
		$this->assertSame( 1, substr_count( $xml, '<loc>https://example.test/fr/prodotto/open-day/</loc>' ) );
		$this->assertSame( 1, substr_count( $xml, '<loc>https://example.test/en/prodotto/open-day/</loc>' ) );
	}

	public function test_seopress_sitemap_adds_each_shared_term_language_once() {
		lmat_test_set( 'term_slugs', array( 9 => array( 'slug' => 'abiti', 'taxonomy' => 'product_cat', 'parent' => 0 ) ) );
		lmat_test_set( 'term_links', array( 9 => 'https://example.test/categoria-prodotto/abiti/' ) );
		Slug_Store::set( 9, 'de', 'kleider', 'term', 'product_cat' );
		$links = $this->links();
		$links->init();
		$term = new WP_Term( 9, 'product_cat', 'abiti' );
		$entry = apply_filters( 'seopress_sitemaps_term_single_url', array( 'loc' => 'https://example.test/categoria-prodotto/abiti/' ), $term );
		$xml = apply_filters( 'seopress_sitemaps_url', '<url><loc>https://example.test/categoria-prodotto/abiti/</loc></url>', $entry );

		$this->assertSame( 1, substr_count( $xml, '<loc>https://example.test/categoria-prodotto/abiti/</loc>' ) );
		$this->assertSame( 1, substr_count( $xml, '<loc>https://example.test/de/categoria-prodotto/kleider/</loc>' ) );
		$this->assertSame( 1, substr_count( $xml, '<loc>https://example.test/fr/categoria-prodotto/abiti/</loc>' ) );
	}

	public function test_seopress_excluded_entries_stay_excluded() {
		$links = $this->links();
		$links->init();
		$this->assertNull( apply_filters( 'seopress_sitemaps_single_url', null, new Lmat_Test_Post( 5 ) ) );
		$this->assertNull( apply_filters( 'seopress_sitemaps_term_single_url', null, new WP_Term( 9, 'product_cat', 'abiti' ) ) );
	}

	public function test_seopress_sitemap_is_unchanged_when_shared_mode_is_off() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => false ) );
		$links = $this->links();
		$links->init();
		$entry = apply_filters( 'seopress_sitemaps_single_url', array( 'loc' => 'https://example.test/prodotto/open-day/' ), new Lmat_Test_Post( 5 ) );
		$xml = '<url><loc>https://example.test/prodotto/open-day/</loc></url>';

		$this->assertSame( $xml, apply_filters( 'seopress_sitemaps_url', $xml, $entry ) );
	}

	public function test_product_permalink_uses_the_translated_slug_in_the_current_language() {
		lmat_test_set( 'curlang', 'de' );
		Slug_Store::set( 5, 'de', 'open-day-de' );
		Slug_Store::set_base( 'de', 'product', 'produkt' );

		$this->assertSame( 'https://example.test/de/produkt/open-day-de/', $this->links()->post_link( 'https://example.test/prodotto/open-day/', new Lmat_Test_Post( 5 ) ) );
	}

	public function test_untranslated_object_keeps_the_canonical_slug_in_every_language() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'https://example.test/de/prodotto/open-day/', $this->links()->post_link( 'https://example.test/prodotto/open-day/', new Lmat_Test_Post( 5 ) ) );
		lmat_test_set( 'curlang', 'it' );
		$this->assertSame( 'https://example.test/prodotto/open-day/', $this->links()->post_link( 'https://example.test/prodotto/open-day/', new Lmat_Test_Post( 5 ) ) );
	}

	public function test_pa_term_link_uses_translated_attribute_slug() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'term_slugs', array( 14 => array( 'slug' => 'blue', 'taxonomy' => 'pa_color', 'parent' => 0 ) ) );
		Slug_Store::set( 14, 'de', 'blau', 'term', 'pa_color' );
		$this->assertSame( 'https://example.test/de/color/blau/', $this->links()->term_link( 'https://example.test/color/blue/', (object) array( 'term_id' => 14, 'slug' => 'blue' ), 'pa_color' ) );
	}

	public function test_get_permalink_with_the_link_filters_live_is_stable_across_repeated_calls() {
		lmat_test_set( 'curlang', 'de' );
		Slug_Store::set( 5, 'de', 'open-day-de' );
		$this->links()->init();

		$this->assertSame( 'https://example.test/de/prodotto/open-day-de/', get_permalink( 5 ) );
		$this->assertSame( 'https://example.test/de/prodotto/open-day-de/', get_permalink( 5 ) );
	}

	public function test_resolver_permalink_for_another_language_is_not_double_translated_by_the_live_filters() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'term_slugs', array( 14 => array( 'slug' => 'blue', 'taxonomy' => 'pa_color', 'parent' => 0 ) ) );
		lmat_test_set( 'term_links', array( 14 => 'https://example.test/color/blue/' ) );
		Slug_Store::set( 5, 'de', 'open-day-de' );
		Slug_Store::set( 5, 'fr', 'jour-ouvert' );
		Slug_Store::set_base( 'fr', 'product', 'produit' );
		Slug_Store::set( 14, 'fr', 'bleu', 'term', 'pa_color' );
		$this->links()->init();
		$resolver = new Slug_Resolver( new Slug_Store() );

		$this->assertSame( 'https://example.test/fr/produit/jour-ouvert/', $resolver->permalink( 'post', 5, 'fr' ) );
		$this->assertSame( 'https://example.test/fr/color/bleu/', $resolver->permalink( 'term', 14, 'fr', 'pa_color' ) );
	}

	public function test_nested_permalink_reads_keep_the_canonical_flag_until_the_outer_read_ends() {
		lmat_test_set( 'permalinks', array( 5 => 'https://example.test/prodotto/open-day/', 6 => 'https://example.test/prodotto/other/' ) );
		$resolver = new Slug_Resolver( new Slug_Store() );
		$seen     = array();
		add_filter(
			'post_type_link',
			function ( $url, $post ) use ( $resolver, &$seen ) {
				if ( 5 === $post->ID ) {
					$resolver->permalink( 'post', 6, 'de' );
					$seen[] = Slug_Resolver::reading_canonical();
				}
				return $url;
			},
			5,
			2
		);

		$resolver->permalink( 'post', 5, 'de' );

		$this->assertSame( array( true ), $seen );
		$this->assertFalse( Slug_Resolver::reading_canonical() );
	}

	public function test_variation_and_unrelated_post_and_term_links_are_untouched() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'post_types', array( 8 => 'product_variation', 9 => 'page' ) );
		$this->assertSame( 'url', $this->links()->post_link( 'url', new Lmat_Test_Post( 8 ) ) );
		$this->assertSame( 'url', $this->links()->post_link( 'url', new Lmat_Test_Post( 9 ) ) );
		$this->assertSame( 'url', $this->links()->term_link( 'url', (object) array( 'term_id' => 14 ), 'category' ) );
	}

	public function test_building_links_for_a_catalogue_page_issues_one_slug_query_not_one_per_product() {
		lmat_test_set( 'curlang', 'de' );
		$links = $this->links();
		$posts = array( new Lmat_Test_Post( 5 ), new Lmat_Test_Post( 6 ), new Lmat_Test_Post( 7 ) );
		$this->assertSame( $posts, $links->prime_loop( $posts ) );
		$this->assertSame( array( 5, 6, 7 ), lmat_test_get( 'primed_posts' ) );
		$this->assertSame( 1, lmat_test_get( 'post_prime_calls' ) );
	}

	private function shared_product_in_three_languages() {
		Slug_Store::set( 5, 'de', 'open-day-de' );
		Slug_Store::set( 5, 'fr', 'jour-ouvert' );
		Slug_Store::set_base( 'de', 'product', 'produkt' );
		Slug_Store::set_base( 'fr', 'product', 'produit' );
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 5 );
	}

	public function test_hreflang_alternates_are_translated_urls_and_reciprocal() {
		$this->shared_product_in_three_languages();
		$this->links()->init();
		$integration = new Linguator_WooCommerce();
		$pages       = array();

		foreach ( array( 'it', 'de', 'fr', 'en' ) as $current ) {
			lmat_test_set( 'curlang', $current );
			$alternates = array();

			foreach ( array( 'it', 'de', 'fr', 'en' ) as $target ) {
				$alternates[ $target ] = $integration->product_translation_url( '', new Lmat_Test_Language( $target ) );
			}

			$pages[ $current ] = $this->links()->hreflang( $alternates );
		}

		$this->assertSame( $pages['it'], $pages['de'] ); // Every page of the set lists the same URLs, so each pair is reciprocal.
		$this->assertSame( $pages['it'], $pages['fr'] );
		$this->assertSame( $pages['it'], $pages['en'] );
		$this->assertSame( 'https://example.test/de/produkt/open-day-de/', $pages['de']['de'] );
		$this->assertSame( 'https://example.test/fr/produit/jour-ouvert/', $pages['de']['fr'] );
		$this->assertSame( 'https://example.test/en/prodotto/open-day/', $pages['de']['en'] );
		$this->assertSame( 'https://example.test/prodotto/open-day/', $pages['de']['x-default'] );
	}

	public function test_public_attribute_archive_gets_canonical_x_default_and_reciprocal_hreflang() {
		lmat_test_set( 'term_slugs', array( 14 => array( 'slug' => 'blue', 'taxonomy' => 'pa_color', 'parent' => 0 ) ) );
		lmat_test_set( 'term_links', array( 14 => 'https://example.test/color/blue/' ) );
		lmat_test_set( 'is_product_tax', 'pa_color' );
		lmat_test_set( 'queried_term', new WP_Term( 14, 'pa_color' ) );
		Slug_Store::set( 14, 'de', 'blau', 'term', 'pa_color' );
		Slug_Store::set( 14, 'fr', 'bleu', 'term', 'pa_color' );
		$this->links()->init();
		$integration = new Linguator_WooCommerce();
		$pages       = array();

		foreach ( array( 'it', 'de', 'fr' ) as $current ) {
			lmat_test_set( 'curlang', $current );
			$alternates = array();

			foreach ( array( 'it', 'de', 'fr' ) as $target ) {
				$alternates[ $target ] = $integration->term_archive_translation_url( '', new Lmat_Test_Language( $target ) );
			}

			$pages[ $current ] = $this->links()->hreflang( $alternates );
		}

		$this->assertSame( $pages['it'], $pages['de'] );
		$this->assertSame( $pages['it'], $pages['fr'] );
		$this->assertSame(
			array(
				'it'        => 'https://example.test/color/blue/',
				'de'        => 'https://example.test/de/color/blau/',
				'fr'        => 'https://example.test/fr/color/bleu/',
				'x-default' => 'https://example.test/color/blue/',
			),
			$pages['de']
		);

		lmat_test_set( 'curlang', 'de' );
		$this->assertSame( 'https://example.test/de/color/blau/', $this->links()->canonical_url( 'https://example.test/de/color/blue/' ) );

		// Any other taxonomy archive is left to Linguator and the SEO plugin.
		lmat_test_set( 'term_links', array( 15 => 'https://example.test/event/fair/' ) );
		lmat_test_set( 'is_product_tax', 'event_type' );
		lmat_test_set( 'queried_term', new WP_Term( 15, 'event_type' ) );
		$this->assertSame( array( 'de' => 'https://example.test/de/event/fair/' ), $this->links()->hreflang( array( 'de' => 'https://example.test/de/event/fair/' ) ) );
		$this->assertSame( '', $integration->term_archive_translation_url( '', new Lmat_Test_Language( 'de' ) ) );
	}

	public function test_canonical_is_self_referencing_on_the_translated_url() {
		$this->shared_product_in_three_languages();
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'https://example.test/de/produkt/open-day-de/', $this->links()->canonical_url( 'https://example.test/de/prodotto/open-day/' ) );
		lmat_test_set( 'curlang', 'it' );
		$this->assertSame( 'https://example.test/prodotto/open-day/', $this->links()->canonical_url( 'https://example.test/prodotto/open-day/' ) );
	}

	public function test_canonical_never_leaks_a_translated_slug_of_an_unpublished_product_or_a_paged_archive() {
		$this->shared_product_in_three_languages();
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'post_statuses', array( 5 => 'draft' ) );

		$this->assertSame( 'https://example.test/de/prodotto/open-day/', $this->links()->canonical_url( 'https://example.test/de/prodotto/open-day/' ) );

		lmat_test_set( 'is_product', false );
		lmat_test_set( 'is_product_tax', 'product_cat' );
		lmat_test_set( 'queried_term', new WP_Term( 9, 'product_cat' ) );
		lmat_test_set( 'is_paged', true );
		lmat_test_set( 'term_slugs', array( 9 => array( 'slug' => 'abiti', 'taxonomy' => 'product_cat', 'parent' => 0 ) ) );
		lmat_test_set( 'term_links', array( 9 => 'https://example.test/categoria/abiti/' ) );
		Slug_Store::set( 9, 'de', 'kleider', 'term', 'product_cat' );

		// Page 2 keeps the URL SEOPress computed; it must not collapse to the translated page-1 archive URL.
		$this->assertSame( 'https://example.test/de/categoria/abiti/page/2/', $this->links()->canonical_url( 'https://example.test/de/categoria/abiti/page/2/' ) );

		lmat_test_set( 'is_paged', false );
		$this->assertSame( 'https://example.test/de/categoria/kleider/', $this->links()->canonical_url( 'https://example.test/de/categoria/abiti/' ) );
	}

	public function test_sitemap_lists_one_translated_url_per_language_never_both_forms() {
		$this->shared_product_in_three_languages();
		$links = $this->links();
		$locs  = array();

		foreach ( array( 'it', 'de', 'fr' ) as $language ) {
			lmat_test_set( 'curlang', $language );
			$entry         = $links->sitemap_entry( array( 'loc' => 'https://example.test/prodotto/open-day/' ), new Lmat_Test_Post( 5 ) );
			$locs[ $language ] = $entry['loc'];
		}

		$this->assertSame(
			array(
				'it' => 'https://example.test/prodotto/open-day/',
				'de' => 'https://example.test/de/produkt/open-day-de/',
				'fr' => 'https://example.test/fr/produit/jour-ouvert/',
			),
			$locs
		);
		$this->assertTrue( $links->sitemap_per_language( false, 'posts', 'product' ) );
		$this->assertTrue( $links->sitemap_per_language( false, 'taxonomies', 'product_cat' ) );
		$this->assertFalse( $links->sitemap_per_language( false, 'posts', 'page' ) );
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'term_slugs', array( 9 => array( 'slug' => 'abiti', 'taxonomy' => 'product_cat', 'parent' => 0 ) ) );
		lmat_test_set( 'term_links', array( 9 => 'https://example.test/categoria/abiti/' ) );
		Slug_Store::set( 9, 'de', 'kleider', 'term', 'product_cat' );
		$this->assertSame( 'https://example.test/de/categoria/kleider/', $links->sitemap_url( 'https://example.test/categoria/abiti/', new WP_Term( 9, 'product_cat' ) ) );
	}

	public function test_json_ld_product_url_is_translated() {
		$this->shared_product_in_three_languages();
		lmat_test_set( 'curlang', 'de' );
		$markup = array(
			'@type' => 'Product',
			'@id'   => 'https://example.test/prodotto/open-day/#product',
			'url'   => 'https://example.test/prodotto/open-day/',
		);

		$markup = $this->links()->schema_url( $markup, new Lmat_Test_Product( 5 ) );

		$this->assertSame( 'https://example.test/de/produkt/open-day-de/', $markup['url'] );
		$this->assertSame( 'https://example.test/de/produkt/open-day-de/#product', $markup['@id'] );
	}
}
