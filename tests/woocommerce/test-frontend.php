<?php
/**
 * Covers localized product and variation text.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Frontend_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The renderer under test.
	 *
	 * @var Frontend
	 */
	protected $frontend;

	/**
	 * The localization context gate, kept accessible so tests can suspend it.
	 *
	 * @var Context_Gate
	 */
	protected $gate;

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
		$this->gate       = new Context_Gate();
		$this->frontend   = new Frontend( $this->repository, $this->gate );

		$this->repository->save(
			42,
			'de',
			array(
				'name'              => array( 'value' => 'Mitgliedsbeitrag', 'source' => 'Tassa di socio' ),
				'description'       => array( 'value' => 'Langer Text', 'source' => 'Testo lungo' ),
				'short_description' => array( 'value' => 'Kurz', 'source' => 'Breve' ),
			),
			0
		);
	}

	/**
	 * A German request gets the German name.
	 *
	 * @return void
	 */
	public function test_name_is_localized() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'Mitgliedsbeitrag', $this->frontend->product_name( 'Tassa di socio', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * The default language takes the short path: the repository is not consulted at all.
	 *
	 * @return void
	 */
	public function test_default_language_is_not_localized() {
		lmat_test_set( 'curlang', 'it' );

		$this->assertSame( 'Tassa di socio', $this->frontend->product_name( 'Tassa di socio', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * A language with no translation falls back to the source.
	 *
	 * @return void
	 */
	public function test_untranslated_language_falls_back() {
		lmat_test_set( 'curlang', 'fr' );

		$this->assertSame( 'Tassa di socio', $this->frontend->product_name( 'Tassa di socio', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * The admin reads canonical text even in a German session.
	 *
	 * @return void
	 */
	public function test_admin_reads_canonical_text() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_admin', true );

		$this->assertSame( 'Tassa di socio', $this->frontend->product_name( 'Tassa di socio', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * A suspended gate reads canonical text even though a German translation exists and German
	 * is not the default language. This is the path order emails and the admin rely on: if the
	 * `should_localize()` check were ever dropped from `localize()`, this would be the only test
	 * that catches translated text leaking into a suspended context.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function test_suspended_gate_reads_canonical_text() {
		lmat_test_set( 'curlang', 'de' );
		$this->gate->suspend( Context_Gate::CONTEXT_EMAIL );

		$this->assertSame( 'Tassa di socio', $this->frontend->product_name( 'Tassa di socio', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * Descriptions are localized on the same terms.
	 *
	 * @return void
	 */
	public function test_descriptions_are_localized() {
		lmat_test_set( 'curlang', 'de' );
		$product = new Lmat_Test_Product( 42 );

		$this->assertSame( 'Langer Text', $this->frontend->product_description( 'Testo lungo', $product ) );
		$this->assertSame( 'Kurz', $this->frontend->product_short_description( 'Breve', $product ) );
	}

	/**
	 * A variation's own description is localized against the variation ID, not the parent's.
	 *
	 * @return void
	 */
	public function test_variation_description_is_localized() {
		lmat_test_set( 'curlang', 'de' );
		$this->repository->save( 77, 'de', array( 'description' => array( 'value' => 'Variante DE', 'source' => 'Variante IT' ) ), 0 );

		$variation = new Lmat_Test_Product( 77, 'variation', 42 );

		$this->assertSame( 'Variante DE', $this->frontend->variation_description( 'Variante IT', $variation ) );
	}

	/**
	 * the_title is localized for a product read straight from the post.
	 *
	 * @return void
	 */
	public function test_post_title_is_localized_for_products() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'post_types', array( 42 => 'product' ) );

		$this->assertSame( 'Mitgliedsbeitrag', $this->frontend->post_title( 'Tassa di socio', 42 ) );
	}

	/**
	 * the_title leaves everything that is not a product alone.
	 *
	 * @return void
	 */
	public function test_post_title_ignores_other_post_types() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'post_types', array( 42 => 'page' ) );

		$this->assertSame( 'Tassa di socio', $this->frontend->post_title( 'Tassa di socio', 42 ) );
	}

	/**
	 * get_the_excerpt maps to the short description and accepts a post object.
	 *
	 * @return void
	 */
	public function test_post_excerpt_is_localized_for_products() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'post_types', array( 42 => 'product' ) );

		$this->assertSame( 'Kurz', $this->frontend->post_excerpt( 'Breve', new Lmat_Test_Post( 42 ) ) );
	}

	/**
	 * All six filters are registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_display_filters() {
		$this->frontend->init();

		$this->assertTrue( has_filter( 'woocommerce_product_get_name' ) );
		$this->assertTrue( has_filter( 'woocommerce_product_get_description' ) );
		$this->assertTrue( has_filter( 'woocommerce_product_get_short_description' ) );
		$this->assertTrue( has_filter( 'woocommerce_product_variation_get_description' ) );
		$this->assertTrue( has_filter( 'the_title' ) );
		$this->assertTrue( has_filter( 'get_the_excerpt' ) );
	}
}
