<?php
/**
 * Outbound translated links and catalogue cache priming.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
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
}
