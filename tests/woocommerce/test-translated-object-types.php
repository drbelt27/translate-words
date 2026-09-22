<?php
/**
 * Covers removing products and product taxonomies from Linguator's translation model.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Translated_Object_Types;

class Translated_Object_Types_Test extends TestCase {

	/**
	 * The adapter under test.
	 *
	 * @var Translated_Object_Types
	 */
	protected $adapter;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$this->adapter = new Translated_Object_Types();
	}

	/**
	 * Products and variations leave the translated post types.
	 *
	 * @return void
	 */
	public function test_products_are_removed() {
		$post_types = array(
			'post'              => 'post',
			'page'              => 'page',
			'product'           => 'product',
			'product_variation' => 'product_variation',
		);

		$this->assertSame(
			array( 'post' => 'post', 'page' => 'page' ),
			$this->adapter->remove_post_types( $post_types )
		);
	}

	/**
	 * Product taxonomies leave the translated taxonomies.
	 *
	 * @return void
	 */
	public function test_product_taxonomies_are_removed() {
		$taxonomies = array(
			'category'      => 'category',
			'product_cat'   => 'product_cat',
			'product_tag'   => 'product_tag',
			'product_brand' => 'product_brand',
		);

		$this->assertSame(
			array( 'category' => 'category' ),
			$this->adapter->remove_taxonomies( $taxonomies )
		);
	}

	/**
	 * A list with integer keys is handled too: Linguator's settings module calls the same
	 * filters with an empty array, and other integrations append plain values.
	 *
	 * @return void
	 */
	public function test_integer_keyed_lists_are_handled() {
		$result = $this->adapter->remove_post_types( array( 0 => 'product', 1 => 'page' ) );

		$this->assertSame( array( 'page' ), array_values( $result ) );
	}

	/**
	 * The settings screen is filtered too, so shared mode cannot be contradicted from there.
	 *
	 * @return void
	 */
	public function test_settings_context_is_filtered_as_well() {
		$result = $this->adapter->remove_post_types( array( 'product' => 'product' ), true );

		$this->assertSame( array(), $result );
	}

	/**
	 * Both filters are registered on init.
	 *
	 * @return void
	 */
	public function test_init_registers_both_filters() {
		$this->adapter->init();

		$this->assertTrue( has_filter( 'lmat_get_post_types' ) );
		$this->assertTrue( has_filter( 'lmat_get_taxonomies' ) );
	}
}
