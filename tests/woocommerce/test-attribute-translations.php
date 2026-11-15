<?php
/**
 * Covers attribute label translation, and the canonical values it must never touch.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Attribute_Translations;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Attribute_Translations_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The adapter under test.
	 *
	 * @var Attribute_Translations
	 */
	protected $attributes;

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
		$this->attributes = new Attribute_Translations( $this->repository, $this->gate, new Frontend( $this->repository, $this->gate ) );

		$this->repository->save(
			42,
			'de',
			array(
				Attribute_Translations::label_field( 'Taglia' )            => array( 'value' => 'Größe', 'source' => 'Taglia' ),
				Attribute_Translations::option_field( 'Taglia', 'Grande' ) => array( 'value' => 'Groß', 'source' => 'Grande' ),
			),
			0
		);
	}

	/**
	 * The same attribute reaches the display hooks under three different spellings, depending on
	 * which WooCommerce call site fires them. All three must resolve to one field key.
	 *
	 * @return void
	 */
	public function test_attribute_key_is_normalized() {
		$this->assertSame( Attribute_Translations::label_field( 'Taglia' ), Attribute_Translations::label_field( 'pa_taglia' ) );
		$this->assertSame( Attribute_Translations::label_field( 'Taglia' ), Attribute_Translations::label_field( 'attribute_taglia' ) );
		$this->assertSame(
			Attribute_Translations::option_field( 'Taglia', 'Grande' ),
			Attribute_Translations::option_field( 'pa_taglia', 'Grande' )
		);
	}

	/**
	 * The field key of an option is derived from the canonical value, never from a label.
	 *
	 * @return void
	 */
	public function test_option_field_key_is_derived_from_the_canonical_value() {
		$this->assertSame(
			Attribute_Translations::option_field( 'Taglia', 'Grande' ),
			Attribute_Translations::option_field( 'Taglia', 'Grande' )
		);
		$this->assertNotSame(
			Attribute_Translations::option_field( 'Taglia', 'Grande' ),
			Attribute_Translations::option_field( 'Taglia', 'Groß' )
		);
	}

	/**
	 * The attribute label is translated.
	 *
	 * @return void
	 */
	public function test_attribute_label_is_localized() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'Größe', $this->attributes->attribute_label( 'Taglia', 'Taglia', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * The option label is translated.
	 *
	 * @return void
	 */
	public function test_option_label_is_localized() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'Groß', $this->attributes->option_name( 'Grande', null, 'Taglia', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * A variation resolves its labels from the parent product's map.
	 *
	 * @return void
	 */
	public function test_variation_resolves_the_parent_map() {
		lmat_test_set( 'curlang', 'de' );

		$variation = new Lmat_Test_Product( 77, 'variation', 42 );

		$this->assertSame( 'Groß', $this->attributes->option_name( 'Grande', null, 'Taglia', $variation ) );
	}

	/**
	 * An untranslated option keeps its canonical label.
	 *
	 * @return void
	 */
	public function test_untranslated_option_keeps_the_canonical_label() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'Piccolo', $this->attributes->option_name( 'Piccolo', null, 'Taglia', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * Without a product there is no map to read, so nothing is translated.
	 *
	 * @return void
	 */
	public function test_no_product_means_no_translation() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'Taglia', $this->attributes->attribute_label( 'Taglia', 'Taglia', null ) );
	}

	/**
	 * The default language and the admin both read canonical labels.
	 *
	 * @return void
	 */
	public function test_canonical_contexts_are_untouched() {
		$product = new Lmat_Test_Product( 42 );

		lmat_test_set( 'curlang', 'it' );
		$this->assertSame( 'Taglia', $this->attributes->attribute_label( 'Taglia', 'Taglia', $product ) );

		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_admin', true );
		$this->assertSame( 'Taglia', $this->attributes->attribute_label( 'Taglia', 'Taglia', $product ) );
	}

	/**
	 * A suspended gate reads canonical labels even though a German translation exists and German
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

		$this->assertSame( 'Taglia', $this->attributes->attribute_label( 'Taglia', 'Taglia', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * Both display hooks are registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_display_hooks() {
		$this->attributes->init();

		$this->assertTrue( has_filter( 'woocommerce_attribute_label' ) );
		$this->assertTrue( has_filter( 'woocommerce_variation_option_name' ) );
	}
}
