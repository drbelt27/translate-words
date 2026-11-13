<?php
/**
 * Covers per-language translated slug and permalink base storage.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Slug_Store;

class Slug_Store_Test extends TestCase {

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
	}

	/**
	 * A stored slug is read back.
	 *
	 * @return void
	 */
	public function test_set_then_get_round_trips() {
		$this->assertTrue( Slug_Store::set( 5, 'de', 'open-day-de' ) );
		$this->assertSame( 'open-day-de', Slug_Store::get( 5, 'de' ) );
		$this->assertSame( '', Slug_Store::get( 5, 'fr' ) );
	}

	/**
	 * Input is sanitized and cut to the maximum length.
	 *
	 * @return void
	 */
	public function test_set_sanitizes_and_bounds() {
		Slug_Store::set( 5, 'de', 'Open Day!!' );
		$this->assertSame( 'open-day', Slug_Store::get( 5, 'de' ) );

		Slug_Store::set( 6, 'de', str_repeat( 'a', 300 ) );
		$this->assertSame( Slug_Store::MAX_LENGTH, strlen( Slug_Store::get( 6, 'de' ) ) );
	}

	/**
	 * Empty, reserved and numeric slugs are refused and nothing is stored.
	 *
	 * @return void
	 */
	public function test_rejects_empty_after_sanitize_and_reserved() {
		foreach ( array( '!!!', 'feed', 'page', '2' ) as $slug ) {
			$this->assertInstanceOf( WP_Error::class, Slug_Store::set( 5, 'de', $slug ), $slug );
		}

		$this->assertSame( '', Slug_Store::get( 5, 'de' ) );
	}

	/**
	 * Another object's translated slug is taken, but only within the same language.
	 *
	 * @return void
	 */
	public function test_conflict_with_other_translated_slug_is_rejected() {
		Slug_Store::set( 5, 'de', 'x' );

		$this->assertInstanceOf( WP_Error::class, Slug_Store::set( 6, 'de', 'x' ) );
		$this->assertTrue( Slug_Store::set( 6, 'fr', 'x' ) );
	}

	/**
	 * The canonical slug of an object that has no translated slug is ambiguous, so it is taken.
	 *
	 * @return void
	 */
	public function test_conflict_with_untranslated_canonical_slug_is_rejected() {
		lmat_test_set( 'post_names', array( 7 => 'y' ) );

		$this->assertInstanceOf( WP_Error::class, Slug_Store::set( 6, 'de', 'y' ) );

		Slug_Store::set( 7, 'de', 'z' );
		$this->assertTrue( Slug_Store::set( 6, 'de', 'y' ), 'A translated object frees its canonical slug.' );
	}

	/**
	 * The previous slug is kept as superseded and no longer resolves as current.
	 *
	 * @return void
	 */
	public function test_changing_a_slug_keeps_the_old_one_as_superseded() {
		Slug_Store::set( 5, 'de', 'a' );
		Slug_Store::set( 5, 'de', 'b' );

		$this->assertSame( 5, Slug_Store::find( 'b', 'de' ) );
		$this->assertSame( 0, Slug_Store::find( 'a', 'de' ) );
		$this->assertSame( 5, Slug_Store::find_superseded( 'a', 'de' ) );

		Slug_Store::set( 5, 'de', 'a' );
		$this->assertSame( 0, Slug_Store::find_superseded( 'a', 'de' ), 'A slug in use again is not superseded.' );
		$this->assertSame( 5, Slug_Store::find_superseded( 'b', 'de' ) );
	}

	/**
	 * Derivation appends a numeric suffix until the slug is free.
	 *
	 * @return void
	 */
	public function test_derive_appends_numeric_suffix_on_conflict() {
		Slug_Store::set( 5, 'de', 'open-day' );

		$this->assertSame( 'open-day-2', Slug_Store::derive( 'Open Day', 6, 'de' ) );
		$this->assertSame( 'open-day', Slug_Store::derive( 'Open Day', 5, 'de' ), 'An object does not conflict with itself.' );
		$this->assertSame( '', Slug_Store::derive( '!!!', 6, 'de' ) );
	}

	/**
	 * Forgetting an object releases its slugs, current and superseded.
	 *
	 * @return void
	 */
	public function test_forget_releases_the_slug() {
		Slug_Store::set( 5, 'de', 'a' );
		Slug_Store::set( 5, 'de', 'b' );
		$this->assertSame( 5, Slug_Store::find( 'b', 'de' ) );

		Slug_Store::forget( 5, 'post' );

		$this->assertSame( 0, Slug_Store::find( 'b', 'de' ) );
		$this->assertSame( 0, Slug_Store::find_superseded( 'a', 'de' ) );
		$this->assertTrue( Slug_Store::set( 6, 'de', 'a' ) );
	}

	/**
	 * Terms are scoped by taxonomy and parent.
	 *
	 * @return void
	 */
	public function test_term_slugs_are_unique_per_taxonomy_and_parent() {
		lmat_test_set(
			'term_slugs',
			array(
				10 => array( 'slug' => 'a', 'taxonomy' => 'product_cat', 'parent' => 1 ),
				11 => array( 'slug' => 'b', 'taxonomy' => 'product_cat', 'parent' => 1 ),
				12 => array( 'slug' => 'c', 'taxonomy' => 'product_cat', 'parent' => 2 ),
				13 => array( 'slug' => 'd', 'taxonomy' => 'product_tag', 'parent' => 0 ),
				14 => array( 'slug' => 'e', 'taxonomy' => 'product_cat', 'parent' => 1 ),
			)
		);

		$this->assertTrue( Slug_Store::set( 10, 'de', 'schuhe', 'term', 'product_cat' ) );
		$this->assertInstanceOf( WP_Error::class, Slug_Store::set( 11, 'de', 'schuhe', 'term', 'product_cat' ), 'Same parent.' );
		$this->assertTrue( Slug_Store::set( 12, 'de', 'schuhe', 'term', 'product_cat' ), 'Other parent.' );
		$this->assertTrue( Slug_Store::set( 13, 'de', 'schuhe', 'term', 'product_tag' ), 'Other taxonomy.' );
		$this->assertInstanceOf( WP_Error::class, Slug_Store::set( 14, 'de', 'b', 'term', 'product_cat' ), 'Canonical slug of an untranslated sibling.' );
		$this->assertTrue( Slug_Store::set( 14, 'de', 'c', 'term', 'product_cat' ), 'Canonical slug under another parent.' );
		$this->assertSame( 10, Slug_Store::find( 'schuhe', 'de', 'term', 'product_cat' ) );
		$this->assertSame( 13, Slug_Store::find( 'schuhe', 'de', 'term', 'product_tag' ) );
	}

	/**
	 * A base is stored per language and base type, and a replaced base is remembered.
	 *
	 * @return void
	 */
	public function test_bases_round_trip_and_supersede() {
		$this->assertTrue( Slug_Store::set_base( 'de', 'product', 'produkt' ) );
		$this->assertSame( 'produkt', Slug_Store::get_base( 'de', 'product' ) );
		$this->assertSame( '', Slug_Store::get_base( 'de', 'category' ) );
		$this->assertFalse( Slug_Store::is_superseded_base( 'produkt', 'de', 'product' ) );

		Slug_Store::set_base( 'de', 'product', 'artikel' );

		$this->assertSame( 'artikel', Slug_Store::get_base( 'de', 'product' ) );
		$this->assertTrue( Slug_Store::is_superseded_base( 'produkt', 'de', 'product' ) );
		$this->assertFalse( Slug_Store::is_superseded_base( 'produkt', 'fr', 'product' ) );
		$this->assertInstanceOf( WP_Error::class, Slug_Store::set_base( 'de', 'bogus', 'x' ) );
		$this->assertInstanceOf( WP_Error::class, Slug_Store::set_base( 'de', 'product', '!!!' ) );
	}

	/**
	 * Same-slug terms under different parents are told apart by the optional parent argument.
	 *
	 * @return void
	 */
	public function test_find_disambiguates_same_slug_terms_by_parent() {
		lmat_test_set(
			'term_slugs',
			array(
				10 => array( 'slug' => 'a', 'taxonomy' => 'product_cat', 'parent' => 1 ),
				11 => array( 'slug' => 'b', 'taxonomy' => 'product_cat', 'parent' => 2 ),
			)
		);
		Slug_Store::set( 10, 'de', 'schuhe', 'term', 'product_cat' );
		Slug_Store::set( 11, 'de', 'schuhe', 'term', 'product_cat' );
		Slug_Store::set( 11, 'de', 'boots', 'term', 'product_cat' );
		Slug_Store::set( 11, 'de', 'schuhe', 'term', 'product_cat' );

		$this->assertSame( 10, Slug_Store::find( 'schuhe', 'de', 'term', 'product_cat', 1 ) );
		$this->assertSame( 11, Slug_Store::find( 'schuhe', 'de', 'term', 'product_cat', 2 ) );
		$this->assertSame( 0, Slug_Store::find( 'schuhe', 'de', 'term', 'product_cat', 3 ) );
		$this->assertSame( 10, Slug_Store::find( 'schuhe', 'de', 'term', 'product_cat' ), 'No parent means any parent.' );
		$this->assertSame( array( 10, 11 ), Slug_Store::find_all( 'schuhe', 'de', 'term', 'product_cat' ) );
		$this->assertSame( array(), Slug_Store::find_all( 'nope', 'de' ) );
	}

	/**
	 * Superseded term slugs are also told apart by parent.
	 *
	 * @return void
	 */
	public function test_find_superseded_disambiguates_by_parent() {
		lmat_test_set(
			'term_slugs',
			array(
				10 => array( 'slug' => 'a', 'taxonomy' => 'product_cat', 'parent' => 1 ),
				11 => array( 'slug' => 'b', 'taxonomy' => 'product_cat', 'parent' => 2 ),
			)
		);
		foreach ( array( 10, 11 ) as $id ) {
			Slug_Store::set( $id, 'de', 'alt', 'term', 'product_cat' );
			Slug_Store::set( $id, 'de', 'neu-' . $id, 'term', 'product_cat' );
		}

		$this->assertSame( 11, Slug_Store::find_superseded( 'alt', 'de', 'term', 'product_cat', 2 ) );
		$this->assertSame( 10, Slug_Store::find_superseded( 'alt', 'de', 'term', 'product_cat', 1 ) );
	}

	/**
	 * The slug maps live in a non-persistent cache group, so an external object cache never serves a stale map.
	 *
	 * @return void
	 */
	public function test_cache_group_is_non_persistent() {
		Slug_Store::find( 'a', 'de' );

		$this->assertContains( Slug_Store::CACHE_GROUP, lmat_test_get( 'non_persistent_groups', array() ) );
	}

	/**
	 * A failed lookup is not cached as an empty map.
	 *
	 * @return void
	 */
	public function test_failed_lookup_is_not_cached() {
		lmat_test_set( 'wpdb_fail', true );
		$this->assertSame( 0, Slug_Store::find( 'a', 'de' ) );

		lmat_test_set( 'wpdb_fail', false );
		lmat_test_set( 'post_meta', array( 5 => array( '_lmat_wc_slug_de' => 'a' ) ) );
		$this->assertSame( 5, Slug_Store::find( 'a', 'de' ) );
	}
}
