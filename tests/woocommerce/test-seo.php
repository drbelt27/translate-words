<?php
/**
 * Covers translated SEO meta on shared products and product term archives.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Seo;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Seo_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The localization context gate.
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * The adapter under test.
	 *
	 * @var Seo
	 */
	protected $seo;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$_REQUEST         = array();
		$this->repository = new Translation_Repository();
		$this->gate        = new Context_Gate();
		$this->seo        = new Seo( $this->repository, $this->gate, new Frontend( $this->repository, $this->gate ) );

		$this->repository->save(
			42,
			'de',
			array(
				Seo::FIELD_TITLE          => array( 'value' => 'Mitgliedsbeitrag kaufen', 'source' => 'Compra la tassa di socio' ),
				Seo::FIELD_DESCRIPTION    => array( 'value' => 'Deutsche Beschreibung', 'source' => 'Descrizione italiana' ),
				Seo::FIELD_OG_TITLE       => array( 'value' => 'OG DE', 'source' => 'OG IT' ),
				Seo::FIELD_OG_DESCRIPTION => array( 'value' => 'OG Beschreibung', 'source' => 'OG descrizione' ),
			),
			0
		);
	}

	/**
	 * All four fields are served on a product page.
	 *
	 * @return void
	 */
	public function test_all_four_fields_are_localized_on_a_product() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );

		$this->assertSame( 'Mitgliedsbeitrag kaufen', $this->seo->title( 'Compra la tassa di socio' ) );
		$this->assertSame( 'Deutsche Beschreibung', $this->seo->description( 'Descrizione italiana' ) );
		$this->assertSame( 'OG DE', $this->seo->og_title( 'OG IT' ) );
		$this->assertSame( 'OG Beschreibung', $this->seo->og_description( 'OG descrizione' ) );
	}

	/**
	 * An untranslated field falls back to what the SEO plugin computed.
	 *
	 * @return void
	 */
	public function test_untranslated_field_falls_back() {
		lmat_test_set( 'curlang', 'fr' );
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );

		$this->assertSame( 'Compra la tassa di socio', $this->seo->title( 'Compra la tassa di socio' ) );
	}

	/**
	 * A product term archive reads its title from the term store.
	 *
	 * @return void
	 */
	public function test_term_archive_uses_the_term_store() {
		$this->repository->save(
			9,
			'de',
			array( Seo::FIELD_TITLE => array( 'value' => 'Bekleidung kaufen', 'source' => 'Compra abbigliamento' ) ),
			0,
			Translation_Repository::TYPE_TERM
		);

		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_product_tax', 'product_cat' );
		lmat_test_set( 'queried_term', new WP_Term( 9, 'product_cat', 'Abbigliamento' ) );

		$this->assertSame( 'Bekleidung kaufen', $this->seo->title( 'Compra abbigliamento' ) );
	}

	/**
	 * Pages that are neither a product nor a product term archive are left to the SEO plugin.
	 *
	 * @return void
	 */
	public function test_other_pages_are_left_alone() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'Qualcosa', $this->seo->title( 'Qualcosa' ) );
	}

	/**
	 * The default language and the admin are untouched.
	 *
	 * @return void
	 */
	public function test_canonical_contexts_are_untouched() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );

		lmat_test_set( 'curlang', 'it' );
		$this->assertSame( 'Compra la tassa di socio', $this->seo->title( 'Compra la tassa di socio' ) );

		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_admin', true );
		$this->assertSame( 'Compra la tassa di socio', $this->seo->title( 'Compra la tassa di socio' ) );
	}

	/**
	 * The gate is consulted before anything else: a regression that dropped the should_localize()
	 * check from Seo::localize() would serve translated SEO meta during order/email/REST/cron
	 * contexts, where canonical text must be read instead.
	 *
	 * @return void
	 */
	public function test_suspended_gate_reads_canonical_text() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		$this->gate->suspend( Context_Gate::CONTEXT_EMAIL );

		$this->assertSame( 'Compra la tassa di socio', $this->seo->title( 'Compra la tassa di socio' ) );
	}

	/**
	 * Without SEOPress the adapter registers nothing at all.
	 *
	 * @return void
	 */
	public function test_nothing_is_registered_without_seopress() {
		$this->seo->init();

		$this->assertFalse( has_filter( 'seopress_titles_title' ) );
	}

	/**
	 * With SEOPress present the four filters are registered.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_filters_are_registered_with_seopress() {
		define( 'SEOPRESS_VERSION', '8.0' );

		$repository = new Translation_Repository();
		$gate       = new Context_Gate();
		$seo        = new Seo( $repository, $gate, new Frontend( $repository, $gate ) );
		$seo->init();

		$this->assertTrue( has_filter( 'seopress_titles_title' ) );
		$this->assertTrue( has_filter( 'seopress_titles_desc' ) );
		$this->assertTrue( has_filter( 'seopress_social_og_title' ) );
		$this->assertTrue( has_filter( 'seopress_social_og_desc' ) );
	}
}
