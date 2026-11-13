<?php
/**
 * Covers the WooCommerce integration coordinator's hook registration.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Linguator_WooCommerce;
use Linguator\Integrations\woocommerce\Slug_Store;

class Coordinator_Test extends TestCase {

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
	}

	/**
	 * The activation guard is registered whether or not shared mode is on, because it is what
	 * decides that shared mode may be turned on in the first place.
	 *
	 * @return void
	 */
	public function test_guard_is_always_registered() {
		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'lmat_wc_shared_identity_guard' ) );
	}

	/**
	 * With shared mode off, the switcher fix is registered and nothing else is.
	 *
	 * @return void
	 */
	public function test_shared_mode_off_registers_only_the_switcher_fix() {
		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'lmat_pre_translation_url' ) );
		$this->assertFalse( has_filter( 'lmat_get_post_types' ) );
		$this->assertFalse( has_filter( 'woocommerce_product_get_name' ) );
	}

	/**
	 * With shared mode on, the product name getter is filtered: this is the on/off half of the
	 * guard, paired with the absence assertion in test_shared_mode_off_registers_only_the_switcher_fix().
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_frontend() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'woocommerce_product_get_name' ) );
	}

	/**
	 * With shared mode on, the identity filters are registered.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_identity_filters() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'lmat_get_post_types' ) );
		$this->assertTrue( has_filter( 'lmat_get_taxonomies' ) );
	}

	/**
	 * With shared mode on, the localization context gate is wired in too: Context_Gate::init()
	 * registers `woocommerce_email_header`, and a regression that dropped `$this->gate->init()`
	 * from register_shared_mode() would defeat email-context suspension silently.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_context_gate() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'woocommerce_email_header' ) );
	}

	/**
	 * With shared mode on, attribute label translation is wired in too: a regression that dropped
	 * `$this->attributes->init()` from register_shared_mode() would defeat variation attribute
	 * localization silently.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_attribute_translations() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'woocommerce_attribute_label' ) );
	}

	/**
	 * With shared mode on, the variation payload is wired in too: a regression that dropped
	 * `$this->variations->init()` from register_shared_mode() would defeat variation description
	 * localization silently.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_variations() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'woocommerce_available_variation' ) );
	}

	/**
	 * With shared mode on, cart and catalogue loop priming is wired in too: a regression that dropped
	 * `$this->cart->init()` from register_shared_mode() would defeat meta cache priming silently.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_cart() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'woocommerce_cart_loaded_from_session' ) );
	}

	/**
	 * With shared mode on, order creation hooks are wired in too: a regression that dropped
	 * `$this->orders->init()` from register_shared_mode() would defeat order language freezing silently.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_orders() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'woocommerce_checkout_create_order' ) );
	}

	/**
	 * With shared mode on, term label translation is wired in too: a regression that dropped
	 * `$this->term_translations->init()` from register_shared_mode() would defeat product category,
	 * tag and brand label localization silently.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_term_translations() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'get_term' ) );
	}

	/**
	 * With shared mode on, language switching on products and product term archives is wired in too:
	 * a regression that dropped the product_translation_url and term_archive_translation_url handlers
	 * from register_shared_mode() would defeat language switching and hreflang on shared objects silently.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_object_translation_url_handlers() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'lmat_pre_translation_url', array( $integration, 'product_translation_url' ) ) );
		$this->assertTrue( has_filter( 'lmat_pre_translation_url', array( $integration, 'term_archive_translation_url' ) ) );
	}

	/**
	 * With shared mode on, the canonical redirect guard is wired in too: a regression that dropped
	 * `keep_shared_object_url_from_redirecting()` from register_shared_mode() would silently bring
	 * back core's 301 away from every language-prefixed shared product and term archive URL.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_canonical_redirect_guard() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'redirect_canonical', array( $integration, 'keep_shared_object_url_from_redirecting' ) ) );
	}

	/**
	 * With shared mode on, translated SEO meta is wired in too: a regression that dropped
	 * `$this->seo->init()` from register_shared_mode() would defeat translated titles and meta
	 * descriptions on shared products and product term archives silently.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_seo() {
		define( 'SEOPRESS_VERSION', '8.0' );
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'seopress_titles_title' ) );
	}

	/**
	 * With shared mode on and the request in the admin, the entry panel is wired in too: a
	 * regression that dropped `$this->product_panel->init()` from register_shared_mode() would
	 * defeat the only way a human can enter a translation, silently.
	 *
	 * @return void
	 */
	public function test_shared_mode_on_registers_the_product_panel() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );
		lmat_test_set( 'is_admin', true );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'add_meta_boxes' ) );
		$this->assertTrue( has_filter( 'admin_menu' ) );
		$this->assertTrue( has_filter( 'edited_term' ) );
	}

	/**
	 * Term slugs are released on every request, but the screens exist only in the admin: a term is
	 * also deleted over REST.
	 *
	 * @return void
	 */
	public function test_shared_mode_releases_term_slugs_outside_the_admin_without_the_screens() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		$this->assertTrue( has_filter( 'delete_term' ) );
		$this->assertFalse( has_filter( 'edited_term' ) );
		$this->assertFalse( has_filter( 'admin_menu' ) );
	}

	/**
	 * Boots shared mode with product 5 holding a German slug.
	 *
	 * @return void
	 */
	protected function boot_with_slugged_product() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );
		lmat_test_set( 'post_types', array( 5 => 'product', 7 => 'page' ) );

		$integration = new Linguator_WooCommerce();
		$integration->init();

		Slug_Store::set( 5, 'de', 'beitrag' );
		Slug_Store::set( 7, 'de', 'seite' );
	}

	/**
	 * Permanently deleting a product releases its translated slugs, so another product can take them.
	 *
	 * @return void
	 */
	public function test_permanent_delete_releases_the_product_slug() {
		$this->boot_with_slugged_product();

		$this->assertInstanceOf( WP_Error::class, Slug_Store::set( 6, 'de', 'beitrag' ) );

		do_action( 'before_delete_post', 5 );

		$this->assertSame( '', Slug_Store::get( 5, 'de' ) );
		$this->assertTrue( Slug_Store::set( 6, 'de', 'beitrag' ) );
	}

	/**
	 * Only products release slugs: another post type is left alone.
	 *
	 * @return void
	 */
	public function test_deleting_another_post_type_releases_nothing() {
		$this->boot_with_slugged_product();

		do_action( 'before_delete_post', 7 );

		$this->assertSame( 'seite', Slug_Store::get( 7, 'de' ) );
	}

	/**
	 * Trashing or unpublishing keeps the slug reserved, so a restore cannot collide.
	 *
	 * @return void
	 */
	public function test_trashing_a_product_keeps_its_slug_reserved() {
		$this->boot_with_slugged_product();

		do_action( 'wp_trash_post', 5 );
		do_action( 'trashed_post', 5 );
		do_action( 'transition_post_status', 'trash', 'publish', new Lmat_Test_Post( 5 ) );
		do_action( 'transition_post_status', 'draft', 'publish', new Lmat_Test_Post( 5 ) );

		$this->assertSame( 'beitrag', Slug_Store::get( 5, 'de' ) );
		$this->assertInstanceOf( WP_Error::class, Slug_Store::set( 6, 'de', 'beitrag' ) );
	}
}
