<?php
/**
 * Covers the variation payload, and the canonical values matching depends on.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Attribute_Translations;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Translation_Repository;
use Linguator\Integrations\woocommerce\Variations;

class Variations_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The adapter under test.
	 *
	 * @var Variations
	 */
	protected $variations;

	/**
	 * The label adapter, used to prove labels stay out of the matching values.
	 *
	 * @var Attribute_Translations
	 */
	protected $attributes;

	/**
	 * A representative variation payload.
	 *
	 * @var array
	 */
	protected $payload;

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
		$frontend         = new Frontend( $this->repository, $this->gate );
		$this->variations = new Variations( $this->repository, $this->gate, $frontend );
		$this->attributes = new Attribute_Translations( $this->repository, $this->gate, $frontend );

		$this->repository->save(
			42,
			'de',
			array(
				Attribute_Translations::label_field( 'Taglia' )            => array( 'value' => 'Größe', 'source' => 'Taglia' ),
				Attribute_Translations::option_field( 'Taglia', 'Grande' ) => array( 'value' => 'Groß', 'source' => 'Grande' ),
			),
			0
		);
		$this->repository->save( 77, 'de', array( 'description' => array( 'value' => 'Variante DE', 'source' => 'Variante IT' ) ), 0 );

		$this->payload = array(
			'variation_id'          => 77,
			'variation_description' => 'Variante IT',
			'sku'                   => 'SKU-77',
			'display_price'         => 49.0,
			'attributes'            => array( 'attribute_taglia' => 'Grande' ),
		);
	}

	/**
	 * The description in the payload is translated.
	 *
	 * @return void
	 */
	public function test_variation_description_is_localized() {
		lmat_test_set( 'curlang', 'de' );

		$data = $this->variations->available_variation( $this->payload, new Lmat_Test_Product( 42, 'variable' ), new Lmat_Test_Product( 77, 'variation', 42 ) );

		$this->assertSame( 'Variante DE', $data['variation_description'] );
	}

	/**
	 * Everything matching depends on is returned byte for byte unchanged.
	 *
	 * @return void
	 */
	public function test_canonical_values_are_untouched() {
		lmat_test_set( 'curlang', 'de' );

		$data = $this->variations->available_variation( $this->payload, new Lmat_Test_Product( 42, 'variable' ), new Lmat_Test_Product( 77, 'variation', 42 ) );

		$this->assertSame( array( 'attribute_taglia' => 'Grande' ), $data['attributes'] );
		$this->assertSame( 77, $data['variation_id'] );
		$this->assertSame( 'SKU-77', $data['sku'] );
		$this->assertSame( 49.0, $data['display_price'] );
	}

	/**
	 * The translated label exists, and is not the value. This is the invariant in one assertion.
	 *
	 * @return void
	 */
	public function test_a_translated_label_never_appears_in_a_matching_value() {
		lmat_test_set( 'curlang', 'de' );

		$label = $this->attributes->option_name( 'Grande', null, 'Taglia', new Lmat_Test_Product( 42, 'variable' ) );
		$data  = $this->variations->available_variation( $this->payload, new Lmat_Test_Product( 42, 'variable' ), new Lmat_Test_Product( 77, 'variation', 42 ) );

		$this->assertSame( 'Groß', $label );
		$this->assertNotContains( $label, array_values( $data['attributes'] ) );
	}

	/**
	 * A variation selected in German resolves to the same variation as in Italian.
	 *
	 * @return void
	 */
	public function test_the_same_variation_resolves_in_both_languages() {
		lmat_test_set( 'curlang', 'it' );
		$italian = $this->variations->available_variation( $this->payload, new Lmat_Test_Product( 42, 'variable' ), new Lmat_Test_Product( 77, 'variation', 42 ) );

		lmat_test_set( 'curlang', 'de' );
		$german = $this->variations->available_variation( $this->payload, new Lmat_Test_Product( 42, 'variable' ), new Lmat_Test_Product( 77, 'variation', 42 ) );

		$this->assertSame( $italian['variation_id'], $german['variation_id'] );
		$this->assertSame( $italian['attributes'], $german['attributes'] );
	}

	/**
	 * A suspended gate reads canonical description even though a German translation exists and German
	 * is not the default language. This is the path order emails and the admin rely on: if the
	 * `should_localize()` check were ever dropped from `available_variation()`, this would be the only test
	 * that catches translated text leaking into a suspended context.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function test_suspended_gate_reads_canonical_text() {
		lmat_test_set( 'curlang', 'de' );
		$this->gate->suspend( Context_Gate::CONTEXT_EMAIL );

		$data = $this->variations->available_variation( $this->payload, new Lmat_Test_Product( 42, 'variable' ), new Lmat_Test_Product( 77, 'variation', 42 ) );

		$this->assertSame( 'Variante IT', $data['variation_description'] );
	}

	/**
	 * A payload without a description is left alone rather than given an empty key.
	 *
	 * @return void
	 */
	public function test_payload_without_description_is_unchanged() {
		lmat_test_set( 'curlang', 'de' );
		unset( $this->payload['variation_description'] );

		$data = $this->variations->available_variation( $this->payload, new Lmat_Test_Product( 42, 'variable' ), new Lmat_Test_Product( 77, 'variation', 42 ) );

		$this->assertArrayNotHasKey( 'variation_description', $data );
	}

	/**
	 * The filter is registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_payload_filter() {
		$this->variations->init();

		$this->assertTrue( has_filter( 'woocommerce_available_variation' ) );
	}
}
