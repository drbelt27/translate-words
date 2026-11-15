<?php
/**
 * Covers cart and checkout line text, and meta cache priming.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Attribute_Translations;
use Linguator\Integrations\woocommerce\Cart;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Cart_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The adapter under test.
	 *
	 * @var Cart
	 */
	protected $cart;

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
		$this->cart       = new Cart( $this->repository );
	}

	/**
	 * The cart primes the meta cache once for every product and variation it holds.
	 *
	 * @return void
	 */
	public function test_cart_primes_its_products() {
		lmat_test_set(
			'cart_items',
			array(
				'key1' => array( 'product_id' => 42, 'variation_id' => 77 ),
				'key2' => array( 'product_id' => 43, 'variation_id' => 0 ),
			)
		);

		$this->cart->prime_cart();

		$this->assertSame( array( 42, 77, 43 ), lmat_test_get( 'primed_posts' ) );
	}

	/**
	 * A catalogue loop primes its whole result set and returns it unchanged.
	 *
	 * @return void
	 */
	public function test_loop_primes_and_passes_through() {
		$posts  = array( new Lmat_Test_Post( 101 ), new Lmat_Test_Post( 102 ) );
		$result = $this->cart->prime_loop( $posts );

		$this->assertSame( $posts, $result );
		$this->assertSame( array( 101, 102 ), lmat_test_get( 'primed_posts' ) );
	}

	/**
	 * An empty result set primes nothing rather than priming an empty ID list.
	 *
	 * @return void
	 */
	public function test_empty_loop_primes_nothing() {
		$this->cart->prime_loop( array() );

		$this->assertSame( array(), lmat_test_get( 'primed_posts' ) );
	}

	/**
	 * Driven through the real filter chain, a German cart line reads German.
	 *
	 * This is the registration test: it proves the hooks are wired, not merely that the methods
	 * return the right string when called by hand.
	 *
	 * @return void
	 */
	public function test_cart_line_reads_german_through_the_filter_chain() {
		lmat_test_set( 'curlang', 'de' );

		$this->repository->save(
			42,
			'de',
			array(
				Attribute_Translations::label_field( 'Taglia' )            => array( 'value' => 'Größe', 'source' => 'Taglia' ),
				Attribute_Translations::option_field( 'Taglia', 'Grande' ) => array( 'value' => 'Groß', 'source' => 'Grande' ),
			),
			0
		);

		$gate     = new Context_Gate();
		$frontend = new Frontend( $this->repository, $gate );
		$adapter  = new Attribute_Translations( $this->repository, $gate, $frontend );
		$adapter->init();

		$product = new Lmat_Test_Product( 42, 'variable' );

		$this->assertSame( 'Größe', apply_filters( 'woocommerce_attribute_label', 'Taglia', 'Taglia', $product ) );
		$this->assertSame( 'Groß', apply_filters( 'woocommerce_variation_option_name', 'Grande', null, 'pa_taglia', $product ) );
	}

	/**
	 * Both priming hooks are registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_priming_hooks() {
		$this->cart->init();

		$this->assertTrue( has_filter( 'the_posts' ) );
		$this->assertTrue( has_filter( 'woocommerce_cart_loaded_from_session' ) );
	}
}
