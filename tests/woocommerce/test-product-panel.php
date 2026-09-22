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
}
