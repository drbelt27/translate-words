<?php
/**
 * Covers the WooCommerce shop translation URL resolution.
 *
 * @package Linguator
 */

use Linguator\Integrations\woocommerce\Linguator_WooCommerce;
use PHPUnit\Framework\TestCase;

class Shop_Translation_Url_Test extends TestCase {

	/**
	 * Integration under test.
	 *
	 * @var Linguator_WooCommerce
	 */
	private $integration;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( getenv( 'LMAT_TEST_WITHOUT_WOOCOMMERCE' ) ) {
			$this->markTestSkipped( 'This suite requires the WooCommerce stubs.' );
		}

		lmat_test_reset_state();
		$this->integration = new Linguator_WooCommerce();
	}

	/**
	 * Builds a language object as Linguator passes it to the filter.
	 *
	 * @param string $slug Language slug.
	 * @return object
	 */
	private function language( $slug ) {
		return (object) array( 'slug' => $slug );
	}

	/**
	 * Simulates a request to the shop archive with a translated shop page.
	 *
	 * @return void
	 */
	private function given_shop_archive_with_translation() {
		lmat_test_set( 'is_shop', true );
		lmat_test_set( 'shop_page_id', 100 );
		lmat_test_set( 'translations', array( '100:de' => 200, '100:it' => 100 ) );
		lmat_test_set(
			'permalinks',
			array(
				100 => 'https://example.test/shop/',
				200 => 'https://example.test/de/shop-2/',
			)
		);
	}

	/**
	 * Scenario 1: the translated shop permalink is returned.
	 *
	 * @return void
	 */
	public function test_returns_translated_shop_permalink() {
		$this->given_shop_archive_with_translation();

		$this->assertSame(
			'https://example.test/de/shop-2/',
			$this->integration->shop_translation_url( '', $this->language( 'de' ) )
		);
	}

	/**
	 * The self link used by the hreflang output resolves to the shop page itself.
	 *
	 * @return void
	 */
	public function test_returns_self_url_for_the_current_language() {
		$this->given_shop_archive_with_translation();

		$this->assertSame(
			'https://example.test/shop/',
			$this->integration->shop_translation_url( '', $this->language( 'it' ) )
		);
	}

	/**
	 * Scenario 2: no translation in the target language keeps Linguator's fallback.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_when_no_translation_for_target_language() {
		$this->given_shop_archive_with_translation();

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'fr' ) ) );
	}

	/**
	 * Scenarios 3, 4, 5 and 10: any request that is not the shop archive is left to core.
	 *
	 * @dataProvider non_shop_requests
	 *
	 * @param string $case Human readable request description.
	 * @return void
	 */
	public function test_returns_untouched_url_outside_the_shop_archive( $case ) {
		lmat_test_set( 'is_shop', false );
		lmat_test_set( 'shop_page_id', 100 );
		lmat_test_set( 'translations', array( '100:de' => 200 ) );
		lmat_test_set( 'permalinks', array( 200 => 'https://example.test/de/shop-2/' ) );

		$this->assertSame(
			'',
			$this->integration->shop_translation_url( '', $this->language( 'de' ) ),
			$case
		);
	}

	/**
	 * Request shapes that must not be touched by this integration.
	 *
	 * @return array<string, string[]>
	 */
	public function non_shop_requests() {
		return array(
			'ordinary page'                  => array( 'An ordinary page must keep the core is_page() branch.' ),
			'ordinary post'                  => array( 'An ordinary post must keep the core is_single() branch.' ),
			'another post type archive'      => array( 'Other archives must keep the core archive branch.' ),
			'translated shop page (reverse)' => array( 'The translated shop page is an ordinary page handled by core.' ),
		);
	}

	/**
	 * The shop page used as the site front page is left to Linguator's home URL logic.
	 *
	 * @return void
	 */
	public function test_skips_when_the_shop_page_is_the_site_front_page() {
		$this->given_shop_archive_with_translation();
		lmat_test_set( 'is_front_page', true );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * Scenario 7: an unset or invalid shop page falls back safely.
	 *
	 * @dataProvider invalid_shop_page_ids
	 *
	 * @param int $shop_page_id Shop page ID returned by WooCommerce.
	 * @return void
	 */
	public function test_returns_untouched_url_when_shop_page_id_is_invalid( $shop_page_id ) {
		lmat_test_set( 'is_shop', true );
		lmat_test_set( 'shop_page_id', $shop_page_id );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * Invalid shop page IDs.
	 *
	 * @return array<string, int[]>
	 */
	public function invalid_shop_page_ids() {
		return array(
			'unset option' => array( -1 ),
			'zero'         => array( 0 ),
		);
	}

	/**
	 * Scenario 8: an invalid translation mapping falls back safely.
	 *
	 * @dataProvider invalid_translation_ids
	 *
	 * @param mixed $translated_id Value returned by the translation lookup.
	 * @return void
	 */
	public function test_returns_untouched_url_when_translation_mapping_is_invalid( $translated_id ) {
		lmat_test_set( 'is_shop', true );
		lmat_test_set( 'shop_page_id', 100 );
		lmat_test_set( 'translations', array( '100:de' => $translated_id ) );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * Invalid translation lookup results.
	 *
	 * @return array<string, mixed[]>
	 */
	public function invalid_translation_ids() {
		return array(
			'zero'         => array( 0 ),
			'negative'     => array( -5 ),
			'empty string' => array( '' ),
			'non numeric'  => array( 'not-an-id' ),
		);
	}

	/**
	 * A translated shop page the visitor may not read is not linked.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_when_the_translated_page_is_not_readable() {
		$this->given_shop_archive_with_translation();
		lmat_test_set( 'unreadable_ids', array( 200 ) );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * A failed permalink generation falls back safely.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_when_permalink_generation_fails() {
		lmat_test_set( 'is_shop', true );
		lmat_test_set( 'shop_page_id', 100 );
		lmat_test_set( 'translations', array( '100:de' => 200 ) );
		lmat_test_set( 'permalinks', array() );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * A URL already resolved by another handler is never overridden.
	 *
	 * @return void
	 */
	public function test_does_not_override_url_resolved_by_an_earlier_filter() {
		$this->given_shop_archive_with_translation();

		$this->assertSame(
			'https://example.test/de/already-resolved/',
			$this->integration->shop_translation_url( 'https://example.test/de/already-resolved/', $this->language( 'de' ) )
		);
	}

	/**
	 * An unusable language argument is ignored.
	 *
	 * @dataProvider invalid_languages
	 *
	 * @param mixed $language Language argument.
	 * @return void
	 */
	public function test_returns_untouched_url_when_language_is_invalid( $language ) {
		$this->given_shop_archive_with_translation();

		$this->assertSame( '', $this->integration->shop_translation_url( '', $language ) );
	}

	/**
	 * Invalid language arguments.
	 *
	 * @return array<string, mixed[]>
	 */
	public function invalid_languages() {
		return array(
			'null'           => array( null ),
			'empty slug'     => array( (object) array( 'slug' => '' ) ),
			'missing slug'   => array( (object) array() ),
			'string instead' => array( 'de' ),
		);
	}

	/**
	 * A missing Linguator instance never fatals.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_when_linguator_is_unavailable() {
		$this->given_shop_archive_with_translation();
		lmat_test_set( 'linguator', false );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * Scenario 9: the integration hooks the shared switcher API, so every switcher
	 * including the Elementor widget is corrected without any Elementor specific code.
	 *
	 * @return void
	 */
	public function test_init_registers_the_shared_translation_url_filter() {
		$this->integration->init();

		$hooks = lmat_test_get( 'hooks', array() );

		// Since Linguator 2.3.0, init() also always registers the shared product identity
		// activation guard; see Coordinator_Test for that behaviour.
		$this->assertCount( 2, $hooks );
		$this->assertSame( 'lmat_pre_translation_url', $hooks[0]['hook'] );
		$this->assertSame( 15, $hooks[0]['priority'] );
		$this->assertSame( 2, $hooks[0]['accepted_args'] );
		$this->assertSame( array( $this->integration, 'shop_translation_url' ), $hooks[0]['callback'] );
		$this->assertSame( 'lmat_wc_shared_identity_guard', $hooks[1]['hook'] );
		$this->assertSame( 10, $hooks[1]['priority'] );
		$this->assertSame( 1, $hooks[1]['accepted_args'] );
	}
}
