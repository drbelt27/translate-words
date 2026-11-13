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

	public function test_repeated_permalink_calls_do_not_recurse_through_link_filters() {
		lmat_test_set( 'curlang', 'de' );
		Slug_Store::set( 5, 'de', 'open-day-de' );
		$this->links()->init();

		$this->assertSame( 'https://example.test/de/prodotto/open-day-de/', get_permalink( 5 ) );
		$this->assertSame( 'https://example.test/de/prodotto/open-day-de/', get_permalink( 5 ) );
	}

	public function test_variation_and_unrelated_post_and_term_links_are_untouched() {
		lmat_test_set( 'curlang', 'de' );
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
