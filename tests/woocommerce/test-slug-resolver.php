<?php
/**
 * Covers the translated-slug rules owned by the slug resolver.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Slug_Resolver;
use Linguator\Integrations\woocommerce\Slug_Store;

class Slug_Resolver_Test extends TestCase {

	/**
	 * Resets the simulated request and describes product 5, canonical slug "open-day".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		lmat_test_set( 'post_names', array( 5 => 'open-day' ) );
		lmat_test_set( 'permalinks', array( 5 => 'https://example.test/prodotto/open-day/' ) );
	}

	/**
	 * Builds a resolver.
	 *
	 * @param string $canonical_under_prefix Outcome of rules 1 and 3.
	 * @return Slug_Resolver
	 */
	private function resolver( $canonical_under_prefix = Slug_Resolver::CANONICAL_UNDER_PREFIX ) {
		return new Slug_Resolver( new Slug_Store(), $canonical_under_prefix );
	}

	/**
	 * Rule 1: the canonical slug 301s to the translated URL once a translation exists.
	 *
	 * @return void
	 */
	public function test_rule_1_canonical_slug_301s_to_the_translated_url_when_translated_exists() {
		Slug_Store::set( 5, 'de', 'open-day-de' );

		$result = $this->resolver()->resolve( 'open-day', 'post', 'de' );

		$this->assertSame( 'redirect', $result['action'] );
		$this->assertSame( 5, $result['object_id'] );
		$this->assertSame( 'https://example.test/de/prodotto/open-day-de/', $result['url'] );
		$this->assertSame( $this->resolver()->permalink( 'post', 5, 'de' ), $result['url'] );
	}

	/**
	 * Only a slug that never belonged to any object is a real 404.
	 *
	 * @return void
	 */
	public function test_resolve_returns_not_found_for_a_slug_that_never_belonged_to_any_object() {
		$result = $this->resolver()->resolve( 'nothing-here', 'post', 'de' );

		$this->assertSame( 'not_found', $result['action'] );
		$this->assertSame( 0, $result['object_id'] );
	}

	/**
	 * Rule 2: without a translation the canonical slug keeps working.
	 *
	 * @return void
	 */
	public function test_rule_2_canonical_slug_passes_when_no_translation() {
		$result = $this->resolver()->resolve( 'open-day', 'post', 'de' );

		$this->assertSame( 'pass', $result['action'] );
		$this->assertSame( 5, $result['object_id'] );
		$this->assertSame( 'open-day', $result['slug'] );
	}

	/**
	 * A translated slug maps to the canonical one, whatever its case or encoding.
	 *
	 * @return void
	 */
	public function test_translated_slug_maps_to_canonical() {
		Slug_Store::set( 5, 'de', 'open-day-de' );

		foreach ( array( 'open-day-de', 'OPEN-DAY-DE', 'open%2Dday-de' ) as $slug ) {
			$result = $this->resolver()->resolve( $slug, 'post', 'de' );

			$this->assertSame( 'map', $result['action'], $slug );
			$this->assertSame( 'open-day', $result['slug'], $slug );
			$this->assertSame( 5, $result['object_id'], $slug );
		}

		$this->assertSame( 'not_found', $this->resolver()->resolve( 'open-day-de', 'post', 'fr' )['action'] );
	}

	/**
	 * A slug that was replaced redirects to the current URL.
	 *
	 * @return void
	 */
	public function test_superseded_slug_redirects_to_current_url() {
		Slug_Store::set( 5, 'de', 'open-day-de' );
		Slug_Store::set( 5, 'de', 'open-day-neu' );

		$result = $this->resolver()->resolve( 'open-day-de', 'post', 'de' );

		$this->assertSame( 'redirect', $result['action'] );
		$this->assertSame( 'https://example.test/de/prodotto/open-day-neu/', $result['url'] );
		$this->assertSame( $this->resolver()->permalink( 'post', 5, 'de' ), $result['url'] );
	}

	/**
	 * A retired slug that another object now uses maps to that object, not to the old owner.
	 *
	 * @return void
	 */
	public function test_current_slug_wins_over_history() {
		lmat_test_set( 'post_names', array( 5 => 'open-day', 6 => 'other' ) );
		Slug_Store::set( 5, 'de', 'shared-name' );
		Slug_Store::set( 5, 'de', 'open-day-neu' );
		Slug_Store::set( 6, 'de', 'shared-name' );

		$result = $this->resolver()->resolve( 'shared-name', 'post', 'de' );

		$this->assertSame( 'map', $result['action'] );
		$this->assertSame( 6, $result['object_id'] );
	}

	/**
	 * Rule 3: each base type follows the same rule on its own.
	 *
	 * @return void
	 */
	public function test_rule_3_base_follows_the_same_rule_per_base_type() {
		Slug_Store::set_base( 'de', 'product', 'produkt' );
		$resolver = $this->resolver();

		$redirect = $resolver->resolve_base( 'prodotto', 'product', 'de' );
		$this->assertSame( 'redirect', $redirect['action'] );
		$this->assertSame( 'produkt', $redirect['url'] );

		$this->assertSame( 'pass', $resolver->resolve_base( 'categoria-prodotto', 'category', 'de' )['action'] );

		$map = $resolver->resolve_base( 'produkt', 'product', 'de' );
		$this->assertSame( 'map', $map['action'] );
		$this->assertSame( 'prodotto', $map['slug'] );

		Slug_Store::set_base( 'de', 'product', 'artikel' );
		$old = $resolver->resolve_base( 'produkt', 'product', 'de' );
		$this->assertSame( 'redirect', $old['action'] );
		$this->assertSame( 'artikel', $old['url'] );

		$this->assertSame( 'not_found', $resolver->resolve_base( 'unknown', 'product', 'de' )['action'] );
		$this->assertSame( 'not_found', $this->resolver( 'not_found' )->resolve_base( 'prodotto', 'product', 'de' )['action'] );
	}

	/**
	 * Rule 4: the default language is always canonical.
	 *
	 * @return void
	 */
	public function test_rule_4_default_language_is_always_canonical() {
		Slug_Store::set( 5, 'it', 'open-day-it' );
		Slug_Store::set_base( 'it', 'product', 'prodotto-it' );

		$this->assertSame( 'https://example.test/prodotto/open-day/', $this->resolver()->permalink( 'post', 5, 'it' ) );
		$this->assertSame( 'pass', $this->resolver()->resolve( 'open-day', 'post', 'it' )['action'] );
		$this->assertSame( 'pass', $this->resolver()->resolve_base( 'prodotto', 'product', 'it' )['action'] );
	}

	/**
	 * The redirect-versus-404 switch for rule 1.
	 *
	 * @return void
	 */
	public function test_canonical_under_prefix_switch() {
		Slug_Store::set( 5, 'de', 'open-day-de' );

		$result = $this->resolver( 'not_found' )->resolve( 'open-day', 'post', 'de' );

		$this->assertSame( 'not_found', $result['action'] );
		$this->assertSame( '', $result['url'] );
	}

	/**
	 * A translated base alone, with the product slug untouched.
	 *
	 * @return void
	 */
	public function test_permalink_uses_the_translated_base_and_the_canonical_slug() {
		Slug_Store::set_base( 'de', 'product', 'produkt' );

		$this->assertSame( 'https://example.test/de/produkt/open-day/', $this->resolver()->permalink( 'post', 5, 'de' ) );
		$this->assertSame( 'https://example.test/fr/prodotto/open-day/', $this->resolver()->permalink( 'post', 5, 'fr' ) );
	}

	/**
	 * Review Focus 5: each path segment of a term is translated independently.
	 *
	 * @return void
	 */
	public function test_permalink_for_a_term_with_translated_parent_and_untranslated_child() {
		lmat_test_set(
			'term_slugs',
			array(
				10 => array( 'slug' => 'parent-c', 'taxonomy' => 'product_cat', 'parent' => 0 ),
				11 => array( 'slug' => 'child-c', 'taxonomy' => 'product_cat', 'parent' => 10 ),
			)
		);
		lmat_test_set( 'term_links', array( 11 => 'https://example.test/categoria-prodotto/parent-c/child-c/' ) );
		Slug_Store::set_base( 'de', 'category', 'kategorie' );
		$resolver = $this->resolver();

		Slug_Store::set( 10, 'de', 'eltern', 'term', 'product_cat' );
		$this->assertSame( 'https://example.test/de/kategorie/eltern/child-c/', $resolver->permalink( 'term', 11, 'de', 'product_cat' ) );

		Slug_Store::set( 11, 'de', 'kind', 'term', 'product_cat' );
		$this->assertSame( 'https://example.test/de/kategorie/eltern/kind/', $resolver->permalink( 'term', 11, 'de', 'product_cat' ) );
	}

	/**
	 * Review Focus 5, reverse: untranslated parent, translated child.
	 *
	 * @return void
	 */
	public function test_permalink_for_a_term_with_untranslated_parent_and_translated_child() {
		lmat_test_set(
			'term_slugs',
			array(
				10 => array( 'slug' => 'parent-c', 'taxonomy' => 'product_cat', 'parent' => 0 ),
				11 => array( 'slug' => 'child-c', 'taxonomy' => 'product_cat', 'parent' => 10 ),
			)
		);
		lmat_test_set( 'term_links', array( 11 => 'https://example.test/categoria-prodotto/parent-c/child-c/' ) );
		Slug_Store::set_base( 'de', 'category', 'kategorie' );
		Slug_Store::set( 11, 'de', 'kind', 'term', 'product_cat' );

		$this->assertSame( 'https://example.test/de/kategorie/parent-c/kind/', $this->resolver()->permalink( 'term', 11, 'de', 'product_cat' ) );
	}

	/**
	 * Same-slug terms under different parents are told apart by the parent argument.
	 *
	 * @return void
	 */
	public function test_term_resolution_tells_same_slug_under_different_parents_apart() {
		lmat_test_set(
			'term_slugs',
			array(
				10 => array( 'slug' => 'a', 'taxonomy' => 'product_cat', 'parent' => 1 ),
				11 => array( 'slug' => 'b', 'taxonomy' => 'product_cat', 'parent' => 2 ),
			)
		);
		Slug_Store::set( 10, 'de', 'same', 'term', 'product_cat' );
		Slug_Store::set( 11, 'de', 'same', 'term', 'product_cat' );

		$under_1 = $this->resolver()->resolve( 'same', 'term', 'de', 'product_cat', 1 );
		$under_2 = $this->resolver()->resolve( 'same', 'term', 'de', 'product_cat', 2 );

		$this->assertSame( array( 'map', 10, 'a' ), array( $under_1['action'], $under_1['object_id'], $under_1['slug'] ) );
		$this->assertSame( array( 'map', 11, 'b' ), array( $under_2['action'], $under_2['object_id'], $under_2['slug'] ) );
	}
}
