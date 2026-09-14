# Linguator WooCommerce Shop Language Switcher Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Linguator's language switcher link the WooCommerce shop archive to the translated shop page instead of falling back to the target-language home page, generically for any configured shop page and any configured language.

**Architecture:** A new optional integration `integrations/woocommerce/`, loaded only when WooCommerce is active, hooks the existing public filter `lmat_pre_translation_url`. It resolves the shop page's own Linguator page translation and returns its permalink. Core frontend services are not modified, so every switcher (Elementor widget, block, widget, nav menu) and the `hreflang` output are corrected through the single shared entry point `Linguator_Frontend_Links::get_translation_url()`.

**Tech Stack:** PHP 7.2+ (runtime floor from `translate-wp-words.php`), WordPress 6.8+, WooCommerce (optional), PHPUnit 9 for isolated unit tests with hand-written WordPress/WooCommerce stubs.

**Spec:** `docs/superpowers/root-cause/2026-09-12-woocommerce-shop-switcher.md` (verified root cause and the required behaviour), plus the task brief recorded in this document's "Requirements" section.

## Global Constraints

- Text domain: `translate-words`. Plugin version floor: `Requires at least: 6.8`, `Requires PHP: 7.2`. Do not raise either.
- All code, comments, identifiers and documentation in English. All files use LF line endings (`.gitattributes`).
- Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- WordPress formatting: tabs for indentation, spaces inside parentheses, opening braces on the same line, PHPDoc with `@since` / `@param` / `@return` on every function.
- No hardcoded site data anywhere in the plugin: no page IDs (`24619`, `27384`), no slugs (`/shop/`, `/de/shop-2/`), no language codes (`it`, `de`), no reference to `beltracing.ch`.
- The plugin must not fatal or change behaviour when WooCommerce is absent or inactive.
- Do not modify products, stock, orders, CRM data, menus, canonical URLs or SEO metadata.
- Code Snippets snippet 17 on the production site stays **active** for the whole implementation. It is disabled only during the controlled verification in Task 6 and left disabled (not deleted) afterwards.
- `integrations/integration-build.php` declares itself auto-generated. No generator exists in this repository (the only reference is the runtime `require` at `integrations/integrations.php:137`). The new entry is added by hand in the position a directory scan would produce, and this is called out in the commit message.

## Requirements (from the task brief)

| # | Scenario | Required behaviour |
| --- | --- | --- |
| 1 | WooCommerce active, `is_shop()`, translation available | return the translated shop page permalink |
| 2 | WooCommerce active, `is_shop()`, no translation in target language | keep Linguator's standard fallback |
| 3 | Ordinary WordPress page | no regression |
| 4 | Ordinary post | no regression |
| 5 | Other post type archives | no regression |
| 6 | WooCommerce not installed/active | no fatal error |
| 7 | Invalid shop page ID | safe fallback |
| 8 | Invalid translation mapping | safe fallback |
| 9 | Elementor language switcher | corrected through existing APIs, no Elementor-specific patch |
| 10 | Reverse switch (shop language B to shop language A) | works |

## File Structure

| File | Status | Responsibility |
| --- | --- | --- |
| `integrations/woocommerce/load.php` | create | Guarded bootstrap: instantiate and hook the integration only when WooCommerce is active |
| `integrations/woocommerce/woocommerce.php` | create | `Linguator_WooCommerce`: resolve the shop archive's translation URL |
| `integrations/integration-build.php` | modify | Register the `woocommerce` integration directory |
| `integrations/integrations.php` | modify | Declare the `$woocommerce` container property, matching the other integrations |
| `phpunit.xml.dist` | create | PHPUnit configuration for the isolated unit suite |
| `tests/bootstrap.php` | create | State-driven WordPress/WooCommerce function stubs; no WordPress install required |
| `tests/woocommerce/test-shop-translation-url.php` | create | Unit coverage for scenarios 1, 2, 3, 4, 5, 7, 8, 9, 10 and the extra edge cases |
| `tests/woocommerce/test-without-woocommerce.php` | create | Unit coverage for scenario 6, run with WooCommerce stubs absent |
| `readme.txt` | modify | Changelog entry |
| `docs/superpowers/root-cause/2026-09-12-woocommerce-shop-switcher.md` | done | Verified root cause |

## Architectural decision

Four options were weighed against the root cause document.

1. **Extend `Linguator_Frontend_Links::get_translation_url()` with a shop branch.** Rejected: it puts WooCommerce knowledge into a core frontend service that must keep working without WooCommerce, and it contradicts the repository's own pattern of isolating third-party support under `integrations/`.
2. **Extend `Linguator_Frontend_Static_Pages`.** Rejected: that controller owns `page_on_front` / `page_for_posts` semantics only. Adding WooCommerce there mixes two unrelated responsibilities into a class that is always loaded.
3. **Intercept the shop archive during query resolution (for example by rewriting the `is_post_type_archive` handling).** Rejected: it changes behaviour for post type archives in general, which the brief explicitly forbids, and it risks side effects on queries, canonical redirects and caching.
4. **A dedicated WooCommerce integration hooking `lmat_pre_translation_url`.** **Chosen.** It is the documented public extension point; it is exactly where the core static-pages controller already solves the structurally identical `page_for_posts` problem; it loads only when WooCommerce is active, so there is no fatal risk and no cost otherwise; it introduces no circular dependency (the integration depends on Linguator and WooCommerce, neither depends on it); and it corrects every consumer of `get_translation_url()` at once, including `hreflang`.

**Hook priority 15.** `Linguator_Frontend_Static_Pages` registers handlers at priority 10 (front page / posts page) and 20 (customizer override). Priority 15 runs after the core resolution and before the customizer override. The callback additionally returns `$url` untouched whenever it is already non-empty, so it can never steal a URL another handler resolved.

**The direction asymmetry is intentional.** Only the language whose page is configured as the WooCommerce shop page is served as the product archive; every translated shop page is an ordinary page already handled by the `is_page()` branch at `frontend/services/frontend-links.php:87`. The integration therefore only has to fix the archive side, and the resulting behaviour is symmetric for the user in every language pair.

---

### Task 1: Isolated unit test harness

**Files:**
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap.php`
- Create: `tests/woocommerce/test-harness.php`
- Create: `integrations/woocommerce/woocommerce.php` (skeleton only)

**Interfaces:**
- Consumes: nothing.
- Produces: `lmat_test_reset_state()`, `lmat_test_set( string $key, $value ): void`, `lmat_test_get( string $key, $default = null )`, and stubs for `is_front_page()`, `get_permalink()`, `linguator_get_post()`, `LMAT()`, `add_filter()`, `add_action()`, plus `is_shop()` and `wc_get_page_id()` which are defined only when the environment variable `LMAT_TEST_WITHOUT_WOOCOMMERCE` is unset.

The repository has no test suite: `composer.json` declares `"test": "phpunit"` but no PHPUnit binary is installed and `npm test` is a placeholder. These tests deliberately do **not** load WordPress: the unit under test is guarded plugin logic, so state-driven stubs are sufficient, fast and runnable offline. Download the PHPUnit PHAR once into the scratchpad; do not add it to the repository and do not run `composer install`, which would rewrite committed `vendor/` files.

- [ ] **Step 1: Fetch the PHPUnit PHAR into the scratchpad**

```bash
PHPUNIT="$SCRATCH/phpunit-9.phar"
curl -sL -o "$PHPUNIT" https://phar.phpunit.de/phpunit-9.phar
php "$PHPUNIT" --version
```

Expected: prints `PHPUnit 9.x.y by Sebastian Bergmann and contributors.`

- [ ] **Step 2: Write `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
	bootstrap="tests/bootstrap.php"
	colors="true"
	beStrictAboutTestsThatDoNotTestAnything="true"
	failOnWarning="true"
	failOnRisky="true">
	<testsuites>
		<testsuite name="linguator-unit">
			<directory prefix="test-" suffix=".php">tests</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 3: Write `tests/bootstrap.php`**

```php
<?php
/**
 * PHPUnit bootstrap for the Linguator isolated unit suite.
 *
 * WordPress and WooCommerce are not loaded. The stubs below are driven by a single
 * global state array so that each test can describe the exact request it simulates.
 *
 * Set the environment variable LMAT_TEST_WITHOUT_WOOCOMMERCE to run the suite with the
 * WooCommerce stubs undefined, which reproduces a site where WooCommerce is not active.
 *
 * @package Linguator
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['lmat_test_state'] = array();

/**
 * Resets the simulated request state.
 *
 * @since 2.2.1
 *
 * @return void
 */
function lmat_test_reset_state() {
	$GLOBALS['lmat_test_state'] = array(
		'is_shop'        => false,
		'is_front_page'  => false,
		'shop_page_id'   => -1,
		'translations'   => array(),
		'permalinks'     => array(),
		'unreadable_ids' => array(),
		'linguator'      => true,
		'hooks'          => array(),
	);
}

/**
 * Sets one simulated request value.
 *
 * @since 2.2.1
 *
 * @param string $key   State key.
 * @param mixed  $value State value.
 * @return void
 */
function lmat_test_set( $key, $value ) {
	$GLOBALS['lmat_test_state'][ $key ] = $value;
}

/**
 * Reads one simulated request value.
 *
 * @since 2.2.1
 *
 * @param string $key     State key.
 * @param mixed  $default Value returned when the key is not set.
 * @return mixed
 */
function lmat_test_get( $key, $default = null ) {
	return array_key_exists( $key, $GLOBALS['lmat_test_state'] ) ? $GLOBALS['lmat_test_state'][ $key ] : $default;
}

lmat_test_reset_state();

/**
 * Records a filter registration.
 *
 * @since 2.2.1
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Number of accepted arguments.
 * @return true
 */
function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['lmat_test_state']['hooks'][] = array(
		'hook'          => $hook_name,
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);

	return true;
}

/**
 * Records an action registration.
 *
 * @since 2.2.1
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Number of accepted arguments.
 * @return true
 */
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

/**
 * Simulates WordPress's is_front_page().
 *
 * @since 2.2.1
 *
 * @return bool
 */
function is_front_page() {
	return (bool) lmat_test_get( 'is_front_page', false );
}

/**
 * Simulates WordPress's get_permalink().
 *
 * @since 2.2.1
 *
 * @param int $post_id Post ID.
 * @return string|false
 */
function get_permalink( $post_id = 0 ) {
	$permalinks = (array) lmat_test_get( 'permalinks', array() );

	return array_key_exists( (int) $post_id, $permalinks ) ? $permalinks[ (int) $post_id ] : false;
}

/**
 * Simulates Linguator's translation lookup.
 *
 * @since 2.2.1
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Language slug.
 * @return mixed
 */
function linguator_get_post( $post_id, $lang = '' ) {
	$translations = (array) lmat_test_get( 'translations', array() );
	$key          = (int) $post_id . ':' . $lang;

	return array_key_exists( $key, $translations ) ? $translations[ $key ] : 0;
}

/**
 * Post model stub exposing the capability check used by the integration.
 *
 * @since 2.2.1
 */
class Lmat_Test_Post_Model {
	/**
	 * Tells whether the current user can read the post.
	 *
	 * @since 2.2.1
	 *
	 * @param int    $id      Post ID.
	 * @param string $context Unused, kept for signature parity.
	 * @return bool
	 */
	public function current_user_can_read( $id, $context = 'view' ) {
		return ! in_array( (int) $id, (array) lmat_test_get( 'unreadable_ids', array() ), true );
	}
}

/**
 * Model stub.
 *
 * @since 2.2.1
 */
class Lmat_Test_Model {
	/**
	 * Post model.
	 *
	 * @var Lmat_Test_Post_Model
	 */
	public $post;

	/**
	 * Constructor.
	 *
	 * @since 2.2.1
	 */
	public function __construct() {
		$this->post = new Lmat_Test_Post_Model();
	}
}

/**
 * Linguator instance stub.
 *
 * @since 2.2.1
 */
class Lmat_Test_Linguator {
	/**
	 * Model.
	 *
	 * @var Lmat_Test_Model
	 */
	public $model;

	/**
	 * Constructor.
	 *
	 * @since 2.2.1
	 */
	public function __construct() {
		$this->model = new Lmat_Test_Model();
	}
}

/**
 * Simulates the LMAT() accessor.
 *
 * @since 2.2.1
 *
 * @return Lmat_Test_Linguator|null
 */
function LMAT() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return lmat_test_get( 'linguator', true ) ? new Lmat_Test_Linguator() : null;
}

if ( ! getenv( 'LMAT_TEST_WITHOUT_WOOCOMMERCE' ) ) {
	/**
	 * Simulates WooCommerce's is_shop().
	 *
	 * @since 2.2.1
	 *
	 * @return bool
	 */
	function is_shop() {
		return (bool) lmat_test_get( 'is_shop', false );
	}

	/**
	 * Simulates WooCommerce's wc_get_page_id().
	 *
	 * @since 2.2.1
	 *
	 * @param string $page Page identifier.
	 * @return int
	 */
	function wc_get_page_id( $page ) {
		return 'shop' === $page ? (int) lmat_test_get( 'shop_page_id', -1 ) : -1;
	}
}

require_once dirname( __DIR__ ) . '/integrations/woocommerce/woocommerce.php';
```

- [ ] **Step 4: Write the harness smoke test `tests/woocommerce/test-harness.php`**

```php
<?php
/**
 * Verifies that the isolated unit harness itself behaves as the tests assume.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;

class Harness_Test extends TestCase {

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
	 * The stub state is readable and writable.
	 *
	 * @return void
	 */
	public function test_state_round_trip() {
		lmat_test_set( 'shop_page_id', 42 );

		$this->assertSame( 42, lmat_test_get( 'shop_page_id' ) );
	}

	/**
	 * Permalinks are resolved from the simulated state and fail closed.
	 *
	 * @return void
	 */
	public function test_permalink_stub() {
		lmat_test_set( 'permalinks', array( 7 => 'https://example.test/de/shop-page/' ) );

		$this->assertSame( 'https://example.test/de/shop-page/', get_permalink( 7 ) );
		$this->assertFalse( get_permalink( 8 ) );
	}
}
```

- [ ] **Step 5: Run the harness test and verify it fails**

Run: `php "$SCRATCH/phpunit-9.phar" --filter Harness_Test`
Expected: FAIL — `failed to open stream` for `integrations/woocommerce/woocommerce.php`, which does not exist yet.

- [ ] **Step 6: Create the integration class file so the bootstrap can load it**

Create `integrations/woocommerce/woocommerce.php` with the class skeleton only; the behaviour is added by TDD in Task 2.

```php
<?php
/**
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

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
}
```

- [ ] **Step 7: Run the harness test and verify it passes**

Run: `php "$SCRATCH/phpunit-9.phar" --filter Harness_Test`
Expected: PASS, 2 tests, 3 assertions.

- [ ] **Step 8: Commit**

```bash
git add phpunit.xml.dist tests/bootstrap.php tests/woocommerce/test-harness.php integrations/woocommerce/woocommerce.php
git commit -m "test: add isolated PHPUnit harness with WordPress and WooCommerce stubs"
```

---

### Task 2: Resolve the shop archive translation URL

**Files:**
- Modify: `integrations/woocommerce/woocommerce.php`
- Create: `tests/woocommerce/test-shop-translation-url.php`

**Interfaces:**
- Consumes: the harness helpers from Task 1.
- Produces: `Linguator\Integrations\woocommerce\Linguator_WooCommerce::shop_translation_url( string $url, object $language ): string`, `Linguator_WooCommerce::init(): void`, and the protected seams `is_shop_archive(): bool`, `get_shop_page_id(): int`, `can_read( int $post_id ): bool`.

- [ ] **Step 1: Write the failing tests**

Create `tests/woocommerce/test-shop-translation-url.php`:

```php
<?php
/**
 * Covers the WooCommerce shop translation URL resolution.
 *
 * @package Linguator
 */

use Linguator\Integrations\woocommerce\Linguator_WooCommerce;
use PHPUnit\Framework\TestCase;

class Shop_Translation_Url_Test extends TestCase {

	/**
	 * Integration under test.
	 *
	 * @var Linguator_WooCommerce
	 */
	private $integration;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( getenv( 'LMAT_TEST_WITHOUT_WOOCOMMERCE' ) ) {
			$this->markTestSkipped( 'This suite requires the WooCommerce stubs.' );
		}

		lmat_test_reset_state();
		$this->integration = new Linguator_WooCommerce();
	}

	/**
	 * Builds a language object as Linguator passes it to the filter.
	 *
	 * @param string $slug Language slug.
	 * @return object
	 */
	private function language( $slug ) {
		return (object) array( 'slug' => $slug );
	}

	/**
	 * Simulates a request to the shop archive with a translated shop page.
	 *
	 * @return void
	 */
	private function given_shop_archive_with_translation() {
		lmat_test_set( 'is_shop', true );
		lmat_test_set( 'shop_page_id', 100 );
		lmat_test_set( 'translations', array( '100:de' => 200, '100:it' => 100 ) );
		lmat_test_set(
			'permalinks',
			array(
				100 => 'https://example.test/shop/',
				200 => 'https://example.test/de/shop-2/',
			)
		);
	}

	/**
	 * Scenario 1: the translated shop permalink is returned.
	 *
	 * @return void
	 */
	public function test_returns_translated_shop_permalink() {
		$this->given_shop_archive_with_translation();

		$this->assertSame(
			'https://example.test/de/shop-2/',
			$this->integration->shop_translation_url( '', $this->language( 'de' ) )
		);
	}

	/**
	 * The self link used by the hreflang output resolves to the shop page itself.
	 *
	 * @return void
	 */
	public function test_returns_self_url_for_the_current_language() {
		$this->given_shop_archive_with_translation();

		$this->assertSame(
			'https://example.test/shop/',
			$this->integration->shop_translation_url( '', $this->language( 'it' ) )
		);
	}

	/**
	 * Scenario 2: no translation in the target language keeps Linguator's fallback.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_when_no_translation_for_target_language() {
		$this->given_shop_archive_with_translation();

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'fr' ) ) );
	}

	/**
	 * Scenarios 3, 4, 5 and 10: any request that is not the shop archive is left to core.
	 *
	 * @dataProvider non_shop_requests
	 *
	 * @param string $case Human readable request description.
	 * @return void
	 */
	public function test_returns_untouched_url_outside_the_shop_archive( $case ) {
		lmat_test_set( 'is_shop', false );
		lmat_test_set( 'shop_page_id', 100 );
		lmat_test_set( 'translations', array( '100:de' => 200 ) );
		lmat_test_set( 'permalinks', array( 200 => 'https://example.test/de/shop-2/' ) );

		$this->assertSame(
			'',
			$this->integration->shop_translation_url( '', $this->language( 'de' ) ),
			$case
		);
	}

	/**
	 * Request shapes that must not be touched by this integration.
	 *
	 * @return array<string, string[]>
	 */
	public function non_shop_requests() {
		return array(
			'ordinary page'                  => array( 'An ordinary page must keep the core is_page() branch.' ),
			'ordinary post'                  => array( 'An ordinary post must keep the core is_single() branch.' ),
			'another post type archive'      => array( 'Other archives must keep the core archive branch.' ),
			'translated shop page (reverse)' => array( 'The translated shop page is an ordinary page handled by core.' ),
		);
	}

	/**
	 * The shop page used as the site front page is left to Linguator's home URL logic.
	 *
	 * @return void
	 */
	public function test_skips_when_the_shop_page_is_the_site_front_page() {
		$this->given_shop_archive_with_translation();
		lmat_test_set( 'is_front_page', true );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * Scenario 7: an unset or invalid shop page falls back safely.
	 *
	 * @dataProvider invalid_shop_page_ids
	 *
	 * @param int $shop_page_id Shop page ID returned by WooCommerce.
	 * @return void
	 */
	public function test_returns_untouched_url_when_shop_page_id_is_invalid( $shop_page_id ) {
		lmat_test_set( 'is_shop', true );
		lmat_test_set( 'shop_page_id', $shop_page_id );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * Invalid shop page IDs.
	 *
	 * @return array<string, int[]>
	 */
	public function invalid_shop_page_ids() {
		return array(
			'unset option' => array( -1 ),
			'zero'         => array( 0 ),
		);
	}

	/**
	 * Scenario 8: an invalid translation mapping falls back safely.
	 *
	 * @dataProvider invalid_translation_ids
	 *
	 * @param mixed $translated_id Value returned by the translation lookup.
	 * @return void
	 */
	public function test_returns_untouched_url_when_translation_mapping_is_invalid( $translated_id ) {
		lmat_test_set( 'is_shop', true );
		lmat_test_set( 'shop_page_id', 100 );
		lmat_test_set( 'translations', array( '100:de' => $translated_id ) );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * Invalid translation lookup results.
	 *
	 * @return array<string, mixed[]>
	 */
	public function invalid_translation_ids() {
		return array(
			'zero'         => array( 0 ),
			'negative'     => array( -5 ),
			'empty string' => array( '' ),
			'non numeric'  => array( 'not-an-id' ),
		);
	}

	/**
	 * A translated shop page the visitor may not read is not linked.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_when_the_translated_page_is_not_readable() {
		$this->given_shop_archive_with_translation();
		lmat_test_set( 'unreadable_ids', array( 200 ) );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * A failed permalink generation falls back safely.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_when_permalink_generation_fails() {
		lmat_test_set( 'is_shop', true );
		lmat_test_set( 'shop_page_id', 100 );
		lmat_test_set( 'translations', array( '100:de' => 200 ) );
		lmat_test_set( 'permalinks', array() );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * A URL already resolved by another handler is never overridden.
	 *
	 * @return void
	 */
	public function test_does_not_override_url_resolved_by_an_earlier_filter() {
		$this->given_shop_archive_with_translation();

		$this->assertSame(
			'https://example.test/de/already-resolved/',
			$this->integration->shop_translation_url( 'https://example.test/de/already-resolved/', $this->language( 'de' ) )
		);
	}

	/**
	 * An unusable language argument is ignored.
	 *
	 * @dataProvider invalid_languages
	 *
	 * @param mixed $language Language argument.
	 * @return void
	 */
	public function test_returns_untouched_url_when_language_is_invalid( $language ) {
		$this->given_shop_archive_with_translation();

		$this->assertSame( '', $this->integration->shop_translation_url( '', $language ) );
	}

	/**
	 * Invalid language arguments.
	 *
	 * @return array<string, mixed[]>
	 */
	public function invalid_languages() {
		return array(
			'null'           => array( null ),
			'empty slug'     => array( (object) array( 'slug' => '' ) ),
			'missing slug'   => array( (object) array() ),
			'string instead' => array( 'de' ),
		);
	}

	/**
	 * A missing Linguator instance never fatals.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_when_linguator_is_unavailable() {
		$this->given_shop_archive_with_translation();
		lmat_test_set( 'linguator', false );

		$this->assertSame( '', $this->integration->shop_translation_url( '', $this->language( 'de' ) ) );
	}

	/**
	 * Scenario 9: the integration hooks the shared switcher API, so every switcher
	 * including the Elementor widget is corrected without any Elementor specific code.
	 *
	 * @return void
	 */
	public function test_init_registers_the_shared_translation_url_filter() {
		$this->integration->init();

		$hooks = lmat_test_get( 'hooks', array() );

		$this->assertCount( 1, $hooks );
		$this->assertSame( 'lmat_pre_translation_url', $hooks[0]['hook'] );
		$this->assertSame( 15, $hooks[0]['priority'] );
		$this->assertSame( 2, $hooks[0]['accepted_args'] );
		$this->assertSame( array( $this->integration, 'shop_translation_url' ), $hooks[0]['callback'] );
	}
}
```

- [ ] **Step 2: Run the tests and verify they fail**

Run: `php "$SCRATCH/phpunit-9.phar" --filter Shop_Translation_Url_Test`
Expected: FAIL — `Call to undefined method Linguator\Integrations\woocommerce\Linguator_WooCommerce::shop_translation_url()`.

- [ ] **Step 3: Implement the resolver**

Replace the empty class body in `integrations/woocommerce/woocommerce.php` with:

```php
class Linguator_WooCommerce {

	/**
	 * Setups filters.
	 *
	 * @since 2.2.1
	 *
	 * @return void
	 */
	public function init() {
		// Priority 15: after the core handler of Linguator_Frontend_Static_Pages (10) and before its customizer override (20).
		add_filter( 'lmat_pre_translation_url', array( $this, 'shop_translation_url' ), 15, 2 );
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
```

- [ ] **Step 4: Run the tests and verify they pass**

Run: `php "$SCRATCH/phpunit-9.phar" --filter Shop_Translation_Url_Test`
Expected: PASS, 24 tests.

- [ ] **Step 5: Lint the changed file**

Run: `php -l integrations/woocommerce/woocommerce.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add integrations/woocommerce/woocommerce.php tests/woocommerce/test-shop-translation-url.php
git commit -m "fix: resolve the WooCommerce shop archive translation URL from the shop page translation"
```

---

### Task 3: Behave safely when WooCommerce is absent

**Files:**
- Create: `tests/woocommerce/test-without-woocommerce.php`

**Interfaces:**
- Consumes: `Linguator_WooCommerce::shop_translation_url()` from Task 2.
- Produces: nothing consumed by later tasks.

This suite runs with `LMAT_TEST_WITHOUT_WOOCOMMERCE=1`, so `is_shop()` and `wc_get_page_id()` are genuinely undefined, exactly like a site without WooCommerce. It proves requirement 6 rather than simulating it.

- [ ] **Step 1: Write the test**

```php
<?php
/**
 * Covers the behaviour of the WooCommerce integration on a site without WooCommerce.
 *
 * Run with: LMAT_TEST_WITHOUT_WOOCOMMERCE=1
 *
 * @package Linguator
 */

use Linguator\Integrations\woocommerce\Linguator_WooCommerce;
use PHPUnit\Framework\TestCase;

class Without_WooCommerce_Test extends TestCase {

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! getenv( 'LMAT_TEST_WITHOUT_WOOCOMMERCE' ) ) {
			$this->markTestSkipped( 'This suite requires the WooCommerce stubs to be absent.' );
		}

		lmat_test_reset_state();
	}

	/**
	 * The WooCommerce functions really are undefined in this suite.
	 *
	 * @return void
	 */
	public function test_woocommerce_functions_are_undefined() {
		$this->assertFalse( function_exists( 'is_shop' ) );
		$this->assertFalse( function_exists( 'wc_get_page_id' ) );
	}

	/**
	 * Scenario 6: no fatal error and no change of behaviour without WooCommerce.
	 *
	 * @return void
	 */
	public function test_returns_untouched_url_without_fatal_error() {
		$integration = new Linguator_WooCommerce();

		$this->assertSame( '', $integration->shop_translation_url( '', (object) array( 'slug' => 'de' ) ) );
		$this->assertSame(
			'https://example.test/de/page/',
			$integration->shop_translation_url( 'https://example.test/de/page/', (object) array( 'slug' => 'de' ) )
		);
	}
}
```

- [ ] **Step 2: Run the suite without the WooCommerce stubs and verify it fails first**

Before Task 2 is applied this test cannot exist. Verify the guard itself instead: temporarily change `is_shop_archive()` to call `is_shop()` without the `function_exists()` check, run the command below, and confirm the fatal `Call to undefined function` appears. Then restore the guard.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php "$SCRATCH/phpunit-9.phar" --filter Without_WooCommerce_Test`
Expected before restoring the guard: FAIL with `Error: Call to undefined function ...is_shop()`.

- [ ] **Step 3: Restore the guard and run both suites**

```bash
php "$SCRATCH/phpunit-9.phar"
LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php "$SCRATCH/phpunit-9.phar"
```

Expected: the first run passes with `Without_WooCommerce_Test` skipped; the second run passes with `Shop_Translation_Url_Test` skipped and `Without_WooCommerce_Test` green.

- [ ] **Step 4: Commit**

```bash
git add tests/woocommerce/test-without-woocommerce.php
git commit -m "test: prove the WooCommerce integration is inert without WooCommerce"
```

---

### Task 4: Register the integration

**Files:**
- Create: `integrations/woocommerce/load.php`
- Modify: `integrations/integration-build.php`
- Modify: `integrations/integrations.php`

**Interfaces:**
- Consumes: `Linguator_WooCommerce::init()` from Task 2.
- Produces: `Linguator_Integrations::instance()->woocommerce`, an instance of `Linguator_WooCommerce` when WooCommerce is active.

- [ ] **Step 1: Write `integrations/woocommerce/load.php`**

```php
<?php
/**
 * Loads the integration with WooCommerce.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use Linguator\Integrations\Linguator_Integrations;

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once __DIR__ . '/woocommerce.php';

		add_action( 'lmat_init', array( Linguator_Integrations::instance()->woocommerce = new Linguator_WooCommerce(), 'init' ) );
	},
	0
);
```

- [ ] **Step 2: Register the directory in `integrations/integration-build.php`**

Insert `'woocommerce',` between `'twenty-seventeen',` and `'wpbakery',`, the position a directory scan produces. The file header says it is generated by the build process; no generator exists in this repository, so the entry is added by hand and the commit message records why.

```php
	'twenty-seventeen',
	'woocommerce',
	'wpbakery',
```

- [ ] **Step 3: Declare the container property in `integrations/integrations.php`**

Add, next to the other integration properties (after the `$cache_compat` block, before `$rankmath`):

```php
	/**
	 * @var mixed
	 */
	public $woocommerce;
```

- [ ] **Step 4: Lint the changed files**

```bash
php -l integrations/woocommerce/load.php
php -l integrations/integration-build.php
php -l integrations/integrations.php
```

Expected: `No syntax errors detected` for each.

- [ ] **Step 5: Verify the loader is inert without WooCommerce**

```bash
php -r '
define( "ABSPATH", __DIR__ . "/" );
$GLOBALS["calls"] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS["calls"][] = $h; return true; }
require "integrations/woocommerce/load.php";
$cb = end( $GLOBALS["calls"] );
echo "registered: " . implode( ",", $GLOBALS["calls"] ) . PHP_EOL;
echo "class loaded: " . ( class_exists( "Linguator\\\\Integrations\\\\woocommerce\\\\Linguator_WooCommerce", false ) ? "yes" : "no" ) . PHP_EOL;
'
```

Expected: `registered: plugins_loaded` and `class loaded: no`. No warning, notice or fatal error.

- [ ] **Step 6: Verify the integration list is well formed**

```bash
php -r '$list = require "integrations/integration-build.php"; var_dump( in_array( "woocommerce", $list, true ), count( $list ) );'
```

Expected: `bool(true)` and `int(17)`.

- [ ] **Step 7: Run the full suite again**

```bash
php "$SCRATCH/phpunit-9.phar"
LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php "$SCRATCH/phpunit-9.phar"
```

Expected: both runs green.

- [ ] **Step 8: Commit**

```bash
git add integrations/woocommerce/load.php integrations/integration-build.php integrations/integrations.php
git commit -m "feat: register the WooCommerce integration

integration-build.php is marked auto-generated but no generator exists in this
repository; the entry is inserted in the position a directory scan produces."
```

---

### Task 5: Document the change

**Files:**
- Modify: `readme.txt:199-206`

**Interfaces:**
- Consumes: the shipped behaviour from Tasks 2 to 4.
- Produces: nothing.

- [ ] **Step 1: Add the changelog entry**

Insert above the `= Version 2.2.0 | 24 August 2026 =` block, keeping the existing format:

```
= Version 2.2.1 =
* Fixed: The language switcher linked the WooCommerce shop page to the home page instead of the translated shop page.
* Added: WooCommerce integration resolving the shop archive translation URL, also used for the rel="alternate" hreflang tags.
```

- [ ] **Step 2: Verify the file still uses LF endings**

Run: `file readme.txt`
Expected: the output does not contain `CRLF`.

- [ ] **Step 3: Commit**

```bash
git add readme.txt
git commit -m "docs: changelog entry for the WooCommerce shop language switcher fix"
```

---

### Task 6: Runtime and SEO verification, then snippet retirement

**Files:** none changed. This task produces evidence, recorded in `docs/superpowers/root-cause/2026-09-12-woocommerce-shop-switcher.md` under a new "Runtime verification" section.

**Interfaces:**
- Consumes: the deployed plugin build from Tasks 2 to 5.
- Produces: the go/no-go decision for disabling Code Snippets snippet 17.

This task requires a WordPress site with WooCommerce, Elementor, SEOPress and at least two Linguator languages. It cannot be executed from this repository alone. Every check below is a manual observation; do not report any of them as done without its recorded output.

**Environment: a full production clone in Local.** The existing `D:\Local_Sites\belt-dev` is a stale clone from before the Linguator migration (it carries GTranslate and Yoast, and has neither Linguator nor SEOPress) and must not be reused or overwritten. Create a **new** Local site and import a fresh production backup into it.

- [ ] **Step 0a: Import the clone and make it safe before doing anything else**

The clone carries live credentials and customer data. Run this immediately after import, before browsing the site, using the Local site shell or `wp --path=<webroot>`:

```bash
# Stop the clone from reaching the outside world.
wp plugin deactivate wp-mail-smtp wp-mail-smtp-custom-sender
wp plugin deactivate woocommerce-gateway-stripe
wp plugin deactivate wordfence
# Caching and minification hide markup changes and must be off for the SEO checks.
wp plugin deactivate w3-total-cache
wp option update blog_public 0
```

Then remove the customer data, which is not needed by any check in this task:

```bash
wp post delete $( wp post list --post_type=shop_order --format=ids ) --force
wp user delete $( wp user list --role=customer --format=ids ) --reassign=1
```

Record the output of `wp plugin list --status=active` as the baseline plugin set.

- [ ] **Step 0b: Confirm the clone reproduces the bug**

With snippet 17 **deactivated** and the unmodified plugin in place, load the shop archive and request the other language from the switcher. Expected: it lands on the target-language home page. If it does not, the clone does not reproduce the reported defect and every later result in this task is meaningless — stop and find out why before continuing.

Reactivate snippet 17 afterwards.

- [ ] **Step 1: Deploy the plugin build with snippet 17 still active and confirm no regression**

Replace the plugin directory with this repository's working tree, keep snippet 17 active, and load the shop archive plus one ordinary translated page. Expect identical behaviour to Step 0b with the snippet on. Any change here means the integration and the snippet disagree; stop and investigate.

- [ ] **Step 2: Disable snippet 17**

Deactivate Code Snippets snippet 17. Do not delete it. This is the state every check from Step 3 on is performed in.

- [ ] **Step 3: Verify the switcher in both directions and in every configured language**

For each configured language pair, from the shop archive and from each translated shop page, click every language in the Linguator Elementor switcher and record the resulting URL. Expected: the translated shop page, never the home page. Repeat with the block switcher and the nav menu switcher.

- [ ] **Step 4: Verify a language with no translated shop page**

Temporarily unpublish one translated shop page. Expected: the switcher item is marked `no-translation` and falls back exactly as before the change (home page, or hidden when `hide_if_no_translation` is set).

- [ ] **Step 5: Inspect the shop page markup in each language**

With `view-source:`, record for the shop archive and each translated shop page:

- `<link rel="canonical">` — must point at the page itself, never at the home page
- `<link rel="alternate" hreflang="...">` — one entry per language pointing at the corresponding shop page, never at the home page; note whether `x-default` is present
- `<meta name="robots">` — expected `index, follow`
- `<title>` and `<meta name="description">`

Linguator emits its own `hreflang` block from `frontend/filters/frontend-filters-links.php:210`, which consumes the same `get_translation_url()` this change fixes, so the alternates on the shop page should now be correct. SEOPress emits the canonical and may emit its own `hreflang`: Linguator ships no SEOPress frontend integration (only SEO field translation in `modules/page-translation/src/component/translate-seo-fields/seo-press.js`), so **duplicate or conflicting `hreflang` tags are a pre-existing site condition, not a regression of this change**. Record what is actually emitted. Do not modify canonical or SEOPress behaviour in this task: if a problem is found, report it and open a separate scope decision.

- [ ] **Step 6: Verify there is no redirect loop and no new duplicate URL**

Request each shop URL with `curl -sSIL` and record the status chain. Expected: a single `200`, no `301`/`302` loop, no new URL variant introduced by the change.

- [ ] **Step 7: Verify the shop page in the sitemaps**

Confirm each language's shop page appears once in the SEOPress XML sitemap and that no home page entry replaced it.

- [ ] **Step 8: Verify WooCommerce is untouched**

Confirm product IDs, prices, stock, cart, checkout and existing orders are unchanged, and that add-to-cart still works from the shop archive in each language.

- [ ] **Step 9: Record the outcome and decide on snippet 17**

Append the observed results to the root cause document. Only when steps 3 to 8 all match the expected behaviour, record that snippet 17 can stay disabled. Leave it disabled rather than deleted as the rollback path, and note the date after which it may be removed.

---

## Self-review

**Spec coverage.** Requirements 1 to 10 map to: 1 `test_returns_translated_shop_permalink`; 2 `test_returns_untouched_url_when_no_translation_for_target_language`; 3, 4, 5 and 10 `test_returns_untouched_url_outside_the_shop_archive` plus the Task 6 manual pass; 6 `Without_WooCommerce_Test` and Task 4 Step 5; 7 `test_returns_untouched_url_when_shop_page_id_is_invalid`; 8 `test_returns_untouched_url_when_translation_mapping_is_invalid`; 9 `test_init_registers_the_shared_translation_url_filter` plus Task 6 Step 3. The SEO requirements are Task 6 Steps 5 to 7. The snippet migration is Task 6 Steps 2 and 9.

**Naming consistency.** `shop_translation_url`, `is_shop_archive`, `get_shop_page_id` and `can_read` are used identically in the implementation, the tests and the registration assertion. The filter name `lmat_pre_translation_url`, the priority `15` and the argument count `2` are identical in `init()` and in `test_init_registers_the_shared_translation_url_filter`.

**Known limitation, deliberately not addressed (YAGNI).** On a paged shop archive (`/shop/page/2/`) the integration returns page 1 of the translated shop page. Linguator's `hreflang` output already skips paged views, and a switcher pointing at the first page of the translated archive is the same behaviour the `is_page()` branch produces for ordinary pages. Nothing in the brief asks for paged parity.
