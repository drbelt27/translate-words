<?php
/**
 * Covers the entry panel's field set and its input sanitization.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Admin\Product_Panel;
use Linguator\Integrations\woocommerce\Attribute_Translations;
use Linguator\Integrations\woocommerce\Seo;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Product_Panel_Test extends TestCase {

	/**
	 * The panel under test.
	 *
	 * @var Product_Panel
	 */
	protected $panel;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$this->panel = new Product_Panel( new Translation_Repository() );
	}

	/**
	 * Clears the simulated $_GET so a language-link round-trip test can never leak into another.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_GET['lmat_wc_language'] );
		parent::tearDown();
	}

	/**
	 * Everything Phase 1 renders is enterable: the product text and the four SEO fields.
	 *
	 * @return void
	 */
	public function test_descriptors_cover_the_whole_phase_1_scope() {
		$keys = wp_list_pluck( $this->panel->descriptors( new Lmat_Test_Product( 42 ) ), 'key' );

		$this->assertContains( 'name', $keys );
		$this->assertContains( 'short_description', $keys );
		$this->assertContains( 'description', $keys );
		$this->assertContains( Seo::FIELD_TITLE, $keys );
		$this->assertContains( Seo::FIELD_DESCRIPTION, $keys );
		$this->assertContains( Seo::FIELD_OG_TITLE, $keys );
		$this->assertContains( Seo::FIELD_OG_DESCRIPTION, $keys );
	}

	/**
	 * A product's attributes contribute a label field and one field per option.
	 *
	 * @return void
	 */
	public function test_descriptors_include_the_attribute_label_map() {
		lmat_test_set( 'product_attributes', array( 42 => array( 'Taglia' => array( 'Grande', 'Piccolo' ) ) ) );

		$keys = wp_list_pluck( $this->panel->descriptors( new Lmat_Test_Product( 42, 'variable' ) ), 'key' );

		$this->assertContains( Attribute_Translations::label_field( 'Taglia' ), $keys );
		$this->assertContains( Attribute_Translations::option_field( 'Taglia', 'Grande' ), $keys );
		$this->assertContains( Attribute_Translations::option_field( 'Taglia', 'Piccolo' ), $keys );
	}

	/**
	 * Text fields are stripped of markup; the two rich fields keep post-safe HTML. Proves the
	 * type-based sanitization required by the project's security rules: TYPE_TEXT through
	 * sanitize_text_field(), TYPE_HTML through wp_kses_post().
	 *
	 * @return void
	 */
	public function test_input_is_sanitized_by_field_type() {
		$descriptors = array(
			array( 'key' => 'name', 'label' => 'Name', 'source' => 'Nome', 'type' => Product_Panel::TYPE_TEXT ),
			array( 'key' => 'description', 'label' => 'Description', 'source' => 'Testo', 'type' => Product_Panel::TYPE_HTML ),
		);

		$collected = $this->panel->collect(
			array(
				'name'        => '<b>Mitgliedsbeitrag</b>',
				'description' => '<p>Text</p>',
			),
			$descriptors
		);

		$this->assertSame( 'Mitgliedsbeitrag', $collected['name']['value'] );
		$this->assertSame( '<p>Text</p>', $collected['description']['value'] );
		$this->assertSame( 'Nome', $collected['name']['source'] );
	}

	/**
	 * A field left out of the payload is not written, so an untouched field stays untranslated
	 * rather than becoming an intentional empty.
	 *
	 * @return void
	 */
	public function test_absent_fields_are_not_written() {
		$descriptors = array(
			array( 'key' => 'name', 'label' => 'Name', 'source' => 'Nome', 'type' => Product_Panel::TYPE_TEXT ),
		);

		$this->assertSame( array(), $this->panel->collect( array(), $descriptors ) );
	}

	/**
	 * A field submitted empty is written as an intentional empty, which is a different state.
	 *
	 * @return void
	 */
	public function test_empty_submission_is_an_intentional_empty() {
		$descriptors = array(
			array( 'key' => 'name', 'label' => 'Name', 'source' => 'Nome', 'type' => Product_Panel::TYPE_TEXT ),
		);

		$collected = $this->panel->collect( array( 'name' => '' ), $descriptors );

		$this->assertArrayHasKey( 'name', $collected );
		$this->assertSame( '', $collected['name']['value'] );
	}

	/**
	 * A key not in the descriptor list is ignored, so a crafted payload cannot write arbitrary
	 * fields into the record. This is the test the security rules ask for: proof that collect()
	 * cannot be used to write a field it was not given, e.g. a canonical value like 'sku'.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_ignored() {
		$descriptors = array(
			array( 'key' => 'name', 'label' => 'Name', 'source' => 'Nome', 'type' => Product_Panel::TYPE_TEXT ),
		);

		$collected = $this->panel->collect( array( 'name' => 'Ok', 'sku' => 'HACKED' ), $descriptors );

		$this->assertSame( array( 'name' ), array_keys( $collected ) );
	}

	/**
	 * The hooks are registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_admin_hooks() {
		$this->panel->init();

		$this->assertTrue( has_filter( 'add_meta_boxes' ) );
		$this->assertTrue( has_filter( 'save_post_product' ) );
	}

	/**
	 * A label is handed back raw, never pre-escaped: the view is the only place that echoes a
	 * label, so it must be the only place that escapes one. Fix round 1, finding 1 — a label was
	 * previously wrapped in esc_html__() here and escaped again in the view, which is harmless for
	 * plain English UI strings but corrupts a translated label containing &, <, >, or a quote.
	 * This proves descriptors() no longer pre-escapes, and that escaping it exactly once (what the
	 * view does) yields a correctly single-escaped result rather than a double-escaped one.
	 *
	 * @return void
	 */
	public function test_attribute_labels_are_not_pre_escaped() {
		$attribute = 'Fit & "Size"';
		lmat_test_set( 'product_attributes', array( 42 => array( $attribute => array() ) ) );

		$descriptors = $this->panel->descriptors( new Lmat_Test_Product( 42, 'variable' ) );

		$label = null;

		foreach ( $descriptors as $descriptor ) {
			if ( Attribute_Translations::label_field( $attribute ) === $descriptor['key'] ) {
				$label = $descriptor['label'];
			}
		}

		$this->assertNotNull( $label, 'The attribute label descriptor must be present.' );
		$this->assertStringContainsString( $attribute, $label, 'descriptors() must hand back raw text.' );
		$this->assertStringNotContainsString( '&amp;', $label, 'A raw label must not already contain an HTML entity.' );

		// Simulate the view's single point of escaping (real WordPress semantics: ENT_QUOTES,
		// double_encode disabled) and confirm the result is correctly escaped once, not twice.
		$escaped_once = htmlspecialchars( $label, ENT_QUOTES, 'UTF-8', false );

		$this->assertStringContainsString( 'Fit &amp; &quot;Size&quot;', $escaped_once );
		$this->assertStringNotContainsString( '&amp;amp;', $escaped_once, 'The ampersand must be escaped exactly once.' );
		$this->assertStringNotContainsString( '&amp;quot;', $escaped_once, 'The quote must be escaped exactly once.' );
	}

	/**
	 * Fix round 1, finding 2 — the language control must not look like a switcher while silently
	 * not being one. Each language gets a real link carrying `lmat_wc_language` in the query
	 * string, the exact parameter selected_language() reads back, and the language currently being
	 * edited is marked rather than linked.
	 *
	 * @return void
	 */
	public function test_language_switch_links_target_each_language() {
		lmat_test_set( 'languages', array( 'it', 'de' ) );

		$links = $this->panel->language_switch_links( new Lmat_Test_Post( 42 ), 'it' );

		$this->assertArrayHasKey( 'de', $links );
		$this->assertStringContainsString( 'lmat_wc_language=de', $links['de']['url'] );
		$this->assertFalse( $links['de']['current'] );
		$this->assertTrue( $links['it']['current'] );
	}

	/**
	 * Proves the mechanism actually works end to end, which is what the previous <select> did not:
	 * the URL a language link points to, once landed on, must cause selected_language() to report
	 * that exact language, so a translation typed after following the link is saved under it.
	 *
	 * @return void
	 */
	public function test_language_link_round_trips_through_selected_language() {
		lmat_test_set( 'languages', array( 'it', 'de' ) );

		$links = $this->panel->language_switch_links( new Lmat_Test_Post( 42 ), 'it' );

		$query_string = (string) parse_url( $links['de']['url'], PHP_URL_QUERY );
		parse_str( $query_string, $query_args );

		$_GET['lmat_wc_language'] = $query_args['lmat_wc_language'];

		$this->assertSame( 'de', $this->panel->selected_language() );
	}
}
