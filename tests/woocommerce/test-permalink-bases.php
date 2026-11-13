<?php
/**
 * Covers the per-language permalink bases screen.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Admin\Permalink_Bases;
use Linguator\Integrations\woocommerce\Slug_Resolver;
use Linguator\Integrations\woocommerce\Slug_Routing;
use Linguator\Integrations\woocommerce\Slug_Store;

class Permalink_Bases_Test extends TestCase {

	/**
	 * The screen under test.
	 *
	 * @var Permalink_Bases
	 */
	protected $screen;

	/**
	 * Resets the simulated request: an administrator, the routing's flush hook wired, an empty POST.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$this->screen = new Permalink_Bases();
		lmat_test_set( 'user_caps', array( 'manage_options' ) );
		( new Slug_Routing( new Slug_Resolver( new Slug_Store() ), new Slug_Store() ) )->init();
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
	 * Submits the screen's form.
	 *
	 * @param array  $bases Language mapped to base type mapped to base.
	 * @param string $nonce Nonce value.
	 * @return void
	 */
	protected function submit( array $bases, $nonce = 'valid-nonce' ) {
		$_POST = array(
			Permalink_Bases::NONCE_FIELD => $nonce,
			Permalink_Bases::FIELD       => $bases,
		);

		$this->screen->save();
	}

	/**
	 * Each language and each base type stores its own base, and one change flushes the rules once.
	 *
	 * @return void
	 */
	public function test_bases_screen_saves_per_language_per_base_type_and_flushes_once() {
		$this->submit( array( 'de' => array( 'product' => 'Produkt' ) ) );

		$this->assertSame( 'produkt', Slug_Store::get_base( 'de', 'product' ) );
		$this->assertSame( 1, lmat_test_get( 'flushes', 0 ), 'one changed base flushes once' );

		$this->submit( array( 'de' => array( 'product' => 'produkt', 'category' => '' ) ) );
		$this->assertSame( 1, lmat_test_get( 'flushes', 0 ), 'resubmitting the same value flushes nothing more' );

		$this->submit(
			array(
				'de' => array( 'category' => 'Kategorie', 'tag' => 'schlagwort', 'attribute' => 'merkmal' ),
				'fr' => array( 'product' => 'produit/fiche' ),
			)
		);

		$this->assertSame( 'kategorie', Slug_Store::get_base( 'de', 'category' ) );
		$this->assertSame( 'schlagwort', Slug_Store::get_base( 'de', 'tag' ) );
		$this->assertSame( 'merkmal', Slug_Store::get_base( 'de', 'attribute' ) );
		$this->assertSame( 'produit/fiche', Slug_Store::get_base( 'fr', 'product' ) );
		$this->assertSame( '', Slug_Store::get_base( 'en', 'product' ) );
	}

	/**
	 * A base that is also an existing page's path warns but is still saved.
	 *
	 * @return void
	 */
	public function test_bases_screen_warns_but_does_not_block_a_base_that_collides_with_a_page_path() {
		lmat_test_set( 'pages', array( 'shop/produkt' => true ) );

		$this->submit( array( 'de' => array( 'product' => 'shop/produkt', 'tag' => 'schlagwort' ) ) );

		$this->assertSame( 'shop/produkt', Slug_Store::get_base( 'de', 'product' ) );
		$this->assertSame( 'schlagwort', Slug_Store::get_base( 'de', 'tag' ) );

		ob_start();
		$this->screen->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'shop/produkt', $html );
		$this->assertSame( 1, substr_count( $html, 'notice-warning' ), 'only the colliding base warns' );
		$this->assertStringNotContainsString( 'notice-error', $html );
	}

	/**
	 * An invalid base is reported and does not stop the valid ones.
	 *
	 * @return void
	 */
	public function test_bases_screen_reports_an_invalid_base_and_saves_the_rest() {
		$this->submit( array( 'de' => array( 'product' => '///', 'tag' => 'schlagwort', 'bogus' => 'x' ) ) );

		$this->assertSame( '', Slug_Store::get_base( 'de', 'product' ) );
		$this->assertSame( 'schlagwort', Slug_Store::get_base( 'de', 'tag' ) );

		ob_start();
		$this->screen->render();

		$this->assertStringContainsString( 'notice-error', ob_get_clean() );
	}

	/**
	 * A missing or wrong nonce and a missing manage_options capability each write nothing, and the
	 * page itself renders nothing without the capability.
	 *
	 * @return void
	 */
	public function test_bases_screen_requires_manage_options() {
		$this->submit( array( 'de' => array( 'product' => 'produkt' ) ), 'forged-nonce' );
		$this->assertSame( '', Slug_Store::get_base( 'de', 'product' ), 'wrong nonce' );

		$_POST = array( Permalink_Bases::FIELD => array( 'de' => array( 'product' => 'produkt' ) ) );
		$this->screen->save();
		$this->assertSame( '', Slug_Store::get_base( 'de', 'product' ), 'no nonce' );

		lmat_test_set( 'user_caps', array( 'edit_posts' ) );
		$this->submit( array( 'de' => array( 'product' => 'produkt' ) ) );
		$this->assertSame( '', Slug_Store::get_base( 'de', 'product' ), 'no capability' );
		$this->assertSame( 0, lmat_test_get( 'flushes', 0 ) );

		ob_start();
		$this->screen->render();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Only configured, non-default languages are written.
	 *
	 * @return void
	 */
	public function test_unknown_or_default_language_writes_nothing() {
		$this->submit( array( 'xx' => array( 'product' => 'produkt' ), 'it' => array( 'product' => 'prodotto' ) ) );

		$this->assertSame( array(), (array) get_option( Slug_Store::BASES_OPTION, array() ) );
		$this->assertSame( 0, lmat_test_get( 'flushes', 0 ) );
	}

	/**
	 * The inputs show the stored base of their own language and type, and no default-language row.
	 *
	 * @return void
	 */
	public function test_rendered_inputs_hold_the_stored_base_per_language_and_type() {
		Slug_Store::set_base( 'de', 'product', 'produkt' );
		Slug_Store::set_base( 'fr', 'tag', 'etiquette' );

		ob_start();
		$this->screen->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="lmat_wc_bases[de][product]" value="produkt"', $html );
		$this->assertStringContainsString( 'name="lmat_wc_bases[fr][tag]" value="etiquette"', $html );
		$this->assertStringContainsString( 'name="lmat_wc_bases[de][tag]" value=""', $html );
		$this->assertStringNotContainsString( 'lmat_wc_bases[it]', $html );
		$this->assertStringContainsString( 'name="lmat_wc_permalink_bases_nonce"', $html );
	}

	/**
	 * The page is added under Settings and its load hook saves before output.
	 *
	 * @return void
	 */
	public function test_page_registers_under_settings_and_saves_on_load() {
		$this->screen->init();
		do_action( 'admin_menu' );

		$this->assertTrue( has_filter( 'load-settings_page_' . Permalink_Bases::PAGE ) );
	}
}
