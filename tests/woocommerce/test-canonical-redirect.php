<?php
/**
 * Covers WordPress core's canonical redirect being cancelled for shared products and shared
 * product taxonomy archives requested under a language prefix.
 *
 * A `/de/<product>/` request for a shared product resolves fine (Task 6 took `product` out of
 * translate-by-duplication, so Linguator's own canonical check already stands aside for it), but
 * `get_permalink()` for a shared product never carries a language prefix. WordPress core's own
 * `redirect_canonical()` then disagrees with the requested URL and 301s the visitor back to the
 * bare, default-language permalink. This is what a live clone's runtime milestone found: the
 * language-prefixed URLs Task 13's switcher and hreflang advertise do not resolve.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Linguator_WooCommerce;

class Canonical_Redirect_Test extends TestCase {

	/**
	 * The integration under test.
	 *
	 * @var Linguator_WooCommerce
	 */
	protected $integration;

	/**
	 * Resets the simulated request before each test and turns shared mode on, since every handler
	 * under test is a shared-mode behaviour.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );
		$this->integration = new Linguator_WooCommerce();
	}

	/**
	 * The core scenario the runtime milestone found: a shared product requested under a language
	 * prefix must not be redirected away from that prefix. Without this fix, core's own canonical
	 * check would send every visitor following a `/de/...` link (including Task 13's own hreflang
	 * alternates) straight back to the bare, Italian URL.
	 *
	 * @return void
	 */
	public function test_shared_product_under_a_language_prefix_is_not_redirected() {
		lmat_test_set( 'is_product', true );

		$result = $this->integration->keep_shared_object_url_from_redirecting(
			'https://example.test/prodotto/tassa-di-socio/',
			'https://example.test/de/prodotto/tassa-di-socio/'
		);

		$this->assertFalse( $result );
	}

	/**
	 * With shared mode off, this integration must change nothing: the redirect core computed is
	 * left exactly as it is, because a site not using shared product identity has no shared product
	 * or shared taxonomy whose canonical URL this handler has any business overriding.
	 *
	 * @return void
	 */
	public function test_shared_mode_off_leaves_the_redirect_untouched() {
		lmat_test_set( 'options', array() );
		lmat_test_set( 'is_product', true );

		$result = $this->integration->keep_shared_object_url_from_redirecting(
			'https://example.test/prodotto/tassa-di-socio/',
			'https://example.test/de/prodotto/tassa-di-socio/'
		);

		$this->assertSame( 'https://example.test/prodotto/tassa-di-socio/', $result );
	}

	/**
	 * A request that is not a product and not a shared product taxonomy archive at all (an
	 * ordinary post, page, or anything else core's redirect_canonical() legitimately corrects)
	 * must never have its redirect cancelled by this handler: that guard is what keeps this fix
	 * from suppressing redirects across the rest of the site.
	 *
	 * @return void
	 */
	public function test_a_request_that_is_not_a_product_is_left_alone() {
		// Neither is_product() nor is_tax() report true: this is some other kind of request.
		$result = $this->integration->keep_shared_object_url_from_redirecting(
			'https://example.test/somewhere-else/',
			'https://example.test/de/somewhere-else/'
		);

		$this->assertSame( 'https://example.test/somewhere-else/', $result );
	}

	/**
	 * A path segment that looks like a language prefix but names no language Linguator actually
	 * knows must not have its redirect cancelled: that would suppress a legitimate correction for
	 * an arbitrary malformed URL, which is exactly the over-broad behaviour the brief warns against.
	 *
	 * @return void
	 */
	public function test_an_unknown_language_prefix_is_left_alone() {
		lmat_test_set( 'is_product', true );

		$result = $this->integration->keep_shared_object_url_from_redirecting(
			'https://example.test/prodotto/tassa-di-socio/',
			'https://example.test/xx/prodotto/tassa-di-socio/'
		);

		$this->assertSame( 'https://example.test/prodotto/tassa-di-socio/', $result );
	}

	/**
	 * A shared product taxonomy archive (product_cat, product_tag, product_brand) requested under a
	 * language prefix must resolve exactly like a shared product: Task 13 advertises hreflang
	 * alternates for these archives too, so they carry the same redirect risk.
	 *
	 * @return void
	 */
	public function test_shared_product_taxonomy_archive_under_a_language_prefix_is_not_redirected() {
		lmat_test_set( 'is_product_tax', 'product_cat' );

		$result = $this->integration->keep_shared_object_url_from_redirecting(
			'https://example.test/categoria-prodotto/abbigliamento/',
			'https://example.test/de/categoria-prodotto/abbigliamento/'
		);

		$this->assertFalse( $result );
	}

	/**
	 * A request with no language prefix at all (the default-language visit that reaches this
	 * product's bare permalink directly) is not this handler's concern: with no prefix, there is
	 * nothing for known_language_prefix() to find, so core's own logic is left in charge.
	 *
	 * @return void
	 */
	public function test_a_request_with_no_language_prefix_is_left_alone() {
		lmat_test_set( 'is_product', true );

		$result = $this->integration->keep_shared_object_url_from_redirecting(
			'https://example.test/prodotto/tassa-di-socio/',
			'https://example.test/prodotto/tassa-di-socio/?utm_source=x'
		);

		$this->assertSame( 'https://example.test/prodotto/tassa-di-socio/', $result );
	}

	/**
	 * A missing Linguator instance never fatals and never suppresses a redirect it cannot validate
	 * the language prefix against.
	 *
	 * @return void
	 */
	public function test_returns_untouched_redirect_when_linguator_is_unavailable() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'linguator', false );

		$result = $this->integration->keep_shared_object_url_from_redirecting(
			'https://example.test/prodotto/tassa-di-socio/',
			'https://example.test/de/prodotto/tassa-di-socio/'
		);

		$this->assertSame( 'https://example.test/prodotto/tassa-di-socio/', $result );
	}
}
