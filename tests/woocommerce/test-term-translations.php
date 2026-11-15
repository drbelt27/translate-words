<?php
/**
 * Covers translated product term labels.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Term_Translations;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Term_Translations_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The adapter under test.
	 *
	 * @var Term_Translations
	 */
	protected $terms;

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
		$gate             = new Context_Gate();
		$this->terms      = new Term_Translations( $this->repository, $gate, new Frontend( $this->repository, $gate ) );

		$this->repository->save(
			9,
			'de',
			array(
				'name'        => array( 'value' => 'Bekleidung', 'source' => 'Abbigliamento' ),
				'description' => array( 'value' => 'Beschreibung', 'source' => 'Descrizione' ),
			),
			0,
			Translation_Repository::TYPE_TERM
		);
	}

	/**
	 * A product category reads German.
	 *
	 * @return void
	 */
	public function test_term_name_and_description_are_localized() {
		lmat_test_set( 'curlang', 'de' );

		$term = $this->terms->localize_term( new WP_Term( 9, 'product_cat', 'Abbigliamento', 'Descrizione' ), 'product_cat' );

		$this->assertSame( 'Bekleidung', $term->name );
		$this->assertSame( 'Beschreibung', $term->description );
	}

	/**
	 * The cached term object is never mutated: WordPress hands out the same instance to every
	 * caller in the request, so translating it in place would poison canonical reads.
	 *
	 * @return void
	 */
	public function test_the_cached_term_object_is_not_mutated() {
		lmat_test_set( 'curlang', 'de' );

		$original = new WP_Term( 9, 'product_cat', 'Abbigliamento', 'Descrizione' );
		$localized = $this->terms->localize_term( $original, 'product_cat' );

		$this->assertSame( 'Abbigliamento', $original->name );
		$this->assertNotSame( $original, $localized );
	}

	/**
	 * Taxonomies outside the product family are left alone.
	 *
	 * @return void
	 */
	public function test_other_taxonomies_are_untouched() {
		lmat_test_set( 'curlang', 'de' );

		$term = $this->terms->localize_term( new WP_Term( 9, 'category', 'Abbigliamento' ), 'category' );

		$this->assertSame( 'Abbigliamento', $term->name );
	}

	/**
	 * The default language and the admin read canonical labels.
	 *
	 * @return void
	 */
	public function test_canonical_contexts_are_untouched() {
		lmat_test_set( 'curlang', 'it' );
		$this->assertSame( 'Abbigliamento', $this->terms->localize_term( new WP_Term( 9, 'product_cat', 'Abbigliamento' ), 'product_cat' )->name );

		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_admin', true );
		$this->assertSame( 'Abbigliamento', $this->terms->localize_term( new WP_Term( 9, 'product_cat', 'Abbigliamento' ), 'product_cat' )->name );
	}

	/**
	 * A list is localized term by term, and a list of IDs is passed straight through.
	 *
	 * @return void
	 */
	public function test_term_lists_are_handled() {
		lmat_test_set( 'curlang', 'de' );

		$localized = $this->terms->localize_terms( array( new WP_Term( 9, 'product_cat', 'Abbigliamento' ) ) );
		$this->assertSame( 'Bekleidung', $localized[0]->name );

		$this->assertSame( array( 9, 10 ), $this->terms->localize_terms( array( 9, 10 ) ) );
	}

	/**
	 * A suspended gate reads the canonical term name even though a German translation exists and
	 * German is not the default language. This is the path order emails and the admin rely on: if
	 * the `should_localize()` check were ever dropped from `language()`, this would be the only
	 * test that catches a translated term label leaking into a suspended context.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function test_suspended_gate_reads_canonical_text() {
		lmat_test_set( 'curlang', 'de' );

		$gate = new Context_Gate();
		$gate->suspend( Context_Gate::CONTEXT_EMAIL );

		$terms = new Term_Translations( $this->repository, $gate, new Frontend( $this->repository, $gate ) );

		$term = $terms->localize_term( new WP_Term( 9, 'product_cat', 'Abbigliamento', 'Descrizione' ), 'product_cat' );

		$this->assertSame( 'Abbigliamento', $term->name );
	}

	/**
	 * All three read paths are registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_read_filters() {
		$this->terms->init();

		$this->assertTrue( has_filter( 'get_term' ) );
		$this->assertTrue( has_filter( 'get_terms' ) );
		$this->assertTrue( has_filter( 'wp_get_object_terms' ) );
	}
}
