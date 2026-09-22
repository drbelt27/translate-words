<?php
/**
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Manages the compatibility with WooCommerce.
 *
 * WooCommerce serves the configured shop page as the `product` post type archive, so Linguator
 * never reaches the `is_page()` branch of Linguator_Frontend_Links::get_translation_url() for it
 * and the language switcher falls back to the home page. This integration resolves the translation
 * of the shop page itself, which is an ordinary translated page.
 *
 * @since 2.2.1
 */
class Linguator_WooCommerce {

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository|null
	 */
	protected $repository = null;

	/**
	 * The localization context gate.
	 *
	 * @since 2.3.0
	 *
	 * @var Context_Gate|null
	 */
	protected $gate = null;

	/**
	 * The adapter that takes products and product taxonomies out of translate-by-duplication.
	 *
	 * @since 2.3.0
	 *
	 * @var Translated_Object_Types|null
	 */
	protected $translated_object_types = null;

	/**
	 * Localized product text.
	 *
	 * @since 2.3.0
	 *
	 * @var Frontend|null
	 */
	protected $frontend = null;

	/**
	 * Translated attribute labels and option labels.
	 *
	 * @since 2.3.0
	 *
	 * @var Attribute_Translations|null
	 */
	protected $attributes = null;

	/**
	 * Localized variation payload text.
	 *
	 * @since 2.3.0
	 *
	 * @var Variations|null
	 */
	protected $variations = null;

	/**
	 * Cart and catalogue loop priming.
	 *
	 * @since 2.3.0
	 *
	 * @var Cart|null
	 */
	protected $cart = null;

	/**
	 * Purchase language and text frozen at order creation.
	 *
	 * @since 2.3.0
	 *
	 * @var Orders|null
	 */
	protected $orders = null;

	/**
	 * Translated product category, tag and brand labels.
	 *
	 * @since 2.3.0
	 *
	 * @var Term_Translations|null
	 */
	protected $term_translations = null;

	/**
	 * Translated SEO meta served to the active SEO plugin.
	 *
	 * @since 2.3.0
	 *
	 * @var Seo|null
	 */
	protected $seo = null;

	/**
	 * The admin-side entry panel for translated product text.
	 *
	 * @since 2.3.0
	 *
	 * @var Admin\Product_Panel|null
	 */
	protected $product_panel = null;

	/**
	 * Setups filters.
	 *
	 * Runs on `lmat_init`, which fires inside Linguator's own `plugins_loaded` priority 1 boot.
	 * That is early enough for `lmat_get_post_types`: Linguator caches the post type list only
	 * once `after_setup_theme` has fired, which is after `plugins_loaded` has completed.
	 *
	 * @since 2.2.1
	 *
	 * @return void
	 */
	public function init() {
		// Priority 15: after the core handler of Linguator_Frontend_Static_Pages (10) and before its customizer override (20).
		add_filter( 'lmat_pre_translation_url', array( $this, 'shop_translation_url' ), 15, 2 );

		// Always registered: this is what decides whether shared mode may be switched on at all.
		add_filter( 'lmat_wc_shared_identity_guard', array( Shared_Mode::class, 'guard' ), 10, 1 );

		if ( ! Shared_Mode::is_enabled() ) {
			return; // Everything below changes behaviour; with shared mode off the integration does only what it did before.
		}

		$this->register_shared_mode();
	}

	/**
	 * Registers the adapters that shared product identity needs.
	 *
	 * Each adapter is added here in its own task; the method exists from the start so that the
	 * gate between "shared mode off" and "shared mode on" has exactly one home.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	protected function register_shared_mode() {
		$this->repository = new Translation_Repository();

		add_filter( 'woocommerce_rest_prepare_product_object', array( $this->repository, 'filter_rest_meta' ), 10, 3 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this->repository, 'filter_rest_meta' ), 10, 3 );

		$this->gate = new Context_Gate();
		$this->gate->init();

		$this->translated_object_types = new Translated_Object_Types();
		$this->translated_object_types->init();

		$this->frontend = new Frontend( $this->repository, $this->gate );
		$this->frontend->init();

		$this->attributes = new Attribute_Translations( $this->repository, $this->gate, $this->frontend );
		$this->attributes->init();

		$this->variations = new Variations( $this->repository, $this->gate, $this->frontend );
		$this->variations->init();

		$this->cart = new Cart( $this->repository );
		$this->cart->init();

		$this->orders = new Orders( $this->repository, $this->gate, $this->frontend );
		$this->orders->init();

		$this->term_translations = new Term_Translations( $this->repository, $this->gate, $this->frontend );
		$this->term_translations->init();

		$this->seo = new Seo( $this->repository, $this->gate, $this->frontend );
		$this->seo->init();

		if ( is_admin() ) {
			$this->product_panel = new Admin\Product_Panel( $this->repository );
			$this->product_panel->init();
		}

		// Same priority as the shop handler: each stands aside when another has already resolved a URL.
		add_filter( 'lmat_pre_translation_url', array( $this, 'product_translation_url' ), 15, 2 );
		add_filter( 'lmat_pre_translation_url', array( $this, 'term_archive_translation_url' ), 15, 2 );

		// A shared product has no language-prefixed permalink of its own (see product_translation_url()
		// above), so WordPress core's own canonical check disagrees with the language-prefixed URL this
		// integration advertises through the switcher and hreflang, and 301s it away. Cancel exactly that
		// redirect; every other reason redirect_canonical() might fire is left alone.
		add_filter( 'redirect_canonical', array( $this, 'keep_shared_object_url_from_redirecting' ), 10, 2 );
	}

	/**
	 * Returns the URL of the translated shop page when the shop archive is displayed.
	 *
	 * The shop page is served as the `product` post type archive, so Linguator cannot reach its
	 * page translation on its own. Everything else, including the translated shop pages themselves,
	 * is left to Linguator's standard resolution.
	 *
	 * @since 2.2.1
	 *
	 * @param string $url      An empty string or the URL of the translation of the current page.
	 * @param object $language The language of the translation.
	 * @return string The translated shop page URL, or $url unchanged when this integration does not apply.
	 */
	public function shop_translation_url( $url, $language ) {
		if ( ! empty( $url ) ) {
			return $url; // Another handler already resolved the translation URL.
		}

		if ( ! is_object( $language ) || empty( $language->slug ) ) {
			return $url;
		}

		if ( ! $this->is_shop_archive() ) {
			return $url;
		}

		$shop_id = $this->get_shop_page_id();

		if ( $shop_id <= 0 ) {
			return $url;
		}

		if ( ! function_exists( 'linguator_get_post' ) ) {
			return $url;
		}

		$translated_id = linguator_get_post( $shop_id, $language->slug );
		$translated_id = is_numeric( $translated_id ) ? (int) $translated_id : 0;

		if ( $translated_id <= 0 || ! $this->can_read( $translated_id ) ) {
			return $url;
		}

		$permalink = get_permalink( $translated_id );

		return is_string( $permalink ) && '' !== $permalink ? $permalink : $url;
	}

	/**
	 * Returns the URL of the current product in another language.
	 *
	 * A shared product has no translation post, so Linguator's own `is_single()` branch — which
	 * resolves through `$this->model->post->get( $id, $language )` — cannot find one and the
	 * switcher falls back to the home page. The product is the same object in every language, so
	 * its URL is its canonical permalink carrying the other language's prefix.
	 *
	 * The alternates loop is built from the same resolution, so this restores `hreflang` on product
	 * pages as a by-product: without it, only the self link resolves and nothing is printed.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url      An empty string or the URL of the translation of the current page.
	 * @param object $language The language of the translation.
	 * @return string
	 */
	public function product_translation_url( $url, $language ) {
		if ( ! empty( $url ) || ! $this->can_resolve_shared_url( $language ) ) {
			return $url;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $url;
		}

		$product_id = (int) get_queried_object_id();

		if ( $product_id <= 0 ) {
			return $url;
		}

		$permalink = get_permalink( $product_id );

		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return $url;
		}

		return $this->switch_language_in_url( $permalink, $language, $url );
	}

	/**
	 * Returns the URL of the current product term archive in another language.
	 *
	 * Making `product_cat` shared takes it out of `is_translated_taxonomy()`, which disables
	 * Linguator's `is_tax()` branch. The generic archive branch then counts posts by language and
	 * finds none, because products no longer carry one, so the URL is suppressed and the visitor
	 * lands on the home page.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url      An empty string or the URL of the translation of the current page.
	 * @param object $language The language of the translation.
	 * @return string
	 */
	public function term_archive_translation_url( $url, $language ) {
		if ( ! empty( $url ) || ! $this->can_resolve_shared_url( $language ) ) {
			return $url;
		}

		if ( ! function_exists( 'is_tax' ) || ! is_tax( Term_Translations::TAXONOMIES ) ) {
			return $url;
		}

		$term = get_queried_object();

		if ( ! $term instanceof WP_Term ) {
			return $url;
		}

		$link = get_term_link( $term, $term->taxonomy );

		if ( ! is_string( $link ) || '' === $link ) {
			return $url;
		}

		return $this->switch_language_in_url( $link, $language, $url );
	}

	/**
	 * Tells whether the shared-object URL handlers may act.
	 *
	 * @since 2.3.0
	 *
	 * @param object $language The language of the translation.
	 * @return bool
	 */
	protected function can_resolve_shared_url( $language ) {
		return is_object( $language ) && ! empty( $language->slug ) && Shared_Mode::is_enabled();
	}

	/**
	 * Cancels WordPress core's canonical redirect for a shared product or shared product taxonomy
	 * archive requested under a language prefix.
	 *
	 * A shared product's own permalink (`get_permalink()`) never carries a language prefix, because
	 * `product` stopped being a translated post type in Task 6. Requesting the language-prefixed URL
	 * this integration itself advertises (via product_translation_url(), term_archive_translation_url()
	 * and the resulting hreflang alternates) still resolves to that same product or term archive, but
	 * WordPress core's `redirect_canonical()` (wp-includes/canonical.php) disagrees with the requested
	 * URL and 301s the visitor back to the bare permalink, in the default language.
	 *
	 * This does not touch `redirect_canonical()`'s many other jobs: www/non-www normalization, feed and
	 * paging cleanup, attachment redirects, trailing slash and date archive corrections, and 404 guessing
	 * for everything else are all untouched, because the guard below only fires for a product or a shared
	 * product taxonomy archive requested under a prefix Linguator itself recognizes as a language.
	 *
	 * @since 2.3.0
	 *
	 * @param string|false $redirect_url  The redirect URL core computed, or false when it found none.
	 * @param string       $requested_url The originally requested URL.
	 * @return string|false $redirect_url unchanged, or false to cancel the redirect.
	 */
	public function keep_shared_object_url_from_redirecting( $redirect_url, $requested_url ) {
		if ( ! Shared_Mode::is_enabled() ) {
			return $redirect_url;
		}

		if ( ! $this->is_shared_object_request() ) {
			return $redirect_url;
		}

		if ( '' === $this->known_language_prefix( (string) $requested_url ) ) {
			return $redirect_url;
		}

		return false;
	}

	/**
	 * Tells whether the current request displays a shared product or a shared product taxonomy archive.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	protected function is_shared_object_request() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return true;
		}

		return function_exists( 'is_tax' ) && is_tax( Term_Translations::TAXONOMIES );
	}

	/**
	 * Returns the language slug named by a URL's language prefix.
	 *
	 * Delegates to Linguator's own links model rather than parsing the URL itself, so this always
	 * agrees with whatever Linguator considers a known language, in whichever URL mode is active.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url The URL to read the language prefix from.
	 * @return string The language slug, or an empty string when the URL names no language Linguator knows.
	 */
	protected function known_language_prefix( $url ) {
		if ( ! function_exists( 'LMAT' ) ) {
			return '';
		}

		$linguator = LMAT();

		if ( ! is_object( $linguator ) || ! isset( $linguator->links_model ) || ! method_exists( $linguator->links_model, 'get_language_from_url' ) ) {
			return '';
		}

		return (string) $linguator->links_model->get_language_from_url( $url );
	}

	/**
	 * Rewrites the language segment of a canonical URL.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url      The canonical URL.
	 * @param object $language The target language.
	 * @param string $fallback Value to return when Linguator's links model is unavailable.
	 * @return string
	 */
	protected function switch_language_in_url( $url, $language, $fallback ) {
		if ( ! function_exists( 'LMAT' ) ) {
			return $fallback;
		}

		$linguator = LMAT();

		if ( ! is_object( $linguator ) || ! isset( $linguator->links_model ) || ! method_exists( $linguator->links_model, 'switch_language_in_link' ) ) {
			return $fallback;
		}

		return (string) $linguator->links_model->switch_language_in_link( $url, $language );
	}

	/**
	 * Tells whether the current request displays the WooCommerce shop archive.
	 *
	 * @since 2.2.1
	 *
	 * @return bool
	 */
	protected function is_shop_archive() {
		if ( ! function_exists( 'is_shop' ) ) {
			return false; // WooCommerce is not active.
		}

		if ( is_front_page() ) {
			return false; // Linguator already resolves the home URL of each language.
		}

		return (bool) is_shop();
	}

	/**
	 * Returns the ID of the page configured as the WooCommerce shop.
	 *
	 * @since 2.2.1
	 *
	 * @return int The page ID, 0 when it cannot be determined.
	 */
	protected function get_shop_page_id() {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return 0;
		}

		$shop_id = (int) wc_get_page_id( 'shop' ); // WooCommerce returns -1 when the option is unset.

		return $shop_id > 0 ? $shop_id : 0;
	}

	/**
	 * Tells whether the current user can read the translated page.
	 *
	 * Mirrors the check Linguator applies to ordinary pages in Linguator_Frontend_Links::get_translation_url().
	 *
	 * @since 2.2.1
	 *
	 * @param int $post_id Translated page ID.
	 * @return bool
	 */
	protected function can_read( $post_id ) {
		if ( ! function_exists( 'LMAT' ) ) {
			return false;
		}

		$linguator = LMAT();

		if ( ! is_object( $linguator ) || ! isset( $linguator->model->post ) ) {
			return false;
		}

		return (bool) $linguator->model->post->current_user_can_read( $post_id );
	}
}
