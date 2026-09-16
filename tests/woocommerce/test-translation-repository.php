<?php
/**
 * Covers per-object per-language text storage.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Translation_Repository;

/**
 * Minimal object-shaped meta entry, mirroring WooCommerce's WC_Meta_Data.
 *
 * @since 2.3.0
 */
class Lmat_Test_Meta_Data {
	/**
	 * Meta key.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key Meta key.
	 */
	public function __construct( $key ) {
		$this->key = $key;
	}
}

class Translation_Repository_Test extends TestCase {

	/**
	 * The repository under test.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$this->repository = new Translation_Repository();
	}

	/**
	 * The meta key carries the language slug.
	 *
	 * @return void
	 */
	public function test_meta_key() {
		$this->assertSame( '_lmat_wc_i18n_de', Translation_Repository::meta_key( 'de' ) );
	}

	/**
	 * A never-translated field falls back to the source text.
	 *
	 * @return void
	 */
	public function test_missing_field_falls_back_to_source() {
		$this->assertSame( 'Tassa di socio', $this->repository->get_value( 42, 'de', 'name', 'Tassa di socio' ) );
		$this->assertSame( Translation_Repository::STATUS_MISSING, $this->repository->get_status( 42, 'de', 'name', 'Tassa di socio' ) );
	}

	/**
	 * A translated field is returned instead of the source.
	 *
	 * @return void
	 */
	public function test_translated_field_is_returned() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Mitgliedsbeitrag', 'source' => 'Tassa di socio' ) ), 0 );

		$this->assertSame( 'Mitgliedsbeitrag', $this->repository->get_value( 42, 'de', 'name', 'Tassa di socio' ) );
		$this->assertSame( Translation_Repository::STATUS_TRANSLATED, $this->repository->get_status( 42, 'de', 'name', 'Tassa di socio' ) );
	}

	/**
	 * An intentionally empty translation is respected and is not a fallback.
	 *
	 * @return void
	 */
	public function test_intentionally_empty_field_is_respected() {
		$this->repository->save( 42, 'de', array( 'short_description' => array( 'value' => '', 'source' => 'Testo italiano' ) ), 0 );

		$this->assertSame( '', $this->repository->get_value( 42, 'de', 'short_description', 'Testo italiano' ) );
		$this->assertSame( Translation_Repository::STATUS_TRANSLATED, $this->repository->get_status( 42, 'de', 'short_description', 'Testo italiano' ) );
	}

	/**
	 * A changed source marks the translation stale without hiding it.
	 *
	 * @return void
	 */
	public function test_changed_source_marks_needs_review_and_keeps_displaying() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Mitgliedsbeitrag', 'source' => 'Tassa di socio' ) ), 0 );

		$this->assertSame( 'Mitgliedsbeitrag', $this->repository->get_value( 42, 'de', 'name', 'Tassa di socio 2027' ) );
		$this->assertSame( Translation_Repository::STATUS_NEEDS_REVIEW, $this->repository->get_status( 42, 'de', 'name', 'Tassa di socio 2027' ) );
	}

	/**
	 * Saving increments the revision.
	 *
	 * @return void
	 */
	public function test_revision_increments() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Eins', 'source' => 'Uno' ) ), 0 );
		$first = $this->repository->get_record( 42, 'de' );

		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Zwei', 'source' => 'Uno' ) ), $first['revision'] );
		$second = $this->repository->get_record( 42, 'de' );

		$this->assertSame( 1, $first['revision'] );
		$this->assertSame( 2, $second['revision'] );
	}

	/**
	 * A write carrying a stale revision is rejected and changes nothing.
	 *
	 * @return void
	 */
	public function test_stale_revision_is_rejected() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Eins', 'source' => 'Uno' ) ), 0 );
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Zwei', 'source' => 'Uno' ) ), 1 );

		$result = $this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Drei', 'source' => 'Uno' ) ), 1 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'lmat_wc_stale_revision', $result->get_error_code() );
		$this->assertSame( array( 'revision' => 2 ), $result->get_error_data() );
		$this->assertSame( 'Zwei', $this->repository->get_value( 42, 'de', 'name', 'Uno' ) );
	}

	/**
	 * Saving one field leaves the others in place.
	 *
	 * @return void
	 */
	public function test_save_merges_fields() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Name', 'source' => 'Nome' ) ), 0 );
		$this->repository->save( 42, 'de', array( 'description' => array( 'value' => 'Text', 'source' => 'Testo' ) ), 1 );

		$this->assertSame( 'Name', $this->repository->get_value( 42, 'de', 'name', 'Nome' ) );
		$this->assertSame( 'Text', $this->repository->get_value( 42, 'de', 'description', 'Testo' ) );
	}

	/**
	 * Languages do not leak into one another.
	 *
	 * @return void
	 */
	public function test_languages_are_isolated() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Deutsch', 'source' => 'Italiano' ) ), 0 );

		$this->assertSame( 'Italiano', $this->repository->get_value( 42, 'fr', 'name', 'Italiano' ) );
	}

	/**
	 * Terms use term meta and never collide with the post of the same ID.
	 *
	 * @return void
	 */
	public function test_terms_use_their_own_store() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Term', 'source' => 'Termine' ) ), 0, Translation_Repository::TYPE_TERM );

		$this->assertSame( 'Term', $this->repository->get_value( 42, 'de', 'name', 'Termine', Translation_Repository::TYPE_TERM ) );
		$this->assertSame( 'Termine', $this->repository->get_value( 42, 'de', 'name', 'Termine' ) );
	}

	/**
	 * Priming warms WordPress's own meta cache for a whole ID set at once.
	 *
	 * @return void
	 */
	public function test_prime_warms_the_meta_cache() {
		$this->repository->prime( array( 1, 2, 3 ) );
		$this->repository->prime( array( 7 ), Translation_Repository::TYPE_TERM );

		$this->assertSame( array( 1, 2, 3 ), lmat_test_get( 'primed_posts' ) );
		$this->assertSame( array( 7 ), lmat_test_get( 'primed_terms' ) );
	}

	/**
	 * Translation storage never reaches the WooCommerce REST meta_data payload.
	 *
	 * @return void
	 */
	public function test_rest_meta_is_filtered_out() {
		$response = new Lmat_Test_REST_Response(
			array(
				'meta_data' => array(
					array( 'key' => '_lmat_wc_i18n_de', 'value' => array( 'revision' => 1 ) ),
					array( 'key' => '_sku_extra', 'value' => 'keep-me' ),
				),
			)
		);

		$filtered = $this->repository->filter_rest_meta( $response, null, null );

		$this->assertCount( 1, $filtered->data['meta_data'] );
		$this->assertSame( '_sku_extra', $filtered->data['meta_data'][0]['key'] );
	}

	/**
	 * WooCommerce populates meta_data with objects, not arrays; that shape must be filtered too.
	 *
	 * @return void
	 */
	public function test_rest_meta_is_filtered_out_for_object_shaped_entries() {
		$response = new Lmat_Test_REST_Response(
			array(
				'meta_data' => array(
					new Lmat_Test_Meta_Data( '_lmat_wc_i18n_de' ),
					new Lmat_Test_Meta_Data( '_sku_extra' ),
				),
			)
		);

		$filtered = $this->repository->filter_rest_meta( $response, null, null );

		$this->assertCount( 1, $filtered->data['meta_data'] );
		$this->assertSame( '_sku_extra', $filtered->data['meta_data'][0]->key );
	}
}
