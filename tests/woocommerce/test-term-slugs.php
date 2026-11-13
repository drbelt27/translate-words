<?php
/**
 * Covers the translated slug fields on the product term edit screens.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Admin\Term_Slugs;
use Linguator\Integrations\woocommerce\Slug_Store;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Term_Slugs_Test extends TestCase {

	/**
	 * The screen under test.
	 *
	 * @var Term_Slugs
	 */
	protected $screen;

	/**
	 * Resets the simulated request: terms in several taxonomies, an editor, and an empty POST.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$this->screen = new Term_Slugs( new Translation_Repository() );
		lmat_test_set(
			'term_slugs',
			array(
				10 => array( 'slug' => 'clothes', 'taxonomy' => 'product_cat', 'parent' => 0 ),
				11 => array( 'slug' => 'sale', 'taxonomy' => 'product_tag', 'parent' => 0 ),
				12 => array( 'slug' => 'acme', 'taxonomy' => 'product_brand', 'parent' => 0 ),
				13 => array( 'slug' => 'blue', 'taxonomy' => 'pa_color', 'parent' => 0 ),
				14 => array( 'slug' => 'shirts', 'taxonomy' => 'product_cat', 'parent' => 10 ),
				15 => array( 'slug' => 'shoes', 'taxonomy' => 'product_cat', 'parent' => 0 ),
				20 => array( 'slug' => 'about', 'taxonomy' => 'category', 'parent' => 0 ),
			)
		);
		lmat_test_set( 'user_caps', array( 'edit_term' ) );
		$_POST = array();
	}

	/**
	 * Clears the simulated POST so a submission can never leak into another test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Submits the screen's form for a term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $slugs    Language slug mapped to submitted slug.
	 * @param string $nonce    Nonce value.
	 * @return void
	 */
	protected function submit( $term_id, $taxonomy, array $slugs, $nonce = 'valid-nonce' ) {
		$_POST = array(
			Term_Slugs::NONCE_FIELD => $nonce,
			Term_Slugs::FIELD       => $slugs,
		);

		$this->screen->save( $term_id, $term_id, $taxonomy );
	}

	/**
	 * Category, tag, brand and attribute terms each store a slug through the slug store.
	 *
	 * @return void
	 */
	public function test_term_screen_saves_a_translated_slug_for_category_tag_brand_and_attribute_terms() {
		$cases = array(
			10 => array( 'product_cat', 'kleidung' ),
			11 => array( 'product_tag', 'angebot' ),
			12 => array( 'product_brand', 'firma' ),
			13 => array( 'pa_color', 'blau' ),
		);

		foreach ( $cases as $term_id => $case ) {
			$this->submit( $term_id, $case[0], array( 'de' => 'Mein ' . $case[1] ) );

			$this->assertSame( 'mein-' . $case[1], Slug_Store::get( $term_id, 'de', Translation_Repository::TYPE_TERM ), $case[0] );
		}
	}

	/**
	 * An empty slug is derived from a translated term name that already exists, and left empty otherwise.
	 *
	 * @return void
	 */
	public function test_empty_term_slug_is_derived_from_the_translated_term_name_when_one_exists() {
		( new Translation_Repository() )->save(
			10,
			'de',
			array( 'name' => array( 'value' => 'Damen Kleidung', 'source' => 'Clothes' ) ),
			0,
			Translation_Repository::TYPE_TERM
		);

		$this->submit( 10, 'product_cat', array( 'de' => '', 'fr' => '' ) );

		$this->assertSame( 'damen-kleidung', Slug_Store::get( 10, 'de', Translation_Repository::TYPE_TERM ) );
		$this->assertSame( '', Slug_Store::get( 10, 'fr', Translation_Repository::TYPE_TERM ) );
		$this->assertSame( '', $this->screen->pull_error() );
	}

	/**
	 * The brand taxonomy is only offered, saved and released when it exists.
	 *
	 * @return void
	 */
	public function test_term_screen_skips_brand_when_the_taxonomy_does_not_exist() {
		lmat_test_set( 'taxonomies', array( 'product_cat', 'product_tag' ) );

		$this->assertNotContains( 'product_brand', $this->screen->taxonomies() );

		$this->submit( 12, 'product_brand', array( 'de' => 'firma' ) );

		$this->assertSame( '', Slug_Store::get( 12, 'de', Translation_Repository::TYPE_TERM ) );
	}

	/**
	 * Every global attribute taxonomy is offered, and no other taxonomy is.
	 *
	 * @return void
	 */
	public function test_every_global_attribute_taxonomy_is_offered_and_other_taxonomies_are_not() {
		lmat_test_set( 'taxonomies', array( 'product_cat', 'pa_color', 'pa_size', 'category', 'post_tag' ) );

		$this->assertSame( array( 'product_cat', 'product_tag', 'pa_color', 'pa_size' ), $this->screen->taxonomies() );

		$this->submit( 20, 'category', array( 'de' => 'ueber-uns' ) );

		$this->assertSame( '', Slug_Store::get( 20, 'de', Translation_Repository::TYPE_TERM ) );
	}

	/**
	 * A missing or wrong nonce and a missing edit_term capability each write nothing.
	 *
	 * @return void
	 */
	public function test_term_slug_save_requires_nonce_and_edit_term_capability() {
		$this->submit( 10, 'product_cat', array( 'de' => 'kleidung' ), 'forged-nonce' );
		$this->assertSame( '', Slug_Store::get( 10, 'de', Translation_Repository::TYPE_TERM ), 'wrong nonce' );

		$_POST = array( Term_Slugs::FIELD => array( 'de' => 'kleidung' ) );
		$this->screen->save( 10, 10, 'product_cat' );
		$this->assertSame( '', Slug_Store::get( 10, 'de', Translation_Repository::TYPE_TERM ), 'no nonce' );

		lmat_test_set( 'user_caps', array() );
		$this->submit( 10, 'product_cat', array( 'de' => 'kleidung' ) );
		$this->assertSame( '', Slug_Store::get( 10, 'de', Translation_Repository::TYPE_TERM ), 'no capability' );
	}

	/**
	 * A slug is only saved for a configured, non-default language: the default language always uses
	 * the canonical slug.
	 *
	 * @return void
	 */
	public function test_slug_is_not_saved_for_an_unknown_or_the_default_language() {
		$this->submit( 10, 'product_cat', array( 'xx' => 'kleidung', 'it' => 'abiti' ) );

		$this->assertSame( array(), array_filter( array_keys( (array) get_term_meta( 10 ) ), function ( $key ) {
			return 0 === strpos( $key, Slug_Store::META_PREFIX );
		} ) );
	}

	/**
	 * A conflict, a reserved word, a numeric slug and an empty one each show an error and keep the
	 * previous slug.
	 *
	 * @return void
	 */
	public function test_invalid_slugs_show_an_error_and_keep_the_previous_slug() {
		Slug_Store::set( 10, 'de', 'kleidung', Translation_Repository::TYPE_TERM, 'product_cat' );
		Slug_Store::set( 15, 'de', 'schuhe', Translation_Repository::TYPE_TERM, 'product_cat' );

		foreach ( array( 'schuhe', 'feed', '2024', '' ) as $bad ) {
			$this->submit( 10, 'product_cat', array( 'de' => $bad ) );

			$this->assertSame( 'kleidung', Slug_Store::get( 10, 'de', Translation_Repository::TYPE_TERM ), $bad );
			$this->assertStringStartsWith( 'de: ', $this->screen->pull_error(), $bad );
		}
	}

	/**
	 * Uniqueness is per parent: the same translated slug is allowed under a different parent and
	 * refused under the same one.
	 *
	 * @return void
	 */
	public function test_same_slug_is_allowed_under_a_different_parent_and_refused_under_the_same() {
		Slug_Store::set( 15, 'de', 'hemden', Translation_Repository::TYPE_TERM, 'product_cat' );

		$this->submit( 14, 'product_cat', array( 'de' => 'hemden' ) );
		$this->assertSame( 'hemden', Slug_Store::get( 14, 'de', Translation_Repository::TYPE_TERM ) );

		Slug_Store::forget( 14, Translation_Repository::TYPE_TERM );
		lmat_test_set( 'term_slugs', array_replace( lmat_test_get( 'term_slugs' ), array( 14 => array( 'slug' => 'shirts', 'taxonomy' => 'product_cat', 'parent' => 0 ) ) ) );

		$this->submit( 14, 'product_cat', array( 'de' => 'hemden' ) );
		$this->assertSame( '', Slug_Store::get( 14, 'de', Translation_Repository::TYPE_TERM ) );
		$this->assertNotSame( '', $this->screen->pull_error() );
	}

	/**
	 * The rendered input holds the stored slug of its own language, not a loop variable.
	 *
	 * @return void
	 */
	public function test_rendered_slug_inputs_hold_the_stored_slug_per_language() {
		Slug_Store::set( 10, 'de', 'kleidung', Translation_Repository::TYPE_TERM, 'product_cat' );
		Slug_Store::set( 10, 'fr', 'vetements', Translation_Repository::TYPE_TERM, 'product_cat' );
		set_transient( 'lmat_wc_term_slug_error_1', 'de: Taken.', 60 );

		ob_start();
		$this->screen->render_field( new WP_Term( 10, 'product_cat', 'Clothes' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="lmat_wc_term_slug[de]" value="kleidung"', $html );
		$this->assertStringContainsString( 'name="lmat_wc_term_slug[fr]" value="vetements"', $html );
		$this->assertStringContainsString( 'name="lmat_wc_term_slug[en]" value=""', $html );
		$this->assertStringNotContainsString( 'name="lmat_wc_term_slug[it]"', $html, 'the default language has no field' );
		$this->assertStringContainsString( 'name="lmat_wc_term_slugs_nonce"', $html );
		$this->assertStringContainsString( 'de: Taken.', $html );
	}

	/**
	 * Permanently deleting a term releases its slugs, so another term can take them.
	 *
	 * @return void
	 */
	public function test_delete_term_releases_the_slug_of_a_shared_taxonomy() {
		$this->screen->init();
		Slug_Store::set( 10, 'de', 'kleidung', Translation_Repository::TYPE_TERM, 'product_cat' );

		$this->assertInstanceOf( WP_Error::class, Slug_Store::set( 15, 'de', 'kleidung', Translation_Repository::TYPE_TERM, 'product_cat' ) );

		do_action( 'delete_term', 10, 10, 'product_cat' );

		$this->assertSame( '', Slug_Store::get( 10, 'de', Translation_Repository::TYPE_TERM ) );
		$this->assertTrue( Slug_Store::set( 15, 'de', 'kleidung', Translation_Repository::TYPE_TERM, 'product_cat' ) );
	}

	/**
	 * Deleting a term of a taxonomy that is not shared releases nothing.
	 *
	 * @return void
	 */
	public function test_delete_term_of_another_taxonomy_releases_nothing() {
		$this->screen->init();
		Slug_Store::set( 20, 'de', 'ueber-uns', Translation_Repository::TYPE_TERM, 'category' );

		do_action( 'delete_term', 20, 20, 'category' );

		$this->assertSame( 'ueber-uns', Slug_Store::get( 20, 'de', Translation_Repository::TYPE_TERM ) );
	}

	/**
	 * Registration: the release hook is always on, the screen and the save only in the admin.
	 *
	 * @return void
	 */
	public function test_save_and_fields_are_registered_only_in_the_admin() {
		$this->screen->init();

		$this->assertTrue( has_filter( 'delete_term' ) );
		$this->assertFalse( has_filter( 'edited_term' ) );

		lmat_test_set( 'is_admin', true );
		$this->screen->init();
		do_action( 'init' );

		$this->assertTrue( has_filter( 'edited_term' ) );
		$this->assertTrue( has_filter( 'pa_color_edit_form_fields' ) );
		$this->assertTrue( has_filter( 'product_brand_edit_form_fields' ) );
	}
}
