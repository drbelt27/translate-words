# Shared WooCommerce Product Identity — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let one WooCommerce product keep a single ID, SKU, price, stock figure and order history across four languages, with only its text translated.

**Architecture:** A shared-mode setting takes `product`, `product_variation`, `product_cat`, `product_tag` and `product_brand` out of Linguator's ordinary translate-by-duplication model. Per-language text lives in one post-meta or term-meta record per object, read through a repository. Every display path goes through WooCommerce's own view filters behind a single `should_localize()` context gate, so canonical reads — admin, REST, cron, order creation, email — keep returning canonical data. No new IDs, no new tables, nothing deleted.

**Tech Stack:** PHP 7.2+, WordPress 6.8+, WooCommerce 11.x, PHPUnit 9 on an isolated stub harness (no WordPress install), React + `@bsf/force-ui` for the settings toggle, webpack.

**Spec:** `docs/superpowers/specs/2026-09-15-woocommerce-shared-product-identity-phase-1-design.md`

## Global Constraints

- **PHP floor `>=7.2`** (`composer.json`). No typed properties, no arrow functions, no `??=`, no union types in signatures.
- **WordPress floor 6.8**, WooCommerce 11.1.0 is the measured target version.
- **Text domain `translate-words`.** Every user-facing string wrapped in `__()` / `esc_html__()`.
- **`.claude/CLAUDE.md` describes a different plugin.** `AGENTS.md`, `translate-wp-words.php`, `composer.json` and `package.json` are the source of truth.
- **English** for all code, identifiers, comments and documentation. The user is answered in Italian; the repository is English.
- **LF line endings** (`.gitattributes`). The clone's copies use CRLF — compare with `diff --strip-trailing-cr`.
- **Every PHP file** starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }` after its docblock and namespace.
- **Namespace** `Linguator\Integrations\woocommerce` for integration files (lowercase final segment, matching the existing `woocommerce.php`), `Linguator\Includes\Options\Business` for the option class.
- **Docblocks** carry `@since 2.3.0` for Phase 1 code. This presumes upstream's release numbering; the pull request must say so, exactly as PR #20 did about `readme.txt`.
- **Escaping** at the point of echo: `esc_html()`, `esc_attr()`, `esc_url()`. Never interpolate into SQL; there is no raw SQL in this plan.
- **Do not run `composer install`** — it rewrites files already committed under `vendor/`.
- **Never translate:** product and variation IDs, SKU, price, stock, tax class, dimensions, weight, attribute keys, attribute option values, term IDs, term slugs, order IDs.
- **Branch:** `feat/woocommerce-shared-product-identity-phase-1`, stacked on `fix/woocommerce-shop-language-switcher`. Push to the `fork` remote; `origin` is read-only. Project documentation lives on `main` and must never enter the pull request.

## Running the tests

PHPUnit is not in the repository and `vendor/` must not be rewritten. Fetch the PHAR once:

```bash
curl -sL -o /tmp/phpunit-9.phar https://phar.phpunit.de/phpunit-9.phar
cd "D:/VS_Projects/Other PR/translate-words"
php /tmp/phpunit-9.phar
LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar
```

Both invocations must stay green after every task. The second proves the integration is inert when WooCommerce is absent.

## File Structure

| File | Responsibility |
| --- | --- |
| `integrations/woocommerce/load.php` *(modify)* | Requires the new files, wires the coordinator |
| `integrations/woocommerce/woocommerce.php` *(modify)* | Coordinator: shared-mode state, adapter registration, translation URL handlers for shop, products and product term archives |
| `integrations/woocommerce/shared-mode.php` *(new)* | Reads the setting; owns the activation guard that refuses a duplicated catalogue |
| `integrations/woocommerce/translation-repository.php` *(new)* | Per-object per-language text: read, write, field states, revisions, bulk priming, REST meta privacy |
| `integrations/woocommerce/context-gate.php` *(new)* | `should_localize()` — the single decision every display filter consults |
| `integrations/woocommerce/translated-object-types.php` *(new)* | Removes products and product taxonomies from Linguator's ordinary translation model |
| `integrations/woocommerce/frontend.php` *(new)* | Localized product and variation text through WooCommerce view getters |
| `integrations/woocommerce/attribute-translations.php` *(new)* | Attribute labels and option labels; canonical values untouched |
| `integrations/woocommerce/variations.php` *(new)* | Variation form, `woocommerce_available_variation`, AJAX `get_variation` |
| `integrations/woocommerce/cart.php` *(new)* | Meta cache priming for cart and catalogue loops, so no loop reads storage one product at a time |
| `integrations/woocommerce/orders.php` *(new)* | Purchase language and frozen display text at order creation |
| `integrations/woocommerce/term-translations.php` *(new)* | Term names and descriptions for `product_cat`, `product_tag`, `product_brand` |
| `integrations/woocommerce/seo.php` *(new)* | SEOPress adapter for title, meta description, Open Graph |
| `integrations/woocommerce/admin/product-panel.php` *(new)* | Minimal entry metabox controller |
| `integrations/woocommerce/admin/views/product-panel.php` *(new)* | Metabox markup |
| `includes/options/business/wc-shared-product-identity.php` *(new)* | The boolean option; delegates activation to the guard through a filter |
| `includes/options/registry.php` *(modify)* | Registers the option |
| `admin/settings/views/src/components/general.jsx` *(modify)* | The settings toggle |
| `tests/bootstrap.php` *(modify)* | Stub surface for meta, filters, options, WooCommerce objects |
| `tests/woocommerce/test-*.php` *(new)* | One test file per component above |

---

### Task 1: Extend the unit harness

The existing harness simulates exactly one request shape: "is this the shop archive, and does the shop page have a translation". Every later task needs meta storage, a working filter chain, a current language, and a context it can lie about. Building that once, first, keeps every later task's tests short.

**Files:**
- Modify: `tests/bootstrap.php`
- Test: `tests/woocommerce/test-harness.php` (extend the existing class)

**Interfaces:**
- Consumes: nothing.
- Produces: `lmat_test_reset_state()`, `lmat_test_set( $key, $value )`, `lmat_test_get( $key, $default )`, an executing `apply_filters()` / `do_action()` / `has_filter()`, post and term meta stubs, `LMAT()->options`, `LMAT()->curlang`, `LMAT()->links_model`, `Lmat_Test_Product`, `WP_Error`.

- [ ] **Step 1: Write the failing tests**

Append inside the existing `Harness_Test` class in `tests/woocommerce/test-harness.php`:

```php
	/**
	 * Post meta round-trips through the stub store.
	 *
	 * @return void
	 */
	public function test_post_meta_round_trip() {
		update_post_meta( 12, '_lmat_wc_i18n_de', array( 'revision' => 1 ) );

		$this->assertSame( array( 'revision' => 1 ), get_post_meta( 12, '_lmat_wc_i18n_de', true ) );
		$this->assertSame( '', get_post_meta( 12, '_lmat_wc_i18n_fr', true ) );
	}

	/**
	 * Term meta round-trips through the stub store.
	 *
	 * @return void
	 */
	public function test_term_meta_round_trip() {
		update_term_meta( 5, '_lmat_wc_i18n_de', array( 'revision' => 2 ) );

		$this->assertSame( array( 'revision' => 2 ), get_term_meta( 5, '_lmat_wc_i18n_de', true ) );
	}

	/**
	 * apply_filters() runs the callbacks add_filter() recorded, in priority order.
	 *
	 * @return void
	 */
	public function test_filters_execute_in_priority_order() {
		add_filter( 'lmat_test_hook', function ( $value ) { return $value . 'b'; }, 20 );
		add_filter( 'lmat_test_hook', function ( $value ) { return $value . 'a'; }, 10 );

		$this->assertSame( 'xab', apply_filters( 'lmat_test_hook', 'x' ) );
	}

	/**
	 * The simulated context flags drive the WordPress context functions.
	 *
	 * @return void
	 */
	public function test_context_flags() {
		$this->assertFalse( is_admin() );

		lmat_test_set( 'is_admin', true );

		$this->assertTrue( is_admin() );
	}

	/**
	 * The current language and the options are readable through the Linguator stub.
	 *
	 * @return void
	 */
	public function test_linguator_stub_exposes_language_and_options() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$this->assertSame( 'de', LMAT()->curlang->slug );
		$this->assertTrue( LMAT()->options['wc_shared_product_identity'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Harness_Test`
Expected: FAIL — `Call to undefined function update_post_meta()`.

- [ ] **Step 3: Extend the state shape**

In `tests/bootstrap.php`, replace the array assigned inside `lmat_test_reset_state()` with:

```php
	$GLOBALS['lmat_test_state'] = array(
		'is_shop'         => false,
		'is_front_page'   => false,
		'is_product'      => false,
		'is_product_tax'  => false,
		'queried_term'    => null,
		'shop_page_id'    => -1,
		'translations'    => array(),
		'permalinks'      => array(),
		'unreadable_ids'  => array(),
		'linguator'       => true,
		'hooks'           => array(),
		'post_meta'       => array(),
		'term_meta'       => array(),
		'primed_posts'    => array(),
		'primed_terms'    => array(),
		'is_admin'        => false,
		'doing_ajax'      => false,
		'doing_cron'      => false,
		'rest_request'    => false,
		'wp_cli'          => false,
		'curlang'         => 'it',
		'default_lang'    => 'it',
		'languages'       => array( 'it', 'de', 'fr', 'en' ),
		'options'         => array(),
		'products'        => array(),
	);
```

- [ ] **Step 4: Add the filter chain stubs**

Append to `tests/bootstrap.php`, before the final `require_once` line:

```php
/**
 * Runs the callbacks registered for a hook, in priority order.
 *
 * @since 2.3.0
 *
 * @param string $hook_name Hook name.
 * @param mixed  $value     Value to filter.
 * @return mixed
 */
function apply_filters( $hook_name, $value ) {
	$args      = array_slice( func_get_args(), 2 );
	$callbacks = array();

	foreach ( (array) lmat_test_get( 'hooks', array() ) as $index => $hook ) {
		if ( $hook['hook'] === $hook_name ) {
			$callbacks[] = array( 'priority' => $hook['priority'], 'index' => $index, 'hook' => $hook );
		}
	}

	usort(
		$callbacks,
		function ( $a, $b ) {
			return $a['priority'] === $b['priority'] ? $a['index'] - $b['index'] : $a['priority'] - $b['priority'];
		}
	);

	foreach ( $callbacks as $callback ) {
		$accepted = max( 1, (int) $callback['hook']['accepted_args'] );
		$passed   = array_merge( array( $value ), $args );
		$value    = call_user_func_array( $callback['hook']['callback'], array_slice( $passed, 0, $accepted ) );
	}

	return $value;
}

/**
 * Runs the callbacks registered for an action.
 *
 * @since 2.3.0
 *
 * @param string $hook_name Hook name.
 * @return void
 */
function do_action( $hook_name ) {
	$args = array_slice( func_get_args(), 1 );
	array_unshift( $args, $hook_name, null );
	call_user_func_array( 'apply_filters', $args );
}

/**
 * Tells whether a hook has the given callback registered.
 *
 * @since 2.3.0
 *
 * @param string $hook_name Hook name.
 * @param mixed  $callback  Callback to look for, false for any.
 * @return bool
 */
function has_filter( $hook_name, $callback = false ) {
	foreach ( (array) lmat_test_get( 'hooks', array() ) as $hook ) {
		if ( $hook['hook'] === $hook_name && ( false === $callback || $hook['callback'] === $callback ) ) {
			return true;
		}
	}

	return false;
}
```

- [ ] **Step 5: Add the meta and context stubs**

Append to `tests/bootstrap.php`, after the filter stubs:

```php
/**
 * Reads one simulated meta value.
 *
 * @since 2.3.0
 *
 * @param string $store  State key of the meta store.
 * @param int    $id     Object ID.
 * @param string $key    Meta key.
 * @param bool   $single Whether to return a single value.
 * @return mixed
 */
function lmat_test_get_meta( $store, $id, $key, $single ) {
	$meta = (array) lmat_test_get( $store, array() );

	if ( ! isset( $meta[ (int) $id ][ $key ] ) ) {
		return $single ? '' : array();
	}

	return $single ? $meta[ (int) $id ][ $key ] : array( $meta[ (int) $id ][ $key ] );
}

/**
 * Writes one simulated meta value.
 *
 * @since 2.3.0
 *
 * @param string $store State key of the meta store.
 * @param int    $id    Object ID.
 * @param string $key   Meta key.
 * @param mixed  $value Meta value.
 * @return true
 */
function lmat_test_set_meta( $store, $id, $key, $value ) {
	$meta = (array) lmat_test_get( $store, array() );

	$meta[ (int) $id ][ $key ] = $value;
	lmat_test_set( $store, $meta );

	return true;
}

function get_post_meta( $id, $key = '', $single = false ) {
	return lmat_test_get_meta( 'post_meta', $id, $key, $single );
}

function update_post_meta( $id, $key, $value ) {
	return lmat_test_set_meta( 'post_meta', $id, $key, $value );
}

function delete_post_meta( $id, $key ) {
	$meta = (array) lmat_test_get( 'post_meta', array() );
	unset( $meta[ (int) $id ][ $key ] );
	lmat_test_set( 'post_meta', $meta );

	return true;
}

function get_term_meta( $id, $key = '', $single = false ) {
	return lmat_test_get_meta( 'term_meta', $id, $key, $single );
}

function update_term_meta( $id, $key, $value ) {
	return lmat_test_set_meta( 'term_meta', $id, $key, $value );
}

function delete_term_meta( $id, $key ) {
	$meta = (array) lmat_test_get( 'term_meta', array() );
	unset( $meta[ (int) $id ][ $key ] );
	lmat_test_set( 'term_meta', $meta );

	return true;
}

function update_postmeta_cache( $ids ) {
	lmat_test_set( 'primed_posts', array_merge( (array) lmat_test_get( 'primed_posts', array() ), array_map( 'intval', (array) $ids ) ) );

	return true;
}

function update_termmeta_cache( $ids ) {
	lmat_test_set( 'primed_terms', array_merge( (array) lmat_test_get( 'primed_terms', array() ), array_map( 'intval', (array) $ids ) ) );

	return true;
}

function is_admin() {
	return (bool) lmat_test_get( 'is_admin', false );
}

function wp_doing_ajax() {
	return (bool) lmat_test_get( 'doing_ajax', false );
}

function wp_doing_cron() {
	return (bool) lmat_test_get( 'doing_cron', false );
}

function is_product() {
	return (bool) lmat_test_get( 'is_product', false );
}
```

- [ ] **Step 6: Add the escaping, sanitizing and WP_Error stubs**

Append to `tests/bootstrap.php`:

```php
function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return $text;
}

function esc_attr( $text ) {
	return $text;
}

function esc_url( $url ) {
	return $url;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_title( $title ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
}

function sanitize_text_field( $text ) {
	return trim( wp_strip_all_tags( (string) $text ) );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function wp_kses_post( $value ) {
	return $value;
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

/**
 * Minimal WP_Error stand-in.
 *
 * @since 2.3.0
 */
class WP_Error {
	/**
	 * Error codes mapped to their messages.
	 *
	 * @var array
	 */
	protected $errors = array();

	/**
	 * Error data, keyed by code.
	 *
	 * @var array
	 */
	protected $error_data = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' !== $code ) {
			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}

	public function get_error_code() {
		$codes = array_keys( $this->errors );

		return empty( $codes ) ? '' : $codes[0];
	}

	public function get_error_message( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;

		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}

	public function get_error_data( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;

		return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
	}

	public function has_errors() {
		return ! empty( $this->errors );
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
```

- [ ] **Step 7: Add the language, links and product stubs**

Append to `tests/bootstrap.php`. These classes must be declared **before** `Lmat_Test_Linguator`, because its constructor instantiates them:

```php
/**
 * Language stub.
 *
 * @since 2.3.0
 */
class Lmat_Test_Language {
	/**
	 * Language slug.
	 *
	 * @var string
	 */
	public $slug;

	public function __construct( $slug ) {
		$this->slug = $slug;
	}

	public function get_locale( $filter = 'raw' ) {
		return $this->slug . '_' . strtoupper( $this->slug );
	}
}

/**
 * Links model stub: directory URL mode, default language prefix hidden.
 *
 * @since 2.3.0
 */
class Lmat_Test_Links_Model {
	public function remove_language_from_link( $url ) {
		foreach ( (array) lmat_test_get( 'languages', array() ) as $slug ) {
			$url = str_replace( '/' . $slug . '/', '/', $url );
		}

		return $url;
	}

	public function add_language_to_link( $url, $language ) {
		$slug = is_object( $language ) ? $language->slug : (string) $language;

		if ( $slug === lmat_test_get( 'default_lang', 'it' ) ) {
			return $url;
		}

		return preg_replace( '#^(https?://[^/]+)/#', '${1}/' . $slug . '/', $url );
	}

	public function switch_language_in_link( $url, $language ) {
		return $this->add_language_to_link( $this->remove_language_from_link( $url ), $language );
	}
}

/**
 * Product stub exposing only what the integration calls.
 *
 * @since 2.3.0
 */
class Lmat_Test_Product {
	/**
	 * Product ID.
	 *
	 * @var int
	 */
	protected $id;

	/**
	 * Parent product ID, 0 for a top-level product.
	 *
	 * @var int
	 */
	protected $parent_id;

	/**
	 * Product type.
	 *
	 * @var string
	 */
	protected $type;

	public function __construct( $id, $type = 'simple', $parent_id = 0 ) {
		$this->id        = (int) $id;
		$this->type      = $type;
		$this->parent_id = (int) $parent_id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_parent_id() {
		return $this->parent_id;
	}

	public function get_type() {
		return $this->type;
	}
}

function wc_get_product( $id ) {
	$products = (array) lmat_test_get( 'products', array() );

	return isset( $products[ (int) $id ] ) ? $products[ (int) $id ] : false;
}
```

- [ ] **Step 8: Extend the Linguator stub**

Replace the `Lmat_Test_Linguator` class in `tests/bootstrap.php` with:

```php
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
	 * Plugin options.
	 *
	 * @var ArrayObject
	 */
	public $options;

	/**
	 * Current language.
	 *
	 * @var Lmat_Test_Language
	 */
	public $curlang;

	/**
	 * Links model.
	 *
	 * @var Lmat_Test_Links_Model
	 */
	public $links_model;

	/**
	 * Constructor.
	 *
	 * @since 2.2.1
	 */
	public function __construct() {
		$this->model       = new Lmat_Test_Model();
		$this->options     = new ArrayObject( (array) lmat_test_get( 'options', array() ) );
		$this->curlang     = new Lmat_Test_Language( (string) lmat_test_get( 'curlang', 'it' ) );
		$this->links_model = new Lmat_Test_Links_Model();
	}
}
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — the 27 existing tests plus the 5 new harness tests.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add tests/bootstrap.php tests/woocommerce/test-harness.php
git commit -m "test: extend the isolated harness with meta, filters and context stubs"
```

---

### Task 2: Shared mode state and the activation guard

Everything in this plan is gated on one boolean. This task builds the reader for that boolean and the guard that refuses to turn it on when the site already has a duplicated translated catalogue. The guard lives in the integration, not in the option class, so it is testable without loading Linguator's option base classes.

**Files:**
- Create: `integrations/woocommerce/shared-mode.php`
- Modify: `tests/bootstrap.php` (two more stubs)
- Test: `tests/woocommerce/test-shared-mode.php`

**Interfaces:**
- Consumes: the Task 1 harness.
- Produces:
  - `Linguator\Integrations\woocommerce\Shared_Mode::is_enabled() : bool`
  - `Linguator\Integrations\woocommerce\Shared_Mode::blockers() : int[]` — IDs of products that already carry a Linguator translation
  - `Linguator\Integrations\woocommerce\Shared_Mode::guard( $value ) : true|WP_Error` — the callback the option's sanitizer consults

- [ ] **Step 1: Add the two harness stubs this task needs**

Append to `tests/bootstrap.php`, before the final `require_once`:

```php
function get_posts( $args = array() ) {
	return (array) lmat_test_get( 'product_ids', array() );
}

function linguator_get_post_translations( $post_id ) {
	$translations = (array) lmat_test_get( 'post_translations', array() );

	return isset( $translations[ (int) $post_id ] ) ? $translations[ (int) $post_id ] : array();
}
```

Add `'product_ids' => array(),` and `'post_translations' => array(),` to the state array in `lmat_test_reset_state()`.

Add the new file to the harness requires at the bottom of `tests/bootstrap.php`:

```php
require_once dirname( __DIR__ ) . '/integrations/woocommerce/shared-mode.php';
```

- [ ] **Step 2: Write the failing tests**

Create `tests/woocommerce/test-shared-mode.php`:

```php
<?php
/**
 * Covers the shared mode state reader and its activation guard.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Shared_Mode;

class Shared_Mode_Test extends TestCase {

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
	 * Shared mode is off when the option was never set.
	 *
	 * @return void
	 */
	public function test_disabled_by_default() {
		$this->assertFalse( Shared_Mode::is_enabled() );
	}

	/**
	 * Shared mode reads the option.
	 *
	 * @return void
	 */
	public function test_enabled_when_option_is_true() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );

		$this->assertTrue( Shared_Mode::is_enabled() );
	}

	/**
	 * Shared mode is off when Linguator is not available.
	 *
	 * @return void
	 */
	public function test_disabled_without_linguator() {
		lmat_test_set( 'options', array( 'wc_shared_product_identity' => true ) );
		lmat_test_set( 'linguator', false );

		$this->assertFalse( Shared_Mode::is_enabled() );
	}

	/**
	 * A catalogue with no translated product produces no blockers.
	 *
	 * @return void
	 */
	public function test_no_blockers_on_a_clean_catalogue() {
		lmat_test_set( 'product_ids', array( 101, 102, 103 ) );
		lmat_test_set(
			'post_translations',
			array(
				101 => array( 'it' => 101 ),
				102 => array( 'it' => 102 ),
				103 => array(),
			)
		);

		$this->assertSame( array(), Shared_Mode::blockers() );
	}

	/**
	 * A product with a translation in another language is a blocker.
	 *
	 * @return void
	 */
	public function test_translated_products_are_blockers() {
		lmat_test_set( 'product_ids', array( 101, 102 ) );
		lmat_test_set(
			'post_translations',
			array(
				101 => array( 'it' => 101, 'de' => 999 ),
				102 => array( 'it' => 102 ),
			)
		);

		$this->assertSame( array( 101 ), Shared_Mode::blockers() );
	}

	/**
	 * A translation entry pointing back at the product itself is not a blocker.
	 *
	 * @return void
	 */
	public function test_self_reference_is_not_a_blocker() {
		lmat_test_set( 'product_ids', array( 101 ) );
		lmat_test_set( 'post_translations', array( 101 => array( 'it' => 101, 'de' => 101 ) ) );

		$this->assertSame( array(), Shared_Mode::blockers() );
	}

	/**
	 * The guard lets a disabling write through without scanning.
	 *
	 * @return void
	 */
	public function test_guard_allows_disabling() {
		lmat_test_set( 'product_ids', array( 101 ) );
		lmat_test_set( 'post_translations', array( 101 => array( 'it' => 101, 'de' => 999 ) ) );

		$this->assertTrue( Shared_Mode::guard( false ) );
	}

	/**
	 * The guard refuses to enable when blockers exist, and names them.
	 *
	 * @return void
	 */
	public function test_guard_refuses_enabling_with_blockers() {
		lmat_test_set( 'product_ids', array( 101 ) );
		lmat_test_set( 'post_translations', array( 101 => array( 'it' => 101, 'de' => 999 ) ) );

		$result = Shared_Mode::guard( true );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'lmat_wc_shared_identity_blocked', $result->get_error_code() );
		$this->assertSame( array( 'products' => array( 101 ) ), $result->get_error_data() );
	}

	/**
	 * The guard allows enabling on a clean catalogue.
	 *
	 * @return void
	 */
	public function test_guard_allows_enabling_without_blockers() {
		lmat_test_set( 'product_ids', array( 101 ) );
		lmat_test_set( 'post_translations', array( 101 => array( 'it' => 101 ) ) );

		$this->assertTrue( Shared_Mode::guard( true ) );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Shared_Mode_Test`
Expected: FAIL — `Class "Linguator\Integrations\woocommerce\Shared_Mode" not found`.

- [ ] **Step 4: Write the implementation**

Create `integrations/woocommerce/shared-mode.php`:

```php
<?php
/**
 * Shared product identity mode: state and activation guard.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use WP_Error;

/**
 * Reads the shared product identity setting and guards its activation.
 *
 * @since 2.3.0
 */
class Shared_Mode {

	/**
	 * The option key holding the setting.
	 *
	 * @since 2.3.0
	 *
	 * @var string
	 */
	const OPTION = 'wc_shared_product_identity';

	/**
	 * Tells whether shared product identity is active.
	 *
	 * Fails closed: with Linguator unavailable, or the option unset, shared mode is off and the
	 * integration behaves exactly as it does today.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! function_exists( 'LMAT' ) ) {
			return false;
		}

		$linguator = LMAT();

		if ( ! is_object( $linguator ) || ! isset( $linguator->options ) ) {
			return false;
		}

		return isset( $linguator->options[ self::OPTION ] ) && ! empty( $linguator->options[ self::OPTION ] );
	}

	/**
	 * Returns the products that already carry a Linguator translation.
	 *
	 * Shared mode and a duplicated translated catalogue cannot coexist: merging them automatically
	 * would be unrecoverable, so activation is refused instead and the conflicting products named.
	 *
	 * @since 2.3.0
	 *
	 * @return int[] Product IDs, empty when the catalogue is clean.
	 */
	public static function blockers() {
		if ( ! function_exists( 'linguator_get_post_translations' ) ) {
			return array();
		}

		$product_ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$blockers = array();

		foreach ( (array) $product_ids as $product_id ) {
			$product_id   = (int) $product_id;
			$translations = (array) linguator_get_post_translations( $product_id );

			foreach ( $translations as $translated_id ) {
				if ( (int) $translated_id > 0 && (int) $translated_id !== $product_id ) {
					$blockers[] = $product_id;
					break;
				}
			}
		}

		return $blockers;
	}

	/**
	 * Decides whether shared mode may be switched on.
	 *
	 * Registered by the coordinator on `lmat_wc_shared_identity_guard`, which the option's
	 * sanitizer consults before storing a `true` value.
	 *
	 * @since 2.3.0
	 *
	 * @param bool $value The value the option is about to be set to.
	 * @return true|WP_Error True when the write may proceed, a blocking error otherwise.
	 */
	public static function guard( $value ) {
		if ( empty( $value ) ) {
			return true; // Turning shared mode off is always allowed and never destructive.
		}

		$blockers = array_map( 'absint', self::blockers() );

		if ( empty( $blockers ) ) {
			return true;
		}

		return new WP_Error(
			'lmat_wc_shared_identity_blocked',
			sprintf(
				/* translators: %s is a comma separated list of product IDs. */
				__( 'Shared product identity cannot be enabled while translated products exist. Review these products first: %s', 'translate-words' ),
				implode( ', ', $blockers )
			),
			array( 'products' => $blockers )
		);
	}
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 8 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add integrations/woocommerce/shared-mode.php tests/bootstrap.php tests/woocommerce/test-shared-mode.php
git commit -m "feat: add shared product identity mode state and activation guard"
```

---

### Task 3: Register the option and wire the coordinator

The option class is thin plumbing over Task 2's guard: Linguator's option base classes are not loaded in the isolated harness, so the behaviour worth testing was deliberately put in `Shared_Mode`. What *is* unit-tested here is that the coordinator registers the guard and nothing else while shared mode is off.

**Files:**
- Create: `includes/options/business/wc-shared-product-identity.php`
- Modify: `includes/options/registry.php`
- Modify: `integrations/woocommerce/woocommerce.php`
- Modify: `integrations/woocommerce/load.php`
- Test: `tests/woocommerce/test-coordinator.php`

**Interfaces:**
- Consumes: `Shared_Mode::guard()`, `Shared_Mode::is_enabled()`.
- Produces:
  - Option key `wc_shared_product_identity`, default `false`, readable at `LMAT()->options['wc_shared_product_identity']`
  - Filter `lmat_wc_shared_identity_guard` — `true|WP_Error`, consulted before the option is stored
  - `Linguator_WooCommerce::init()` registering adapters only while shared mode is on

- [ ] **Step 1: Write the failing test**

Create `tests/woocommerce/test-coordinator.php`:

```php
<?php
/**
 * Covers the WooCommerce integration coordinator's hook registration.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Linguator_WooCommerce;

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
}
```

The third test will keep failing until Task 6 creates the identity adapter. Write it now, mark it skipped with `$this->markTestIncomplete( 'Identity adapter arrives in Task 6.' );` as the first line of its body, and remove that line in Task 6.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php /tmp/phpunit-9.phar --filter Coordinator_Test`
Expected: FAIL — `lmat_wc_shared_identity_guard` is not registered.

- [ ] **Step 3: Create the option class**

Create `includes/options/business/wc-shared-product-identity.php`:

```php
<?php
/**
 * @package Linguator
 */

namespace Linguator\Includes\Options\Business;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Linguator\Includes\Options\Primitive\Abstract_Boolean;
use Linguator\Includes\Options\Options;
use WP_Error;

/**
 * Class defining the "Shared WooCommerce product identity" boolean option.
 *
 * Off by default. Enabling is refused while the site still holds a duplicated translated
 * catalogue: the decision is delegated to the WooCommerce integration through the
 * `lmat_wc_shared_identity_guard` filter, so that this class stays free of WooCommerce knowledge.
 *
 * @since 2.3.0
 */
class Wc_Shared_Product_Identity extends Abstract_Boolean {
	/**
	 * Returns option key.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 *
	 * @phpstan-return 'wc_shared_product_identity'
	 */
	public static function key(): string {
		return 'wc_shared_product_identity';
	}

	/**
	 * Returns the default value.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	protected function get_default() {
		return false;
	}

	/**
	 * Sanitizes option's value, refusing activation when the guard reports a conflict.
	 *
	 * @since 2.3.0
	 *
	 * @param bool    $value   Value to sanitize.
	 * @param Options $options All options.
	 * @return bool|WP_Error The sanitized value, or a blocking error.
	 */
	protected function sanitize( $value, Options $options ) {
		$value = parent::sanitize( $value, $options );

		if ( $value instanceof WP_Error || true !== $value ) {
			return $value;
		}

		/**
		 * Filters whether shared WooCommerce product identity may be enabled.
		 *
		 * The value under test is passed as the first argument, so a handler decides on the value
		 * itself rather than on a default someone else supplied. A handler returns the value to
		 * allow the write, or a WP_Error to refuse it.
		 *
		 * @since 2.3.0
		 *
		 * @param bool $value The value the option is about to be set to.
		 */
		$allowed = apply_filters( 'lmat_wc_shared_identity_guard', $value );

		return $allowed instanceof WP_Error ? $allowed : $value;
	}

	/**
	 * Returns the description used in the JSON schema.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	protected function get_description(): string {
		return __( 'Share one WooCommerce product identity across languages: one product ID, SKU, price and stock, with only the text translated.', 'translate-words' );
	}
}
```

- [ ] **Step 4: Register the option**

In `includes/options/registry.php`, add to the `OPTIONS` constant, after `Business\Media_Support::class`:

```php
		// WooCommerce.
		Business\Wc_Shared_Product_Identity::class,
```

- [ ] **Step 5: Wire the coordinator**

In `integrations/woocommerce/woocommerce.php`, add the import and replace `init()`:

```php
use WP_Error;

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
		$this->translated_object_types = new Translated_Object_Types();
		$this->translated_object_types->init();
	}
```

Declare the property alongside the class's other members:

```php
	/**
	 * Removes products and product taxonomies from Linguator's ordinary translation model.
	 *
	 * @since 2.3.0
	 *
	 * @var Translated_Object_Types|null
	 */
	protected $translated_object_types = null;
```

`Translated_Object_Types` does not exist yet — it arrives in Task 6. Until then, keep `register_shared_mode()` with an empty body and a `// Adapters are registered from Task 6 onwards.` comment, so this task's tests pass on their own.

- [ ] **Step 6: Wire the loader**

In `integrations/woocommerce/load.php`, add the new require before the existing one:

```php
		require_once __DIR__ . '/shared-mode.php';
		require_once __DIR__ . '/woocommerce.php';
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS, with `test_shared_mode_on_registers_the_identity_filters` reported as incomplete.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add includes/options/business/wc-shared-product-identity.php includes/options/registry.php integrations/woocommerce/woocommerce.php integrations/woocommerce/load.php tests/woocommerce/test-coordinator.php
git commit -m "feat: register the shared product identity option and gate the integration on it"
```

---

### Task 4: The translation repository

One meta record per object per language, holding every translated field with its source fingerprint and status. This is the only component that touches storage, and the only one that tells the three field states apart.

**Files:**
- Create: `integrations/woocommerce/translation-repository.php`
- Modify: `tests/bootstrap.php` (REST response stub, require the new file)
- Test: `tests/woocommerce/test-translation-repository.php`

**Interfaces:**
- Consumes: the Task 1 harness.
- Produces `Linguator\Integrations\woocommerce\Translation_Repository` with:
  - `const META_PREFIX = '_lmat_wc_i18n_'`, `STATUS_TRANSLATED = 'translated'`, `STATUS_NEEDS_REVIEW = 'needs_review'`, `STATUS_MISSING = 'missing'`, `TYPE_POST = 'post'`, `TYPE_TERM = 'term'`
  - `static meta_key( string $language ) : string`
  - `static hash( string $source ) : string`
  - `get_record( int $object_id, string $language, string $type = self::TYPE_POST ) : array`
  - `get_value( int $object_id, string $language, string $field, string $source, string $type = self::TYPE_POST ) : string`
  - `get_status( int $object_id, string $language, string $field, string $source, string $type = self::TYPE_POST ) : string`
  - `save( int $object_id, string $language, array $fields, int $revision, string $type = self::TYPE_POST ) : true|WP_Error`, where `$fields` maps a field name to `array( 'value' => string, 'source' => string )`
  - `prime( array $object_ids, string $type = self::TYPE_POST ) : void`
  - `filter_rest_meta( $response, $object, $request )`

- [ ] **Step 1: Add the REST response stub and the require**

Append to `tests/bootstrap.php`:

```php
/**
 * Minimal WP_REST_Response stand-in.
 *
 * @since 2.3.0
 */
class Lmat_Test_REST_Response {
	/**
	 * Response payload.
	 *
	 * @var array
	 */
	public $data;

	public function __construct( $data = array() ) {
		$this->data = $data;
	}
}
```

And, with the other requires at the bottom of the file:

```php
require_once dirname( __DIR__ ) . '/integrations/woocommerce/translation-repository.php';
```

- [ ] **Step 2: Write the failing tests, part 1 — the three field states**

Create `tests/woocommerce/test-translation-repository.php`:

```php
<?php
/**
 * Covers per-object per-language text storage.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Translation_Repository_Test extends TestCase {

	/**
	 * The repository under test.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$this->repository = new Translation_Repository();
	}

	/**
	 * The meta key carries the language slug.
	 *
	 * @return void
	 */
	public function test_meta_key() {
		$this->assertSame( '_lmat_wc_i18n_de', Translation_Repository::meta_key( 'de' ) );
	}

	/**
	 * A never-translated field falls back to the source text.
	 *
	 * @return void
	 */
	public function test_missing_field_falls_back_to_source() {
		$this->assertSame( 'Tassa di socio', $this->repository->get_value( 42, 'de', 'name', 'Tassa di socio' ) );
		$this->assertSame( Translation_Repository::STATUS_MISSING, $this->repository->get_status( 42, 'de', 'name', 'Tassa di socio' ) );
	}

	/**
	 * A translated field is returned instead of the source.
	 *
	 * @return void
	 */
	public function test_translated_field_is_returned() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Mitgliedsbeitrag', 'source' => 'Tassa di socio' ) ), 0 );

		$this->assertSame( 'Mitgliedsbeitrag', $this->repository->get_value( 42, 'de', 'name', 'Tassa di socio' ) );
		$this->assertSame( Translation_Repository::STATUS_TRANSLATED, $this->repository->get_status( 42, 'de', 'name', 'Tassa di socio' ) );
	}

	/**
	 * An intentionally empty translation is respected and is not a fallback.
	 *
	 * @return void
	 */
	public function test_intentionally_empty_field_is_respected() {
		$this->repository->save( 42, 'de', array( 'short_description' => array( 'value' => '', 'source' => 'Testo italiano' ) ), 0 );

		$this->assertSame( '', $this->repository->get_value( 42, 'de', 'short_description', 'Testo italiano' ) );
		$this->assertSame( Translation_Repository::STATUS_TRANSLATED, $this->repository->get_status( 42, 'de', 'short_description', 'Testo italiano' ) );
	}

	/**
	 * A changed source marks the translation stale without hiding it.
	 *
	 * @return void
	 */
	public function test_changed_source_marks_needs_review_and_keeps_displaying() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Mitgliedsbeitrag', 'source' => 'Tassa di socio' ) ), 0 );

		$this->assertSame( 'Mitgliedsbeitrag', $this->repository->get_value( 42, 'de', 'name', 'Tassa di socio 2027' ) );
		$this->assertSame( Translation_Repository::STATUS_NEEDS_REVIEW, $this->repository->get_status( 42, 'de', 'name', 'Tassa di socio 2027' ) );
	}
```

- [ ] **Step 3: Write the failing tests, part 2 — revisions, isolation, priming, privacy**

Continue the same class in `tests/woocommerce/test-translation-repository.php`:

```php
	/**
	 * Saving increments the revision.
	 *
	 * @return void
	 */
	public function test_revision_increments() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Eins', 'source' => 'Uno' ) ), 0 );
		$first = $this->repository->get_record( 42, 'de' );

		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Zwei', 'source' => 'Uno' ) ), $first['revision'] );
		$second = $this->repository->get_record( 42, 'de' );

		$this->assertSame( 1, $first['revision'] );
		$this->assertSame( 2, $second['revision'] );
	}

	/**
	 * A write carrying a stale revision is rejected and changes nothing.
	 *
	 * @return void
	 */
	public function test_stale_revision_is_rejected() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Eins', 'source' => 'Uno' ) ), 0 );
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Zwei', 'source' => 'Uno' ) ), 1 );

		$result = $this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Drei', 'source' => 'Uno' ) ), 1 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'lmat_wc_stale_revision', $result->get_error_code() );
		$this->assertSame( array( 'revision' => 2 ), $result->get_error_data() );
		$this->assertSame( 'Zwei', $this->repository->get_value( 42, 'de', 'name', 'Uno' ) );
	}

	/**
	 * Saving one field leaves the others in place.
	 *
	 * @return void
	 */
	public function test_save_merges_fields() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Name', 'source' => 'Nome' ) ), 0 );
		$this->repository->save( 42, 'de', array( 'description' => array( 'value' => 'Text', 'source' => 'Testo' ) ), 1 );

		$this->assertSame( 'Name', $this->repository->get_value( 42, 'de', 'name', 'Nome' ) );
		$this->assertSame( 'Text', $this->repository->get_value( 42, 'de', 'description', 'Testo' ) );
	}

	/**
	 * Languages do not leak into one another.
	 *
	 * @return void
	 */
	public function test_languages_are_isolated() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Deutsch', 'source' => 'Italiano' ) ), 0 );

		$this->assertSame( 'Italiano', $this->repository->get_value( 42, 'fr', 'name', 'Italiano' ) );
	}

	/**
	 * Terms use term meta and never collide with the post of the same ID.
	 *
	 * @return void
	 */
	public function test_terms_use_their_own_store() {
		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Term', 'source' => 'Termine' ) ), 0, Translation_Repository::TYPE_TERM );

		$this->assertSame( 'Term', $this->repository->get_value( 42, 'de', 'name', 'Termine', Translation_Repository::TYPE_TERM ) );
		$this->assertSame( 'Termine', $this->repository->get_value( 42, 'de', 'name', 'Termine' ) );
	}

	/**
	 * Priming warms WordPress's own meta cache for a whole ID set at once.
	 *
	 * @return void
	 */
	public function test_prime_warms_the_meta_cache() {
		$this->repository->prime( array( 1, 2, 3 ) );
		$this->repository->prime( array( 7 ), Translation_Repository::TYPE_TERM );

		$this->assertSame( array( 1, 2, 3 ), lmat_test_get( 'primed_posts' ) );
		$this->assertSame( array( 7 ), lmat_test_get( 'primed_terms' ) );
	}

	/**
	 * Translation storage never reaches the WooCommerce REST meta_data payload.
	 *
	 * @return void
	 */
	public function test_rest_meta_is_filtered_out() {
		$response = new Lmat_Test_REST_Response(
			array(
				'meta_data' => array(
					array( 'key' => '_lmat_wc_i18n_de', 'value' => array( 'revision' => 1 ) ),
					array( 'key' => '_sku_extra', 'value' => 'keep-me' ),
				),
			)
		);

		$filtered = $this->repository->filter_rest_meta( $response, null, null );

		$this->assertCount( 1, $filtered->data['meta_data'] );
		$this->assertSame( '_sku_extra', $filtered->data['meta_data'][0]['key'] );
	}
}
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Translation_Repository_Test`
Expected: FAIL — `Class "Linguator\Integrations\woocommerce\Translation_Repository" not found`.

- [ ] **Step 5: Write the implementation, part 1 — reading**

Create `integrations/woocommerce/translation-repository.php`:

```php
<?php
/**
 * Per-object per-language text storage for shared WooCommerce products.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use WP_Error;

/**
 * Reads and writes the translated text of a shared object.
 *
 * One record per object per language, stored in post meta for products and variations and in term
 * meta for product taxonomies. WooCommerce owns identity and commercial data; this class owns text.
 *
 * @since 2.3.0
 */
class Translation_Repository {

	const META_PREFIX         = '_lmat_wc_i18n_';
	const STATUS_TRANSLATED   = 'translated';
	const STATUS_NEEDS_REVIEW = 'needs_review';
	const STATUS_MISSING      = 'missing';
	const TYPE_POST           = 'post';
	const TYPE_TERM           = 'term';

	/**
	 * Returns the meta key holding a language's record.
	 *
	 * @since 2.3.0
	 *
	 * @param string $language Language slug.
	 * @return string
	 */
	public static function meta_key( $language ) {
		return self::META_PREFIX . sanitize_key( $language );
	}

	/**
	 * Fingerprints a source string, so that a later edit of the source can be detected.
	 *
	 * @since 2.3.0
	 *
	 * @param string $source Source text at translation time.
	 * @return string
	 */
	public static function hash( $source ) {
		return substr( sha1( (string) $source ), 0, 12 );
	}

	/**
	 * Returns the whole record for one object in one language.
	 *
	 * Always returns the full shape, so callers never have to test for a missing record.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Language slug.
	 * @param string $type      self::TYPE_POST or self::TYPE_TERM.
	 * @return array{revision: int, updated: string, fields: array}
	 */
	public function get_record( $object_id, $language, $type = self::TYPE_POST ) {
		$record = self::TYPE_TERM === $type
			? get_term_meta( (int) $object_id, self::meta_key( $language ), true )
			: get_post_meta( (int) $object_id, self::meta_key( $language ), true );

		if ( ! is_array( $record ) ) {
			$record = array();
		}

		return array(
			'revision' => isset( $record['revision'] ) ? (int) $record['revision'] : 0,
			'updated'  => isset( $record['updated'] ) ? (string) $record['updated'] : '',
			'fields'   => isset( $record['fields'] ) && is_array( $record['fields'] ) ? $record['fields'] : array(),
		);
	}

	/**
	 * Returns the text to display for one field.
	 *
	 * Three states are distinguished: a field that was never translated falls back to the source,
	 * a field stored with an empty value is respected as intentionally empty, and a field whose
	 * source has since changed keeps being displayed rather than reverting to the source language.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Language slug.
	 * @param string $field     Field name.
	 * @param string $source    Current source text, used as the fallback.
	 * @param string $type      self::TYPE_POST or self::TYPE_TERM.
	 * @return string
	 */
	public function get_value( $object_id, $language, $field, $source, $type = self::TYPE_POST ) {
		$record = $this->get_record( $object_id, $language, $type );

		if ( ! isset( $record['fields'][ $field ] ) || ! array_key_exists( 'value', (array) $record['fields'][ $field ] ) ) {
			return (string) $source;
		}

		return (string) $record['fields'][ $field ]['value'];
	}

	/**
	 * Returns the editorial status of one field against the current source text.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Language slug.
	 * @param string $field     Field name.
	 * @param string $source    Current source text.
	 * @param string $type      self::TYPE_POST or self::TYPE_TERM.
	 * @return string One of self::STATUS_MISSING, self::STATUS_TRANSLATED, self::STATUS_NEEDS_REVIEW.
	 */
	public function get_status( $object_id, $language, $field, $source, $type = self::TYPE_POST ) {
		$record = $this->get_record( $object_id, $language, $type );

		if ( ! isset( $record['fields'][ $field ] ) ) {
			return self::STATUS_MISSING;
		}

		$stored = isset( $record['fields'][ $field ]['source_hash'] ) ? (string) $record['fields'][ $field ]['source_hash'] : '';

		return self::hash( $source ) === $stored ? self::STATUS_TRANSLATED : self::STATUS_NEEDS_REVIEW;
	}
```

- [ ] **Step 6: Write the implementation, part 2 — writing, priming, privacy**

Continue the same class:

```php
	/**
	 * Writes one or more fields for one object in one language.
	 *
	 * The caller passes the revision it last read. A write carrying an older revision is rejected
	 * rather than merged, so a translation is never silently lost. This is best-effort, not atomic:
	 * see the Phase 1 design's "Concurrency, as actually guaranteed".
	 *
	 * @since 2.3.0
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $language  Language slug.
	 * @param array  $fields    Field name mapped to array( 'value' => string, 'source' => string ).
	 * @param int    $revision  The revision the caller last read; 0 for a first write.
	 * @param string $type      self::TYPE_POST or self::TYPE_TERM.
	 * @return true|WP_Error
	 */
	public function save( $object_id, $language, array $fields, $revision, $type = self::TYPE_POST ) {
		$record = $this->get_record( $object_id, $language, $type );

		if ( (int) $revision !== $record['revision'] ) {
			return new WP_Error(
				'lmat_wc_stale_revision',
				__( 'This translation was changed by someone else while you were editing it. Reload the product and apply your changes again.', 'translate-words' ),
				array( 'revision' => $record['revision'] )
			);
		}

		foreach ( $fields as $field => $data ) {
			$record['fields'][ $field ] = array(
				'value'       => isset( $data['value'] ) ? (string) $data['value'] : '',
				'source_hash' => self::hash( isset( $data['source'] ) ? $data['source'] : '' ),
				'status'      => self::STATUS_TRANSLATED,
			);
		}

		$record['revision'] = $record['revision'] + 1;
		$record['updated']  = gmdate( 'c' );

		if ( self::TYPE_TERM === $type ) {
			update_term_meta( (int) $object_id, self::meta_key( $language ), $record );
		} else {
			update_post_meta( (int) $object_id, self::meta_key( $language ), $record );
		}

		return true;
	}

	/**
	 * Warms WordPress's meta cache for a whole set of objects at once.
	 *
	 * Catalogue loops must never issue one query per product per language. WP_Query already primes
	 * the meta cache for its own results; this exists for the ID sets handed to the repository
	 * outside a loop. No new caching layer is introduced.
	 *
	 * @since 2.3.0
	 *
	 * @param int[]  $object_ids Post or term IDs.
	 * @param string $type       self::TYPE_POST or self::TYPE_TERM.
	 * @return void
	 */
	public function prime( array $object_ids, $type = self::TYPE_POST ) {
		$object_ids = array_values( array_unique( array_map( 'intval', $object_ids ) ) );

		if ( empty( $object_ids ) ) {
			return;
		}

		if ( self::TYPE_TERM === $type ) {
			update_termmeta_cache( $object_ids );

			return;
		}

		update_postmeta_cache( $object_ids );
	}

	/**
	 * Strips translation storage from a WooCommerce REST meta_data payload.
	 *
	 * The leading underscore makes the key protected, which is not by itself an access policy.
	 * Phase 3 exposes translations deliberately, through its own field, never by leaking storage.
	 *
	 * @since 2.3.0
	 *
	 * @param object $response The REST response.
	 * @param mixed  $object   The product or variation being prepared. Unused.
	 * @param mixed  $request  The REST request. Unused.
	 * @return object
	 */
	public function filter_rest_meta( $response, $object = null, $request = null ) {
		if ( ! is_object( $response ) || ! isset( $response->data['meta_data'] ) || ! is_array( $response->data['meta_data'] ) ) {
			return $response;
		}

		$kept = array();

		foreach ( $response->data['meta_data'] as $meta ) {
			$key = '';

			if ( is_array( $meta ) && isset( $meta['key'] ) ) {
				$key = (string) $meta['key'];
			} elseif ( is_object( $meta ) && isset( $meta->key ) ) {
				$key = (string) $meta->key;
			}

			if ( 0 === strpos( $key, self::META_PREFIX ) ) {
				continue;
			}

			$kept[] = $meta;
		}

		$response->data['meta_data'] = array_values( $kept );

		return $response;
	}
}
```

- [ ] **Step 7: Register the REST privacy filters in the coordinator**

In `register_shared_mode()` in `integrations/woocommerce/woocommerce.php`:

```php
		$this->repository = new Translation_Repository();

		add_filter( 'woocommerce_rest_prepare_product_object', array( $this->repository, 'filter_rest_meta' ), 10, 3 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this->repository, 'filter_rest_meta' ), 10, 3 );
```

with the matching property:

```php
	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository|null
	 */
	protected $repository = null;
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 12 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add integrations/woocommerce/translation-repository.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-translation-repository.php
git commit -m "feat: add the WooCommerce translation repository"
```

---

### Task 5: The localization context gate

The highest-risk surface in the design. `woocommerce_product_get_name` fires in contexts that have nothing to do with rendering a storefront page, and localizing in the wrong one corrupts order history permanently. Every display filter in every later task asks this one object whether it may act.

**Files:**
- Create: `integrations/woocommerce/context-gate.php`
- Modify: `tests/bootstrap.php` (require the new file)
- Test: `tests/woocommerce/test-context-gate.php`

**Interfaces:**
- Consumes: the Task 1 harness.
- Produces `Linguator\Integrations\woocommerce\Context_Gate` with:
  - `const CONTEXT_ORDER = 'order'`, `const CONTEXT_EMAIL = 'email'`
  - `init() : void` — brackets email rendering and order creation
  - `should_localize() : bool`
  - `suspend( string $context ) : void`, `resume( string $context ) : void`

- [ ] **Step 1: Write the failing tests**

Create `tests/woocommerce/test-context-gate.php`:

```php
<?php
/**
 * Covers the single decision every display filter consults.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;

class Context_Gate_Test extends TestCase {

	/**
	 * The gate under test.
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
		$_REQUEST = array();
		$this->gate = new Context_Gate();
	}

	/**
	 * An ordinary front end request localizes.
	 *
	 * @return void
	 */
	public function test_frontend_request_localizes() {
		$this->assertTrue( $this->gate->should_localize() );
	}

	/**
	 * The admin reads canonical text, or an editor renames a product by accident.
	 *
	 * @return void
	 */
	public function test_admin_does_not_localize() {
		lmat_test_set( 'is_admin', true );

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * An admin-ajax request that is not a known WooCommerce front end action stays canonical.
	 *
	 * @return void
	 */
	public function test_admin_ajax_without_a_known_action_does_not_localize() {
		lmat_test_set( 'is_admin', true );
		lmat_test_set( 'doing_ajax', true );
		$_REQUEST['action'] = 'woocommerce_do_ajax_product_export';

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * WooCommerce front end AJAX localizes, because the customer is looking at the result.
	 *
	 * @return void
	 */
	public function test_woocommerce_frontend_ajax_localizes() {
		lmat_test_set( 'is_admin', true );
		lmat_test_set( 'doing_ajax', true );
		$_REQUEST['action'] = 'woocommerce_add_to_cart';

		$this->assertTrue( $this->gate->should_localize() );
	}

	/**
	 * Cron has no viewer, therefore no display language.
	 *
	 * @return void
	 */
	public function test_cron_does_not_localize() {
		lmat_test_set( 'doing_cron', true );

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * Order creation is suspended: the snapshot is written from the purchase language,
	 * resolved explicitly, never from ambient context.
	 *
	 * @return void
	 */
	public function test_order_context_does_not_localize() {
		$this->gate->suspend( Context_Gate::CONTEXT_ORDER );

		$this->assertFalse( $this->gate->should_localize() );

		$this->gate->resume( Context_Gate::CONTEXT_ORDER );

		$this->assertTrue( $this->gate->should_localize() );
	}

	/**
	 * Transactional email reads the frozen snapshot, not live translations.
	 *
	 * @return void
	 */
	public function test_email_context_does_not_localize() {
		$this->gate->suspend( Context_Gate::CONTEXT_EMAIL );

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * Suspensions nest: resuming one context does not lift another.
	 *
	 * @return void
	 */
	public function test_suspensions_are_independent() {
		$this->gate->suspend( Context_Gate::CONTEXT_ORDER );
		$this->gate->suspend( Context_Gate::CONTEXT_EMAIL );
		$this->gate->resume( Context_Gate::CONTEXT_ORDER );

		$this->assertFalse( $this->gate->should_localize() );
	}

	/**
	 * REST stays canonical until Phase 3 exposes translations deliberately.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_rest_request_does_not_localize() {
		define( 'REST_REQUEST', true );

		$gate = new Context_Gate();

		$this->assertFalse( $gate->should_localize() );
	}

	/**
	 * WP-CLI has no viewer either.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_wp_cli_does_not_localize() {
		define( 'WP_CLI', true );

		$gate = new Context_Gate();

		$this->assertFalse( $gate->should_localize() );
	}
}
```

The two constant-driven tests run in their own PHP process, because a constant defined in one test method would otherwise leak into every test that follows and make the suite order-dependent.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Context_Gate_Test`
Expected: FAIL — `Class "Linguator\Integrations\woocommerce\Context_Gate" not found`.

- [ ] **Step 3: Write the implementation**

Create `integrations/woocommerce/context-gate.php`:

```php
<?php
/**
 * The single decision every localized display filter consults.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Decides whether the current request may be served localized product text.
 *
 * WooCommerce's view getters fire far beyond the storefront: the admin product list, REST, cron,
 * WP-CLI, order creation, transactional email, CRM exports, feeds and structured data all call
 * them. Localizing in one of those would put translated text where canonical text is expected, and
 * in the order case it would freeze the wrong language into order history permanently.
 *
 * @since 2.3.0
 */
class Context_Gate {

	const CONTEXT_ORDER = 'order';
	const CONTEXT_EMAIL = 'email';

	/**
	 * WooCommerce front end actions that reach admin-ajax with is_admin() true.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	protected $frontend_ajax_actions = array(
		'woocommerce_add_to_cart',
		'woocommerce_get_refreshed_fragments',
		'woocommerce_update_order_review',
		'woocommerce_update_shipping_method',
		'woocommerce_apply_coupon',
		'woocommerce_remove_coupon',
		'woocommerce_checkout',
		'get_variation',
	);

	/**
	 * Contexts currently suspended.
	 *
	 * @since 2.3.0
	 *
	 * @var array<string, bool>
	 */
	protected $suspended = array();

	/**
	 * Brackets the request phases during which localization must not happen.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		// Email bodies read the snapshot frozen at purchase, never live translations.
		add_action( 'woocommerce_email_header', array( $this, 'suspend_email' ), 0 );
		add_action( 'woocommerce_email_footer', array( $this, 'resume_email' ), 999 );
	}

	/**
	 * Suspends a context.
	 *
	 * @since 2.3.0
	 *
	 * @param string $context One of the CONTEXT_* constants.
	 * @return void
	 */
	public function suspend( $context ) {
		$this->suspended[ (string) $context ] = true;
	}

	/**
	 * Resumes a context.
	 *
	 * @since 2.3.0
	 *
	 * @param string $context One of the CONTEXT_* constants.
	 * @return void
	 */
	public function resume( $context ) {
		unset( $this->suspended[ (string) $context ] );
	}

	/**
	 * Suspends the email context. Hook callback.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function suspend_email() {
		$this->suspend( self::CONTEXT_EMAIL );
	}

	/**
	 * Resumes the email context. Hook callback.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function resume_email() {
		$this->resume( self::CONTEXT_EMAIL );
	}

	/**
	 * Tells whether localized text may be served for the current request.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	public function should_localize() {
		if ( ! empty( $this->suspended ) ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}

		if ( ! is_admin() ) {
			return true;
		}

		return $this->is_frontend_ajax();
	}

	/**
	 * Tells whether this admin-side request is really a front end AJAX call.
	 *
	 * WooCommerce's cart, checkout and variation endpoints run through admin-ajax, where
	 * is_admin() is true although a customer is looking at the result.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	protected function is_frontend_ajax() {
		if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
			return false;
		}

		// Read only: this decides presentation, never a state change, so no nonce is involved.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $action, $this->frontend_ajax_actions, true );
	}
}
```

- [ ] **Step 4: Require the file and register the gate**

Add to `tests/bootstrap.php`:

```php
require_once dirname( __DIR__ ) . '/integrations/woocommerce/context-gate.php';
```

Add to `load.php` alongside the other requires, and in `register_shared_mode()`:

```php
		$this->gate = new Context_Gate();
		$this->gate->init();
```

with the property:

```php
	/**
	 * The localization context gate.
	 *
	 * @since 2.3.0
	 *
	 * @var Context_Gate|null
	 */
	protected $gate = null;
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 10 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add integrations/woocommerce/context-gate.php integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-context-gate.php
git commit -m "feat: add the localization context gate"
```

---

### Task 6: The identity switch

Takes products and product taxonomies out of Linguator's translate-by-duplication model. Nothing is deleted: the `lmat_language` terms already attached to existing products stay exactly where they are, which is what makes turning shared mode off a no-op rather than a recovery operation.

**Files:**
- Create: `integrations/woocommerce/translated-object-types.php`
- Modify: `integrations/woocommerce/woocommerce.php`, `integrations/woocommerce/load.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-translated-object-types.php`, `tests/woocommerce/test-coordinator.php`

**Interfaces:**
- Consumes: `Shared_Mode::is_enabled()` through the coordinator.
- Produces `Linguator\Integrations\woocommerce\Translated_Object_Types` with:
  - `const POST_TYPES = array( 'product', 'product_variation' )`
  - `const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' )`
  - `init() : void`
  - `remove_post_types( array $post_types, $is_settings = false ) : array`
  - `remove_taxonomies( array $taxonomies, $is_settings = false ) : array`

- [ ] **Step 1: Write the failing tests**

Create `tests/woocommerce/test-translated-object-types.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Translated_Object_Types_Test`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `integrations/woocommerce/translated-object-types.php`:

```php
<?php
/**
 * Removes products and product taxonomies from Linguator's ordinary translation model.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Takes the WooCommerce object types out of translate-by-duplication.
 *
 * Nothing is deleted. The `lmat_language` terms already attached to existing products are left in
 * place, so turning shared mode off restores today's behaviour with no recovery step.
 *
 * Registered from `lmat_init`, which runs inside Linguator's own `plugins_loaded` priority 1 boot.
 * Linguator caches both lists only once `after_setup_theme` has fired, so this is early enough.
 *
 * @since 2.3.0
 */
class Translated_Object_Types {

	/**
	 * Post types that stop being translated by duplication.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	const POST_TYPES = array( 'product', 'product_variation' );

	/**
	 * Taxonomies that stop being translated by duplication.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'lmat_get_post_types', array( $this, 'remove_post_types' ), 10, 2 );
		add_filter( 'lmat_get_taxonomies', array( $this, 'remove_taxonomies' ), 10, 2 );
	}

	/**
	 * Removes the WooCommerce product post types from the translated list.
	 *
	 * @since 2.3.0
	 *
	 * @param array $post_types  List of post type names.
	 * @param bool  $is_settings True when the Linguator settings screen is building its list.
	 * @return array
	 */
	public function remove_post_types( $post_types, $is_settings = false ) {
		return $this->remove( (array) $post_types, self::POST_TYPES );
	}

	/**
	 * Removes the WooCommerce product taxonomies from the translated list.
	 *
	 * @since 2.3.0
	 *
	 * @param array $taxonomies  List of taxonomy names.
	 * @param bool  $is_settings True when the Linguator settings screen is building its list.
	 * @return array
	 */
	public function remove_taxonomies( $taxonomies, $is_settings = false ) {
		return $this->remove( (array) $taxonomies, self::TAXONOMIES );
	}

	/**
	 * Removes a set of names from a list, whether the list is keyed by name or by position.
	 *
	 * @since 2.3.0
	 *
	 * @param array    $list  The list to filter.
	 * @param string[] $names The names to remove.
	 * @return array
	 */
	protected function remove( array $list, array $names ) {
		$list = array_diff( $list, $names );

		foreach ( $names as $name ) {
			unset( $list[ $name ] );
		}

		return $list;
	}
}
```

The settings screen is filtered along with everything else, on purpose: while shared mode is on, offering products in Linguator's "translate these post types" list would let a user re-enable duplication behind the integration's back.

- [ ] **Step 4: Register and require**

Add to `load.php` and to `tests/bootstrap.php`:

```php
require_once __DIR__ . '/translated-object-types.php';
```

In `register_shared_mode()`, replace the placeholder comment with:

```php
		$this->translated_object_types = new Translated_Object_Types();
		$this->translated_object_types->init();
```

In `tests/woocommerce/test-coordinator.php`, delete the `markTestIncomplete()` line from
`test_shared_mode_on_registers_the_identity_filters`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS, with no incomplete tests remaining.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add integrations/woocommerce/translated-object-types.php integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-translated-object-types.php tests/woocommerce/test-coordinator.php
git commit -m "feat: take products and product taxonomies out of the duplication model"
```

---

### Task 7: Product and variation text rendering

The smallest possible use of the repository, done now rather than later so that the German purchase milestone shows German text rather than only German attribute labels. Three product fields, one variation field, plus the two post-level filters that cover templates reading post fields directly.

**Files:**
- Create: `integrations/woocommerce/frontend.php`
- Modify: `integrations/woocommerce/woocommerce.php`, `integrations/woocommerce/load.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-frontend.php`

**Interfaces:**
- Consumes: `Translation_Repository`, `Context_Gate`.
- Produces `Linguator\Integrations\woocommerce\Frontend` with:
  - `__construct( Translation_Repository $repository, Context_Gate $gate )`
  - `init() : void`
  - `product_name( $value, $product ) : string`, `product_description( $value, $product ) : string`, `product_short_description( $value, $product ) : string`, `variation_description( $value, $variation ) : string`
  - `post_title( $title, $post_id ) : string`, `post_excerpt( $excerpt, $post ) : string`
  - `current_language() : string`, `is_default_language() : bool`

- [ ] **Step 1: Add the harness stubs this task needs**

Append to `tests/bootstrap.php`, and add `'post_types' => array(),` to the reset state:

```php
function linguator_current_language( $field = 'slug' ) {
	return (string) lmat_test_get( 'curlang', 'it' );
}

function linguator_default_language( $field = 'slug' ) {
	return (string) lmat_test_get( 'default_lang', 'it' );
}

function get_post_type( $post = null ) {
	$post_types = (array) lmat_test_get( 'post_types', array() );
	$id         = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;

	return isset( $post_types[ $id ] ) ? $post_types[ $id ] : 'post';
}

/**
 * Minimal WP_Post stand-in.
 *
 * @since 2.3.0
 */
class Lmat_Test_Post {
	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public $ID; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	public function __construct( $id ) {
		$this->ID = (int) $id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
	}
}
```

And the require:

```php
require_once dirname( __DIR__ ) . '/integrations/woocommerce/frontend.php';
```

- [ ] **Step 2: Write the failing tests**

Create `tests/woocommerce/test-frontend.php`:

```php
<?php
/**
 * Covers localized product and variation text.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Frontend_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The renderer under test.
	 *
	 * @var Frontend
	 */
	protected $frontend;

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
		$this->frontend   = new Frontend( $this->repository, new Context_Gate() );

		$this->repository->save(
			42,
			'de',
			array(
				'name'              => array( 'value' => 'Mitgliedsbeitrag', 'source' => 'Tassa di socio' ),
				'description'       => array( 'value' => 'Langer Text', 'source' => 'Testo lungo' ),
				'short_description' => array( 'value' => 'Kurz', 'source' => 'Breve' ),
			),
			0
		);
	}
```

Continue the same class:

```php
	/**
	 * A German request gets the German name.
	 *
	 * @return void
	 */
	public function test_name_is_localized() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'Mitgliedsbeitrag', $this->frontend->product_name( 'Tassa di socio', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * The default language takes the short path: the repository is not consulted at all.
	 *
	 * @return void
	 */
	public function test_default_language_is_not_localized() {
		lmat_test_set( 'curlang', 'it' );

		$this->assertSame( 'Tassa di socio', $this->frontend->product_name( 'Tassa di socio', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * A language with no translation falls back to the source.
	 *
	 * @return void
	 */
	public function test_untranslated_language_falls_back() {
		lmat_test_set( 'curlang', 'fr' );

		$this->assertSame( 'Tassa di socio', $this->frontend->product_name( 'Tassa di socio', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * The admin reads canonical text even in a German session.
	 *
	 * @return void
	 */
	public function test_admin_reads_canonical_text() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_admin', true );

		$this->assertSame( 'Tassa di socio', $this->frontend->product_name( 'Tassa di socio', new Lmat_Test_Product( 42 ) ) );
	}

	/**
	 * Descriptions are localized on the same terms.
	 *
	 * @return void
	 */
	public function test_descriptions_are_localized() {
		lmat_test_set( 'curlang', 'de' );
		$product = new Lmat_Test_Product( 42 );

		$this->assertSame( 'Langer Text', $this->frontend->product_description( 'Testo lungo', $product ) );
		$this->assertSame( 'Kurz', $this->frontend->product_short_description( 'Breve', $product ) );
	}

	/**
	 * A variation's own description is localized against the variation ID, not the parent's.
	 *
	 * @return void
	 */
	public function test_variation_description_is_localized() {
		lmat_test_set( 'curlang', 'de' );
		$this->repository->save( 77, 'de', array( 'description' => array( 'value' => 'Variante DE', 'source' => 'Variante IT' ) ), 0 );

		$variation = new Lmat_Test_Product( 77, 'variation', 42 );

		$this->assertSame( 'Variante DE', $this->frontend->variation_description( 'Variante IT', $variation ) );
	}

	/**
	 * the_title is localized for a product read straight from the post.
	 *
	 * @return void
	 */
	public function test_post_title_is_localized_for_products() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'post_types', array( 42 => 'product' ) );

		$this->assertSame( 'Mitgliedsbeitrag', $this->frontend->post_title( 'Tassa di socio', 42 ) );
	}

	/**
	 * the_title leaves everything that is not a product alone.
	 *
	 * @return void
	 */
	public function test_post_title_ignores_other_post_types() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'post_types', array( 42 => 'page' ) );

		$this->assertSame( 'Tassa di socio', $this->frontend->post_title( 'Tassa di socio', 42 ) );
	}

	/**
	 * get_the_excerpt maps to the short description and accepts a post object.
	 *
	 * @return void
	 */
	public function test_post_excerpt_is_localized_for_products() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'post_types', array( 42 => 'product' ) );

		$this->assertSame( 'Kurz', $this->frontend->post_excerpt( 'Breve', new Lmat_Test_Post( 42 ) ) );
	}

	/**
	 * All six filters are registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_display_filters() {
		$this->frontend->init();

		$this->assertTrue( has_filter( 'woocommerce_product_get_name' ) );
		$this->assertTrue( has_filter( 'woocommerce_product_get_description' ) );
		$this->assertTrue( has_filter( 'woocommerce_product_get_short_description' ) );
		$this->assertTrue( has_filter( 'woocommerce_product_variation_get_description' ) );
		$this->assertTrue( has_filter( 'the_title' ) );
		$this->assertTrue( has_filter( 'get_the_excerpt' ) );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Frontend_Test`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the implementation**

Create `integrations/woocommerce/frontend.php`:

```php
<?php
/**
 * Localized product and variation text.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Serves translated text through WooCommerce's own view getters.
 *
 * Templates, blocks and the Store API all end up calling these getters, so filtering them covers
 * far more surface than filtering templates would. Every call is gated: see Context_Gate.
 *
 * @since 2.3.0
 */
class Frontend {

	/**
	 * Post types whose post-level text belongs to a shared product.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	const POST_TYPES = array( 'product', 'product_variation' );

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The localization context gate.
	 *
	 * @since 2.3.0
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 * @param Context_Gate           $gate       The localization context gate.
	 */
	public function __construct( Translation_Repository $repository, Context_Gate $gate ) {
		$this->repository = $repository;
		$this->gate       = $gate;
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_product_get_name', array( $this, 'product_name' ), 10, 2 );
		add_filter( 'woocommerce_product_get_description', array( $this, 'product_description' ), 10, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'product_short_description' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_description', array( $this, 'variation_description' ), 10, 2 );
		add_filter( 'the_title', array( $this, 'post_title' ), 10, 2 );
		add_filter( 'get_the_excerpt', array( $this, 'post_excerpt' ), 10, 2 );
	}

	/**
	 * Returns the current language slug.
	 *
	 * @since 2.3.0
	 *
	 * @return string Empty when Linguator cannot tell.
	 */
	public function current_language() {
		return function_exists( 'linguator_current_language' ) ? (string) linguator_current_language( 'slug' ) : '';
	}

	/**
	 * Tells whether the current language is the site's default.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	public function is_default_language() {
		if ( ! function_exists( 'linguator_default_language' ) ) {
			return true;
		}

		return (string) linguator_default_language( 'slug' ) === $this->current_language();
	}

	/**
	 * Localizes the product name.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value   Canonical name.
	 * @param object $product The product object.
	 * @return string
	 */
	public function product_name( $value, $product ) {
		return $this->localize_object( $value, $product, 'name' );
	}

	/**
	 * Localizes the product description.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value   Canonical description.
	 * @param object $product The product object.
	 * @return string
	 */
	public function product_description( $value, $product ) {
		return $this->localize_object( $value, $product, 'description' );
	}

	/**
	 * Localizes the product short description.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value   Canonical short description.
	 * @param object $product The product object.
	 * @return string
	 */
	public function product_short_description( $value, $product ) {
		return $this->localize_object( $value, $product, 'short_description' );
	}

	/**
	 * Localizes a variation's own description, against the variation ID.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value     Canonical description.
	 * @param object $variation The variation object.
	 * @return string
	 */
	public function variation_description( $value, $variation ) {
		return $this->localize_object( $value, $variation, 'description' );
	}
```

Continue the same class:

```php
	/**
	 * Localizes a title read straight from the post rather than from the product object.
	 *
	 * @since 2.3.0
	 *
	 * @param string $title   Canonical title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function post_title( $title, $post_id = 0 ) {
		return $this->localize_post( $title, $post_id, 'name' );
	}

	/**
	 * Localizes an excerpt read straight from the post.
	 *
	 * @since 2.3.0
	 *
	 * @param string $excerpt Canonical excerpt.
	 * @param object $post    Post object.
	 * @return string
	 */
	public function post_excerpt( $excerpt, $post = null ) {
		$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;

		return $this->localize_post( $excerpt, $post_id, 'short_description' );
	}

	/**
	 * Localizes one field of a product or variation object.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value  Canonical value.
	 * @param object $object The product or variation.
	 * @param string $field  Field name in the repository.
	 * @return string
	 */
	protected function localize_object( $value, $object, $field ) {
		if ( ! is_object( $object ) || ! method_exists( $object, 'get_id' ) ) {
			return $value;
		}

		return $this->localize( $value, (int) $object->get_id(), $field );
	}

	/**
	 * Localizes one field of a post, when that post is a product or a variation.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value   Canonical value.
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name in the repository.
	 * @return string
	 */
	protected function localize_post( $value, $post_id, $field ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 || ! in_array( get_post_type( $post_id ), self::POST_TYPES, true ) ) {
			return $value;
		}

		return $this->localize( $value, $post_id, $field );
	}

	/**
	 * The common path: gate, default-language short circuit, then storage.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value     Canonical value.
	 * @param int    $object_id Post ID of the product or variation.
	 * @param string $field     Field name in the repository.
	 * @return string
	 */
	protected function localize( $value, $object_id, $field ) {
		if ( ! $this->gate->should_localize() ) {
			return $value;
		}

		if ( $this->is_default_language() ) {
			return $value; // Short path: the repository is not consulted at all.
		}

		$language = $this->current_language();

		if ( '' === $language ) {
			return $value;
		}

		return $this->repository->get_value( (int) $object_id, $language, $field, (string) $value );
	}
}
```

- [ ] **Step 5: Register and require**

Add `require_once __DIR__ . '/frontend.php';` to `load.php` and the matching line to `tests/bootstrap.php`.

In `register_shared_mode()`:

```php
		$this->frontend = new Frontend( $this->repository, $this->gate );
		$this->frontend->init();
```

with the property:

```php
	/**
	 * Localized product text.
	 *
	 * @since 2.3.0
	 *
	 * @var Frontend|null
	 */
	protected $frontend = null;
```

`register_shared_mode()` must construct the repository and the gate before the frontend, because the frontend takes both.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 10 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add integrations/woocommerce/frontend.php integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-frontend.php
git commit -m "feat: serve localized product and variation text through the WooCommerce getters"
```

---

### Task 8: Attribute labels and option labels

A variation has no name of its own. What the customer reads in the cart, at checkout and in the order email is the parent product name plus the formatted attribute pairs, so the attribute label map is what makes a German variation line read as German. Canonical option values are never touched: they are what WooCommerce matches on.

**Files:**
- Create: `integrations/woocommerce/attribute-translations.php`
- Modify: `integrations/woocommerce/woocommerce.php`, `integrations/woocommerce/load.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-attribute-translations.php`

**Interfaces:**
- Consumes: `Translation_Repository`, `Context_Gate`, `Frontend::current_language()` / `is_default_language()`.
- Produces `Linguator\Integrations\woocommerce\Attribute_Translations` with:
  - `const FIELD_LABEL_PREFIX = 'attr_label::'`, `const FIELD_OPTION_PREFIX = 'attr_option::'`
  - `static normalize_attribute( string $attribute ) : string`
  - `static label_field( string $attribute ) : string`
  - `static option_field( string $attribute, string $option_value ) : string`
  - `__construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend )`
  - `init() : void`
  - `attribute_label( $label, $name = '', $product = null ) : string`
  - `option_name( $value, $term = null, $taxonomy = '', $product = null ) : string`

- [ ] **Step 1: Write the failing tests**

Create `tests/woocommerce/test-attribute-translations.php`:

```php
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
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$_REQUEST         = array();
		$this->repository = new Translation_Repository();
		$gate             = new Context_Gate();
		$this->attributes = new Attribute_Translations( $this->repository, $gate, new Frontend( $this->repository, $gate ) );

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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Attribute_Translations_Test`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `integrations/woocommerce/attribute-translations.php`:

```php
<?php
/**
 * Translated attribute labels and option labels.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Localizes what an attribute is called, never what it is.
 *
 * Product-local attributes carry a canonical name and canonical option values. Those are what
 * WooCommerce matches on, in the variation form, in `woocommerce_available_variation`, in the AJAX
 * variation response and in add-to-cart. This class stores a label map beside them and localizes
 * only what is rendered. The field key of an option is derived from the canonical value, so a
 * translated label can never shift the key it is stored under.
 *
 * @since 2.3.0
 */
class Attribute_Translations {

	const FIELD_LABEL_PREFIX  = 'attr_label::';
	const FIELD_OPTION_PREFIX = 'attr_option::';

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The localization context gate.
	 *
	 * @since 2.3.0
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * Language helper.
	 *
	 * @since 2.3.0
	 *
	 * @var Frontend
	 */
	protected $frontend;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 * @param Context_Gate           $gate       The localization context gate.
	 * @param Frontend               $frontend   Language helper.
	 */
	public function __construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend ) {
		$this->repository = $repository;
		$this->gate       = $gate;
		$this->frontend   = $frontend;
	}

	/**
	 * Returns the repository field key holding an attribute's label.
	 *
	 * @since 2.3.0
	 *
	 * @param string $attribute Canonical attribute name.
	 * @return string
	 */
	public static function label_field( $attribute ) {
		return self::FIELD_LABEL_PREFIX . self::normalize_attribute( $attribute );
	}

	/**
	 * Reduces the spellings of one attribute to a single key.
	 *
	 * WooCommerce hands the same attribute to the display hooks under different names depending on
	 * the call site: the variation form passes the raw attribute key, while the cart passes the
	 * value of `wc_attribute_taxonomy_name()`, which prefixes `pa_`. Order item meta adds
	 * `attribute_`. Keying on the raw string would store one label per call site.
	 *
	 * On a site that has both a global `pa_size` taxonomy and a product-local attribute named
	 * "Size", the two collapse to the same key. The target site has zero global attribute
	 * taxonomies, so this cannot happen there; it is recorded as a known limitation rather than
	 * solved with a longer key that would still be ambiguous.
	 *
	 * @since 2.3.0
	 *
	 * @param string $attribute Attribute name in any of its spellings.
	 * @return string
	 */
	public static function normalize_attribute( $attribute ) {
		$attribute = (string) $attribute;

		if ( 0 === strpos( $attribute, 'attribute_' ) ) {
			$attribute = substr( $attribute, strlen( 'attribute_' ) );
		}

		if ( 0 === strpos( $attribute, 'pa_' ) ) {
			$attribute = substr( $attribute, strlen( 'pa_' ) );
		}

		return sanitize_title( urldecode( $attribute ) );
	}

	/**
	 * Returns the repository field key holding one option's label.
	 *
	 * @since 2.3.0
	 *
	 * @param string $attribute    Canonical attribute name.
	 * @param string $option_value Canonical option value.
	 * @return string
	 */
	public static function option_field( $attribute, $option_value ) {
		return self::FIELD_OPTION_PREFIX . self::normalize_attribute( $attribute ) . '::' . Translation_Repository::hash( (string) $option_value );
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_attribute_label', array( $this, 'attribute_label' ), 10, 3 );
		add_filter( 'woocommerce_variation_option_name', array( $this, 'option_name' ), 10, 4 );
	}

	/**
	 * Localizes the label of an attribute.
	 *
	 * @since 2.3.0
	 *
	 * @param string $label   Canonical label.
	 * @param string $name    Canonical attribute name.
	 * @param object $product The product the attribute belongs to, when WooCommerce knows it.
	 * @return string
	 */
	public function attribute_label( $label, $name = '', $product = null ) {
		$product_id = $this->owner_id( $product );

		if ( $product_id <= 0 ) {
			return $label;
		}

		$attribute = '' === $name ? $label : $name;

		return $this->localize( $label, $product_id, self::label_field( $attribute ) );
	}

	/**
	 * Localizes the label of one option value.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value    Canonical option value.
	 * @param mixed  $term     The term, for taxonomy-based attributes. Null for product-local ones.
	 * @param string $taxonomy Attribute name or taxonomy.
	 * @param object $product  The product or variation being rendered.
	 * @return string
	 */
	public function option_name( $value, $term = null, $taxonomy = '', $product = null ) {
		$product_id = $this->owner_id( $product );

		if ( $product_id <= 0 || '' === (string) $taxonomy ) {
			return $value;
		}

		return $this->localize( $value, $product_id, self::option_field( $taxonomy, $value ) );
	}

	/**
	 * Returns the product ID that owns the label map.
	 *
	 * A variation borrows its parent's map: the attribute belongs to the parent product.
	 *
	 * @since 2.3.0
	 *
	 * @param object $product Product or variation.
	 * @return int 0 when it cannot be determined.
	 */
	protected function owner_id( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return 0;
		}

		if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			return (int) $product->get_parent_id();
		}

		return (int) $product->get_id();
	}

	/**
	 * Gate, default-language short circuit, then storage.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value      Canonical value.
	 * @param int    $product_id Product that owns the map.
	 * @param string $field      Repository field key.
	 * @return string
	 */
	protected function localize( $value, $product_id, $field ) {
		if ( ! $this->gate->should_localize() || $this->frontend->is_default_language() ) {
			return $value;
		}

		$language = $this->frontend->current_language();

		if ( '' === $language ) {
			return $value;
		}

		return $this->repository->get_value( (int) $product_id, $language, $field, (string) $value );
	}
}
```

- [ ] **Step 4: Register and require**

Add `require_once __DIR__ . '/attribute-translations.php';` to `load.php` and to `tests/bootstrap.php`. In `register_shared_mode()`:

```php
		$this->attributes = new Attribute_Translations( $this->repository, $this->gate, $this->frontend );
		$this->attributes->init();
```

with the matching `protected $attributes = null;` property and its docblock.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 9 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add integrations/woocommerce/attribute-translations.php integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-attribute-translations.php
git commit -m "feat: translate attribute and option labels without touching canonical values"
```

---

### Task 9: The variation payload and the matching invariant

The single rule that protects the cart: **a translated label never takes part in variation matching.** WooCommerce keeps matching on canonical option values in the variation form, in `woocommerce_available_variation`, in the AJAX `get_variation` response — which is the same filter — and in add-to-cart. This task localizes the one field in that payload that is text, and pins the invariant with tests that will fail loudly if a later change breaks it.

**Files:**
- Create: `integrations/woocommerce/variations.php`
- Modify: `integrations/woocommerce/woocommerce.php`, `integrations/woocommerce/load.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-variations.php`

**Interfaces:**
- Consumes: `Translation_Repository`, `Context_Gate`, `Frontend`, `Attribute_Translations`.
- Produces `Linguator\Integrations\woocommerce\Variations` with:
  - `__construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend )`
  - `init() : void`
  - `available_variation( array $data, $product = null, $variation = null ) : array`

- [ ] **Step 1: Write the failing tests**

Create `tests/woocommerce/test-variations.php`:

```php
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
	 * Resets the simulated request before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		lmat_test_reset_state();
		$_REQUEST         = array();
		$this->repository = new Translation_Repository();
		$gate             = new Context_Gate();
		$frontend         = new Frontend( $this->repository, $gate );
		$this->variations = new Variations( $this->repository, $gate, $frontend );
		$this->attributes = new Attribute_Translations( $this->repository, $gate, $frontend );

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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Variations_Test`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `integrations/woocommerce/variations.php`:

```php
<?php
/**
 * The variation payload served to the variation form and to AJAX.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Localizes the text in a variation payload, and nothing else.
 *
 * `woocommerce_available_variation` builds what the variation form reads and what the AJAX
 * `get_variation` endpoint returns. Only `variation_description` is text; `attributes`,
 * `variation_id`, `sku` and every price and stock figure are canonical and are what WooCommerce
 * matches an add-to-cart request against. They are returned untouched, deliberately.
 *
 * @since 2.3.0
 */
class Variations {

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The localization context gate.
	 *
	 * @since 2.3.0
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * Language helper.
	 *
	 * @since 2.3.0
	 *
	 * @var Frontend
	 */
	protected $frontend;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 * @param Context_Gate           $gate       The localization context gate.
	 * @param Frontend               $frontend   Language helper.
	 */
	public function __construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend ) {
		$this->repository = $repository;
		$this->gate       = $gate;
		$this->frontend   = $frontend;
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_available_variation', array( $this, 'available_variation' ), 10, 3 );
	}

	/**
	 * Localizes the text of one variation payload.
	 *
	 * @since 2.3.0
	 *
	 * @param array  $data      The payload.
	 * @param object $product   The parent variable product.
	 * @param object $variation The variation.
	 * @return array
	 */
	public function available_variation( $data, $product = null, $variation = null ) {
		if ( ! is_array( $data ) || ! array_key_exists( 'variation_description', $data ) ) {
			return $data;
		}

		if ( ! $this->gate->should_localize() || $this->frontend->is_default_language() ) {
			return $data;
		}

		$language = $this->frontend->current_language();

		if ( '' === $language ) {
			return $data;
		}

		$variation_id = 0;

		if ( is_object( $variation ) && method_exists( $variation, 'get_id' ) ) {
			$variation_id = (int) $variation->get_id();
		} elseif ( isset( $data['variation_id'] ) ) {
			$variation_id = (int) $data['variation_id'];
		}

		if ( $variation_id <= 0 ) {
			return $data;
		}

		$data['variation_description'] = $this->repository->get_value(
			$variation_id,
			$language,
			'description',
			(string) $data['variation_description']
		);

		return $data;
	}
}
```

Nothing localizes `attributes`. That is the whole point of the class, and the reason the tests assert on what is *not* changed rather than only on what is.

- [ ] **Step 4: Register and require**

Add `require_once __DIR__ . '/variations.php';` to `load.php` and to `tests/bootstrap.php`. In `register_shared_mode()`:

```php
		$this->variations = new Variations( $this->repository, $this->gate, $this->frontend );
		$this->variations->init();
```

with the matching `protected $variations = null;` property and its docblock.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 6 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add integrations/woocommerce/variations.php integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-variations.php
git commit -m "feat: localize the variation payload while leaving matching values canonical"
```

---

### Task 10: Cart, checkout and catalogue priming

The cart line and the checkout review table are assembled from the parent product name plus the formatted attribute pairs, so Task 8's two hooks already reach them — provided they are registered, which this task proves by driving the real filter chain rather than calling methods directly. What is genuinely new here is priming: a catalogue or cart loop must never issue one meta query per product per language.

**Files:**
- Create: `integrations/woocommerce/cart.php`
- Modify: `integrations/woocommerce/woocommerce.php`, `integrations/woocommerce/load.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-cart.php`

**Interfaces:**
- Consumes: `Translation_Repository`, `Attribute_Translations`.
- Produces `Linguator\Integrations\woocommerce\Cart` with:
  - `__construct( Translation_Repository $repository )`
  - `init() : void`
  - `prime_cart( $cart = null ) : void`
  - `prime_loop( $posts, $query = null )` — a `the_posts` pass-through that primes and returns `$posts` unchanged

- [ ] **Step 1: Add the harness stub this task needs**

Append to `tests/bootstrap.php`, and add `'cart_items' => array(),` to the reset state:

```php
function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return new Lmat_Test_WC();
}

/**
 * WooCommerce instance stub, exposing only the cart.
 *
 * @since 2.3.0
 */
class Lmat_Test_WC {
	/**
	 * Cart stub.
	 *
	 * @var Lmat_Test_Cart
	 */
	public $cart;

	public function __construct() {
		$this->cart = new Lmat_Test_Cart();
	}
}

/**
 * Cart stub.
 *
 * @since 2.3.0
 */
class Lmat_Test_Cart {
	public function get_cart() {
		return (array) lmat_test_get( 'cart_items', array() );
	}
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/woocommerce/test-cart.php`:

```php
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
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Cart_Test`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the implementation**

Create `integrations/woocommerce/cart.php`:

```php
<?php
/**
 * Meta cache priming for cart and catalogue loops.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Warms WordPress's meta cache for the objects a page is about to render.
 *
 * The cart line and the checkout review table are assembled from the parent product name and the
 * formatted attribute pairs, which the attribute hooks already localize. What those hooks must not
 * do is read storage one product at a time, so the ID set is primed before the loop starts.
 *
 * @since 2.3.0
 */
class Cart {

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 */
	public function __construct( Translation_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'the_posts', array( $this, 'prime_loop' ), 10, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'prime_cart' ), 10, 1 );
	}

	/**
	 * Primes every product and variation currently in the cart.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $cart The cart, when WooCommerce passes one. Unused: the session cart is read.
	 * @return void
	 */
	public function prime_cart( $cart = null ) {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();

		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! method_exists( $wc->cart, 'get_cart' ) ) {
			return;
		}

		$ids = array();

		foreach ( (array) $wc->cart->get_cart() as $item ) {
			if ( ! empty( $item['product_id'] ) ) {
				$ids[] = (int) $item['product_id'];
			}

			if ( ! empty( $item['variation_id'] ) ) {
				$ids[] = (int) $item['variation_id'];
			}
		}

		$this->repository->prime( $ids );
	}

	/**
	 * Primes a query's whole result set and returns it unchanged.
	 *
	 * @since 2.3.0
	 *
	 * @param array $posts The posts the query found.
	 * @param mixed $query The query. Unused.
	 * @return array The posts, untouched.
	 */
	public function prime_loop( $posts, $query = null ) {
		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}

		$ids = array();

		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$ids[] = (int) $post->ID;
			}
		}

		$this->repository->prime( $ids );

		return $posts;
	}
}
```

`prime_loop()` primes every query, not only product queries. That is deliberate and cheap: `update_postmeta_cache()` is a no-op for IDs already in the cache, and WP_Query primes its own results anyway, so the call costs one array pass. Narrowing it to product queries would mean guessing at post types before the loop has told us what it found.

- [ ] **Step 5: Register and require**

Add `require_once __DIR__ . '/cart.php';` to `load.php` and to `tests/bootstrap.php`. In `register_shared_mode()`:

```php
		$this->cart = new Cart( $this->repository );
		$this->cart->init();
```

with the matching `protected $cart = null;` property and its docblock.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 5 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add integrations/woocommerce/cart.php integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-cart.php
git commit -m "feat: prime translation storage for cart and catalogue loops"
```

---

### Task 11: Order snapshots — MILESTONE

The order is where a mistake becomes permanent. At creation the integration records the language the purchase was made in and the text the customer actually saw. From then on the order never changes when a viewer's language changes. The canonical order item name stays canonical; the displayed text is added beside it.

This task ends with the milestone the ordering of this plan was chosen for: **a complete purchase of a variable product in German on the clone.**

**Files:**
- Create: `integrations/woocommerce/orders.php`
- Modify: `integrations/woocommerce/woocommerce.php`, `integrations/woocommerce/load.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-orders.php`

**Interfaces:**
- Consumes: `Translation_Repository`, `Context_Gate`, `Frontend`.
- Produces `Linguator\Integrations\woocommerce\Orders` with:
  - `const ORDER_META_LANGUAGE = '_lmat_purchase_language'`, `const ITEM_META_NAME = '_lmat_display_name'`
  - `__construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend )`
  - `init() : void`
  - `capture_language( $order, $data = array() ) : void`
  - `capture_item_text( $item, $cart_item_key = '', $values = array(), $order = null ) : void`
  - `order_item_name( $name, $item = null ) : string`
  - `resume_localization() : void`

- [ ] **Step 1: Add the order stubs**

Append to `tests/bootstrap.php`:

```php
/**
 * Order stub carrying meta.
 *
 * @since 2.3.0
 */
class Lmat_Test_Order {
	/**
	 * Meta store.
	 *
	 * @var array
	 */
	protected $meta = array();

	public function add_meta_data( $key, $value, $unique = false ) {
		$this->meta[ $key ] = $value;
	}

	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
}

/**
 * Order line item stub.
 *
 * @since 2.3.0
 */
class Lmat_Test_Order_Item extends Lmat_Test_Order {
	/**
	 * Product ID.
	 *
	 * @var int
	 */
	protected $product_id;

	/**
	 * Variation ID, 0 for a simple product.
	 *
	 * @var int
	 */
	protected $variation_id;

	public function __construct( $product_id, $variation_id = 0 ) {
		$this->product_id   = (int) $product_id;
		$this->variation_id = (int) $variation_id;
	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_variation_id() {
		return $this->variation_id;
	}
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/woocommerce/test-orders.php`:

```php
<?php
/**
 * Covers the order language and the text frozen at purchase.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Orders;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Orders_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The gate, shared with the adapter so suspension can be observed.
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * The adapter under test.
	 *
	 * @var Orders
	 */
	protected $orders;

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
		$this->orders     = new Orders( $this->repository, $this->gate, new Frontend( $this->repository, $this->gate ) );

		$this->repository->save( 42, 'de', array( 'name' => array( 'value' => 'Mitgliedsbeitrag', 'source' => 'Tassa di socio' ) ), 0 );
	}

	/**
	 * The order records the language the purchase was made in.
	 *
	 * @return void
	 */
	public function test_purchase_language_is_recorded() {
		lmat_test_set( 'curlang', 'de' );
		$order = new Lmat_Test_Order();

		$this->orders->capture_language( $order );

		$this->assertSame( 'de', $order->get_meta( Orders::ORDER_META_LANGUAGE ) );
	}

	/**
	 * Creating an order suspends localization, so core order fields stay canonical.
	 *
	 * @return void
	 */
	public function test_order_creation_suspends_localization() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertTrue( $this->gate->should_localize() );

		$this->orders->capture_language( new Lmat_Test_Order() );

		$this->assertFalse( $this->gate->should_localize() );

		$this->orders->resume_localization();

		$this->assertTrue( $this->gate->should_localize() );
	}

	/**
	 * The line item carries the text the customer saw, resolved from the purchase language
	 * recorded on the order and not from whatever context is saving it.
	 *
	 * @return void
	 */
	public function test_item_text_is_frozen_in_the_purchase_language() {
		lmat_test_set( 'curlang', 'de' );
		$order = new Lmat_Test_Order();
		$this->orders->capture_language( $order );

		$item = new Lmat_Test_Order_Item( 42 );
		$this->orders->capture_item_text( $item, 'key1', array(), $order );

		$this->assertSame( 'Mitgliedsbeitrag', $item->get_meta( Orders::ITEM_META_NAME ) );
	}

	/**
	 * A purchase in the default language stores no display text: there is nothing to add.
	 *
	 * @return void
	 */
	public function test_default_language_purchase_stores_no_display_text() {
		lmat_test_set( 'curlang', 'it' );
		$order = new Lmat_Test_Order();
		$this->orders->capture_language( $order );

		$item = new Lmat_Test_Order_Item( 42 );
		$this->orders->capture_item_text( $item, 'key1', array(), $order );

		$this->assertSame( '', $item->get_meta( Orders::ITEM_META_NAME ) );
	}

	/**
	 * A variation freezes its own text, against the variation ID.
	 *
	 * @return void
	 */
	public function test_variation_item_uses_the_variation_id() {
		lmat_test_set( 'curlang', 'de' );
		$this->repository->save( 77, 'de', array( 'name' => array( 'value' => 'Variante DE', 'source' => 'Variante IT' ) ), 0 );

		$order = new Lmat_Test_Order();
		$this->orders->capture_language( $order );

		$item = new Lmat_Test_Order_Item( 42, 77 );
		$this->orders->capture_item_text( $item, 'key1', array(), $order );

		$this->assertSame( 'Variante DE', $item->get_meta( Orders::ITEM_META_NAME ) );
	}

	/**
	 * A historical order reads the same however the viewer's language changes.
	 *
	 * @return void
	 */
	public function test_frozen_text_is_displayed_regardless_of_the_viewer_language() {
		$item = new Lmat_Test_Order_Item( 42 );
		$item->add_meta_data( Orders::ITEM_META_NAME, 'Mitgliedsbeitrag' );

		lmat_test_set( 'curlang', 'fr' );

		$this->assertSame( 'Mitgliedsbeitrag', $this->orders->order_item_name( 'Tassa di socio', $item ) );
	}

	/**
	 * An order placed before shared mode existed keeps its canonical name.
	 *
	 * @return void
	 */
	public function test_orders_without_a_snapshot_are_untouched() {
		$item = new Lmat_Test_Order_Item( 42 );

		$this->assertSame( 'Tassa di socio', $this->orders->order_item_name( 'Tassa di socio', $item ) );
	}

	/**
	 * The hooks are registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_order_hooks() {
		$this->orders->init();

		$this->assertTrue( has_filter( 'woocommerce_checkout_create_order' ) );
		$this->assertTrue( has_filter( 'woocommerce_checkout_create_order_line_item' ) );
		$this->assertTrue( has_filter( 'woocommerce_order_item_name' ) );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Orders_Test`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the implementation**

Create `integrations/woocommerce/orders.php`:

```php
<?php
/**
 * Purchase language and the product text frozen at order creation.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Freezes what the customer read into the order, once, at creation.
 *
 * Order data is written once and never rewritten by a later translation edit, so refunds, emails,
 * exports and CRM reads keep referring to the same canonical IDs and to the same words the
 * customer agreed to buy.
 *
 * Localization is suspended for the whole of order creation. The display text is then resolved
 * explicitly from the language recorded on the order, never from ambient request context: an admin,
 * a cron job or a webhook must not be able to decide what language an order was placed in.
 *
 * @since 2.3.0
 */
class Orders {

	const ORDER_META_LANGUAGE = '_lmat_purchase_language';
	const ITEM_META_NAME      = '_lmat_display_name';

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The localization context gate.
	 *
	 * @since 2.3.0
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * Language helper.
	 *
	 * @since 2.3.0
	 *
	 * @var Frontend
	 */
	protected $frontend;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 * @param Context_Gate           $gate       The localization context gate.
	 * @param Frontend               $frontend   Language helper.
	 */
	public function __construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend ) {
		$this->repository = $repository;
		$this->gate       = $gate;
		$this->frontend   = $frontend;
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_language' ), 0, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'capture_item_text' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'resume_localization' ), 0 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'resume_localization' ), 0 );
		add_filter( 'woocommerce_order_item_name', array( $this, 'order_item_name' ), 10, 2 );
	}

	/**
	 * Records the purchase language and suspends localization for the rest of order creation.
	 *
	 * @since 2.3.0
	 *
	 * @param object $order The order being created.
	 * @param array  $data  Checkout data. Unused.
	 * @return void
	 */
	public function capture_language( $order, $data = array() ) {
		if ( is_object( $order ) && method_exists( $order, 'add_meta_data' ) ) {
			$order->add_meta_data( self::ORDER_META_LANGUAGE, $this->frontend->current_language(), true );
		}

		$this->gate->suspend( Context_Gate::CONTEXT_ORDER );
	}

	/**
	 * Lifts the order suspension once the order has been created.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function resume_localization() {
		$this->gate->resume( Context_Gate::CONTEXT_ORDER );
	}

	/**
	 * Freezes the text the customer saw onto the line item.
	 *
	 * @since 2.3.0
	 *
	 * @param object $item           The order line item.
	 * @param string $cart_item_key  Cart item key. Unused.
	 * @param array  $values         Cart item values. Unused.
	 * @param object $order          The order being created.
	 * @return void
	 */
	public function capture_item_text( $item, $cart_item_key = '', $values = array(), $order = null ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		$language = $this->purchase_language( $order );

		if ( '' === $language || $language === (string) linguator_default_language( 'slug' ) ) {
			return; // The canonical order item name already says it.
		}

		$object_id = method_exists( $item, 'get_variation_id' ) && (int) $item->get_variation_id() > 0
			? (int) $item->get_variation_id()
			: (int) $item->get_product_id();

		if ( $object_id <= 0 ) {
			return;
		}

		$record = $this->repository->get_record( $object_id, $language );

		if ( ! isset( $record['fields']['name']['value'] ) ) {
			return; // Nothing was translated: the canonical name is what the customer read.
		}

		$item->add_meta_data( self::ITEM_META_NAME, (string) $record['fields']['name']['value'], true );
	}

	/**
	 * Serves the frozen text whenever the order has one.
	 *
	 * Deliberately independent of the viewer's language: a historical order must read the same to
	 * everyone, forever.
	 *
	 * @since 2.3.0
	 *
	 * @param string $name Canonical order item name.
	 * @param object $item The order line item.
	 * @return string
	 */
	public function order_item_name( $name, $item = null ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return $name;
		}

		$frozen = (string) $item->get_meta( self::ITEM_META_NAME, true );

		return '' === $frozen ? $name : $frozen;
	}

	/**
	 * Returns the language recorded on an order.
	 *
	 * @since 2.3.0
	 *
	 * @param object $order The order.
	 * @return string Empty when it cannot be determined.
	 */
	protected function purchase_language( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}

		return (string) $order->get_meta( self::ORDER_META_LANGUAGE, true );
	}
}
```

- [ ] **Step 5: Register and require**

Add `require_once __DIR__ . '/orders.php';` to `load.php` and to `tests/bootstrap.php`. In `register_shared_mode()`:

```php
		$this->orders = new Orders( $this->repository, $this->gate, $this->frontend );
		$this->orders->init();
```

with the matching `protected $orders = null;` property and its docblock.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 8 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add integrations/woocommerce/orders.php integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-orders.php
git commit -m "feat: freeze purchase language and displayed text into orders"
```

- [ ] **Step 8: Check the clone is healthy before trusting anything it says**

After any machine restart, four things must be true. Skipping this has cost hours before.

1. **Docker must be off.** It holds ports 80 and 443 through the WSL network layer and stops Local's router. It does not appear as a listening socket on the host, so `netstat` will not reveal it.
2. **The MySQL port moves** across restarts. Read the current one and make `wp-config.php` agree:

```bash
netstat -ano | grep LISTENING | grep -E ":100[0-9][0-9] "
grep DB_HOST /d/Local_Sites/dev-linguatorbeltracing/app/public/wp-config.php
```

If Local reports `Unable to start site` or `Unable to swap services`, a `mysqld` is almost certainly still alive on the previous port. Shut it down gracefully rather than killing it:

```bash
MYSQLADMIN="/c/Users/matte/AppData/Roaming/Local/lightning-services/mysql-8.4.0+2/bin/win64/bin/mysqladmin.exe"
"$MYSQLADMIN" --host=127.0.0.1 --port=<old port> --user=root --password=root shutdown
```

3. **`WP_HTTP_BLOCK_EXTERNAL` stays on** in `wp-config.php`. Without it, plugins call production endpoints on every request and pages exceed Apache's 40-second FastCGI timeout, which surfaces as a 500 rather than as a slow page.
4. **Snippets 17 and 18 stay deactivated**, and the switcher fix stays installed in the clone's plugin directory.

- [ ] **Step 9: Install this branch into the clone**

The clone's plugin files use CRLF while the repository uses LF, so always compare with `diff --strip-trailing-cr` or every file looks changed.

```bash
cd /d/Local_Sites/dev-linguatorbeltracing/app/public
php /c/Users/matte/bin/wp-cli.phar --path=. plugin list --status=active
```

Copy the changed integration files and `includes/options/` changes into
`wp-content/plugins/linguator-multilingual-ai-translation/`, then confirm with a strip-CR diff that
nothing else differs from the branch.

- [ ] **Step 10: Turn shared mode on and record the before state**

Linguator stores its settings in the single `linguator` option.

```bash
cd /d/Local_Sites/dev-linguatorbeltracing/app/public
WP="php /c/Users/matte/bin/wp-cli.phar --path=."

$WP eval 'var_export( get_option( "linguator" )["wc_shared_product_identity"] ?? null );'
$WP eval 'echo wp_count_posts("product")->publish, " publish / ", wp_count_posts("product")->private, " private\n";'
$WP eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"product_variation\""), " variations\n";'
$WP eval '$p = wc_get_product( <variable product id> ); foreach ( $p->get_children() as $id ) { $v = wc_get_product($id); echo $id, " ", $v->get_sku(), " ", $v->get_price(), " ", $v->get_stock_quantity(), "\n"; }'
```

Save that output. It is the baseline the after state is compared against.

Enable shared mode through the REST settings endpoint or, for this verification, directly:

```bash
$WP eval '$o = get_option("linguator"); $o["wc_shared_product_identity"] = true; update_option("linguator", $o); echo "enabled\n";'
```

Then confirm the activation guard agrees the catalogue is clean, which it should, since all 121 products are Italian and none has a translation:

```bash
$WP eval 'var_export( \Linguator\Integrations\woocommerce\Shared_Mode::blockers() );'
```

Expected: `array()`. Anything else stops the milestone: investigate before continuing.

- [ ] **Step 11: Enter German text for one variable product**

Pick one of the 44 variable products. Through the repository, give it a German name, a German attribute label and German option labels for the variation you intend to buy. The entry panel does not exist until Task 16, so do it directly:

```bash
$WP eval '
$r = new \Linguator\Integrations\woocommerce\Translation_Repository();
$id = <variable product id>;
$p  = wc_get_product( $id );
$r->save( $id, "de", array( "name" => array( "value" => "<German name>", "source" => $p->get_name() ) ), 0 );
echo "saved\n";'
```

Add the attribute label and option label fields the same way, using
`Attribute_Translations::label_field()` and `::option_field()` to build the keys from the canonical
attribute name and canonical option value read off the product.

- [ ] **Step 12: Buy it in German**

In a browser, on `http://dev-linguatorbeltracing.local/de/`:

1. Open the product. The name and the attribute labels must read German; the SKU and price must be unchanged from the Italian page.
2. Select the variation. The dropdown option must read German.
3. Add to cart. The cart line must show the German product name and German attribute pair.
4. Complete checkout with an offline payment method.

- [ ] **Step 13: Prove the purchase bought the right thing**

```bash
$WP eval '
$o = wc_get_order( <order id> );
echo "order language: ", $o->get_meta( "_lmat_purchase_language" ), "\n";
foreach ( $o->get_items() as $item ) {
  echo "product ", $item->get_product_id(), " variation ", $item->get_variation_id(), "\n";
  echo "canonical name: ", $item->get_name(), "\n";
  echo "frozen name: ", $item->get_meta( "_lmat_display_name" ), "\n";
}'
```

Every one of these must hold:

- the purchase language is `de`
- the variation ID is the same one the Italian page offers for the same option values
- the SKU, price and stock movement match the baseline recorded in Step 10
- the product and variation counts are unchanged: no new post was created by any of this
- the frozen item name is German, and the canonical `get_name()` is Italian

- [ ] **Step 14: Buy the same variation in Italian and compare**

Repeat the purchase on `http://dev-linguatorbeltracing.local/` with the same option values. The two
orders must reference **the same variation ID**. If they do not, stop: the architecture does not hold
the invariant, and that is exactly what this milestone exists to find out early.

- [ ] **Step 15: Record the milestone**

Write the measured before and after figures, the two order IDs and the variation ID into
`docs/superpowers/root-cause/2026-XX-XX-woocommerce-shared-identity-milestone.md` on `main`, with the
commands used. Project documentation stays on `main` and never enters the pull request.

---

### Task 12: Term names and descriptions

Category labels are part of the storefront a customer reads. With products shared, leaving categories as translated taxonomies would put two contradictory models in the same query layer, so they get the same treatment: one canonical term, translated label stored beside it.

**Files:**
- Create: `integrations/woocommerce/term-translations.php`
- Modify: `integrations/woocommerce/woocommerce.php`, `integrations/woocommerce/load.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-term-translations.php`

**Interfaces:**
- Consumes: `Translation_Repository`, `Context_Gate`, `Frontend`.
- Produces `Linguator\Integrations\woocommerce\Term_Translations` with:
  - `const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' )`
  - `__construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend )`
  - `init() : void`
  - `localize_term( $term, $taxonomy = '' )`
  - `localize_terms( $terms, $taxonomies = array(), $args = array(), $query = null )`
  - `localize_object_terms( $terms, $object_ids = array(), $taxonomies = array(), $args = array() )`

- [ ] **Step 1: Add the term stub**

Append to `tests/bootstrap.php`:

```php
/**
 * Minimal WP_Term stand-in.
 *
 * @since 2.3.0
 */
class WP_Term {
	/**
	 * Term ID.
	 *
	 * @var int
	 */
	public $term_id;

	/**
	 * Taxonomy name.
	 *
	 * @var string
	 */
	public $taxonomy;

	/**
	 * Term name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Term description.
	 *
	 * @var string
	 */
	public $description;

	public function __construct( $term_id, $taxonomy = 'product_cat', $name = '', $description = '' ) {
		$this->term_id     = (int) $term_id;
		$this->taxonomy    = $taxonomy;
		$this->name        = $name;
		$this->description = $description;
	}
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/woocommerce/test-term-translations.php`:

```php
<?php
/**
 * Covers translated product term labels.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Term_Translations;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Term_Translations_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The adapter under test.
	 *
	 * @var Term_Translations
	 */
	protected $terms;

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
		$gate             = new Context_Gate();
		$this->terms      = new Term_Translations( $this->repository, $gate, new Frontend( $this->repository, $gate ) );

		$this->repository->save(
			9,
			'de',
			array(
				'name'        => array( 'value' => 'Bekleidung', 'source' => 'Abbigliamento' ),
				'description' => array( 'value' => 'Beschreibung', 'source' => 'Descrizione' ),
			),
			0,
			Translation_Repository::TYPE_TERM
		);
	}

	/**
	 * A product category reads German.
	 *
	 * @return void
	 */
	public function test_term_name_and_description_are_localized() {
		lmat_test_set( 'curlang', 'de' );

		$term = $this->terms->localize_term( new WP_Term( 9, 'product_cat', 'Abbigliamento', 'Descrizione' ), 'product_cat' );

		$this->assertSame( 'Bekleidung', $term->name );
		$this->assertSame( 'Beschreibung', $term->description );
	}

	/**
	 * The cached term object is never mutated: WordPress hands out the same instance to every
	 * caller in the request, so translating it in place would poison canonical reads.
	 *
	 * @return void
	 */
	public function test_the_cached_term_object_is_not_mutated() {
		lmat_test_set( 'curlang', 'de' );

		$original = new WP_Term( 9, 'product_cat', 'Abbigliamento', 'Descrizione' );
		$localized = $this->terms->localize_term( $original, 'product_cat' );

		$this->assertSame( 'Abbigliamento', $original->name );
		$this->assertNotSame( $original, $localized );
	}

	/**
	 * Taxonomies outside the product family are left alone.
	 *
	 * @return void
	 */
	public function test_other_taxonomies_are_untouched() {
		lmat_test_set( 'curlang', 'de' );

		$term = $this->terms->localize_term( new WP_Term( 9, 'category', 'Abbigliamento' ), 'category' );

		$this->assertSame( 'Abbigliamento', $term->name );
	}

	/**
	 * The default language and the admin read canonical labels.
	 *
	 * @return void
	 */
	public function test_canonical_contexts_are_untouched() {
		lmat_test_set( 'curlang', 'it' );
		$this->assertSame( 'Abbigliamento', $this->terms->localize_term( new WP_Term( 9, 'product_cat', 'Abbigliamento' ), 'product_cat' )->name );

		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_admin', true );
		$this->assertSame( 'Abbigliamento', $this->terms->localize_term( new WP_Term( 9, 'product_cat', 'Abbigliamento' ), 'product_cat' )->name );
	}

	/**
	 * A list is localized term by term, and a list of IDs is passed straight through.
	 *
	 * @return void
	 */
	public function test_term_lists_are_handled() {
		lmat_test_set( 'curlang', 'de' );

		$localized = $this->terms->localize_terms( array( new WP_Term( 9, 'product_cat', 'Abbigliamento' ) ) );
		$this->assertSame( 'Bekleidung', $localized[0]->name );

		$this->assertSame( array( 9, 10 ), $this->terms->localize_terms( array( 9, 10 ) ) );
	}

	/**
	 * All three read paths are registered.
	 *
	 * @return void
	 */
	public function test_init_registers_the_read_filters() {
		$this->terms->init();

		$this->assertTrue( has_filter( 'get_term' ) );
		$this->assertTrue( has_filter( 'get_terms' ) );
		$this->assertTrue( has_filter( 'wp_get_object_terms' ) );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Term_Translations_Test`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the implementation**

Create `integrations/woocommerce/term-translations.php`:

```php
<?php
/**
 * Translated labels for product categories, tags and brands.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use WP_Term;

/**
 * Localizes what a product term is called, never what it is.
 *
 * Term IDs and slugs are canonical: they are what queries, permalinks and product relationships
 * are built on. Only the name and the description are translated, and only on read.
 *
 * Every localized term is a clone. WordPress caches term objects and hands the same instance to
 * every caller in a request, so translating one in place would poison canonical reads later in the
 * same request.
 *
 * @since 2.3.0
 */
class Term_Translations {

	/**
	 * Taxonomies whose labels are translated.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The localization context gate.
	 *
	 * @since 2.3.0
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * Language helper.
	 *
	 * @since 2.3.0
	 *
	 * @var Frontend
	 */
	protected $frontend;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 * @param Context_Gate           $gate       The localization context gate.
	 * @param Frontend               $frontend   Language helper.
	 */
	public function __construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend ) {
		$this->repository = $repository;
		$this->gate       = $gate;
		$this->frontend   = $frontend;
	}

	/**
	 * Setups filters.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'get_term', array( $this, 'localize_term' ), 10, 2 );
		add_filter( 'get_terms', array( $this, 'localize_terms' ), 10, 4 );
		add_filter( 'wp_get_object_terms', array( $this, 'localize_object_terms' ), 10, 4 );
	}

	/**
	 * Localizes one term.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed  $term     The term, usually a WP_Term.
	 * @param string $taxonomy Taxonomy name.
	 * @return mixed A localized clone, or the term unchanged.
	 */
	public function localize_term( $term, $taxonomy = '' ) {
		if ( ! $term instanceof WP_Term ) {
			return $term;
		}

		$taxonomy = '' === $taxonomy ? $term->taxonomy : $taxonomy;

		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			return $term;
		}

		$language = $this->language();

		if ( '' === $language ) {
			return $term;
		}

		$localized = clone $term;

		$localized->name = $this->repository->get_value(
			(int) $term->term_id,
			$language,
			'name',
			(string) $term->name,
			Translation_Repository::TYPE_TERM
		);

		$localized->description = $this->repository->get_value(
			(int) $term->term_id,
			$language,
			'description',
			(string) $term->description,
			Translation_Repository::TYPE_TERM
		);

		return $localized;
	}

	/**
	 * Localizes a list of terms.
	 *
	 * `get_terms()` can return IDs, slugs or counts depending on its `fields` argument, so only
	 * WP_Term instances are touched and everything else is passed straight through.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $terms      The query result.
	 * @param mixed $taxonomies Taxonomies queried. Unused.
	 * @param mixed $args       Query arguments. Unused.
	 * @param mixed $query      The term query. Unused.
	 * @return mixed
	 */
	public function localize_terms( $terms, $taxonomies = array(), $args = array(), $query = null ) {
		if ( ! is_array( $terms ) ) {
			return $terms;
		}

		foreach ( $terms as $index => $term ) {
			$terms[ $index ] = $this->localize_term( $term );
		}

		return $terms;
	}

	/**
	 * Localizes the terms attached to an object.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $terms      The terms found.
	 * @param mixed $object_ids Object IDs. Unused.
	 * @param mixed $taxonomies Taxonomies queried. Unused.
	 * @param mixed $args       Query arguments. Unused.
	 * @return mixed
	 */
	public function localize_object_terms( $terms, $object_ids = array(), $taxonomies = array(), $args = array() ) {
		return $this->localize_terms( $terms );
	}

	/**
	 * Returns the language to translate into, or an empty string when there is none.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	protected function language() {
		if ( ! $this->gate->should_localize() || $this->frontend->is_default_language() ) {
			return '';
		}

		return $this->frontend->current_language();
	}
}
```

- [ ] **Step 5: Register and require**

Add `require_once __DIR__ . '/term-translations.php';` to `load.php` and to `tests/bootstrap.php`. In `register_shared_mode()`:

```php
		$this->term_translations = new Term_Translations( $this->repository, $this->gate, $this->frontend );
		$this->term_translations->init();
```

with the matching `protected $term_translations = null;` property and its docblock.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 6 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add integrations/woocommerce/term-translations.php integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-term-translations.php
git commit -m "feat: translate product term names and descriptions"
```

---

### Task 13: Language switching on products and term archives, and the hreflang that follows

`Linguator_Frontend_Links::get_translation_url()` resolves a single post through a **translation post**, which a shared product by definition does not have, and a term archive through its `is_tax()` branch, which `is_translated_taxonomy()` switches off the moment `product_cat` becomes shared. Neither falls through to a usable fallback, so both land on the home page. The same function feeds the alternates loop, so without this task a shared product page emits **no hreflang at all** while being reachable under four language prefixes.

**Files:**
- Modify: `integrations/woocommerce/woocommerce.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-object-translation-url.php`

**Interfaces:**
- Consumes: `Shared_Mode::is_enabled()`, `LMAT()->links_model->switch_language_in_link()`.
- Produces, on `Linguator_WooCommerce`:
  - `product_translation_url( $url, $language ) : string`
  - `term_archive_translation_url( $url, $language ) : string`

- [ ] **Step 1: Add the harness stubs this task needs**

Append to `tests/bootstrap.php`, and add `'queried_object_id' => 0,` plus `'term_links' => array(),` to the reset state:

```php
function get_queried_object_id() {
	return (int) lmat_test_get( 'queried_object_id', 0 );
}

function get_queried_object() {
	return lmat_test_get( 'queried_term', null );
}

function is_tax( $taxonomy = '' ) {
	$current = (string) lmat_test_get( 'is_product_tax', '' );

	if ( '' === $current || false === $current ) {
		return false;
	}

	if ( '' === $taxonomy ) {
		return true;
	}

	return in_array( $current, (array) $taxonomy, true );
}

function get_term_link( $term, $taxonomy = '' ) {
	$links = (array) lmat_test_get( 'term_links', array() );
	$id    = is_object( $term ) ? (int) $term->term_id : (int) $term;

	return isset( $links[ $id ] ) ? $links[ $id ] : false;
}
```

`is_product_tax` holds the taxonomy name of the archive being displayed, or `false` when the request is not a product term archive.

- [ ] **Step 2: Write the failing tests**

Create `tests/woocommerce/test-object-translation-url.php`:

```php
<?php
/**
 * Covers language switching on shared products and product term archives.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Linguator_WooCommerce;

class Object_Translation_Url_Test extends TestCase {

	/**
	 * The integration under test.
	 *
	 * @var Linguator_WooCommerce
	 */
	protected $integration;

	/**
	 * Resets the simulated request before each test.
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
	 * Switching language on a shared product stays on that product.
	 *
	 * @return void
	 */
	public function test_product_keeps_the_visitor_on_the_product() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		lmat_test_set( 'permalinks', array( 42 => 'https://example.test/prodotto/tassa-di-socio/' ) );

		$url = $this->integration->product_translation_url( '', new Lmat_Test_Language( 'de' ) );

		$this->assertSame( 'https://example.test/de/prodotto/tassa-di-socio/', $url );
	}

	/**
	 * The default language gets the unprefixed URL, because its prefix is hidden.
	 *
	 * @return void
	 */
	public function test_default_language_gets_the_canonical_url() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		lmat_test_set( 'permalinks', array( 42 => 'https://example.test/prodotto/tassa-di-socio/' ) );

		$url = $this->integration->product_translation_url( '', new Lmat_Test_Language( 'it' ) );

		$this->assertSame( 'https://example.test/prodotto/tassa-di-socio/', $url );
	}

	/**
	 * Every language resolves, which is what makes the alternates loop print hreflang at all:
	 * it prints nothing unless more than one URL comes back.
	 *
	 * @return void
	 */
	public function test_every_language_resolves_so_hreflang_is_emitted() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		lmat_test_set( 'permalinks', array( 42 => 'https://example.test/prodotto/tassa-di-socio/' ) );

		$urls = array();

		foreach ( array( 'it', 'de', 'fr', 'en' ) as $slug ) {
			$urls[ $slug ] = $this->integration->product_translation_url( '', new Lmat_Test_Language( $slug ) );
		}

		$this->assertCount( 4, array_filter( $urls ) );
		$this->assertCount( 4, array_unique( $urls ) );
	}

	/**
	 * Switching language on a product category archive stays on that archive.
	 *
	 * @return void
	 */
	public function test_product_category_archive_stays_on_the_archive() {
		lmat_test_set( 'is_product_tax', 'product_cat' );
		lmat_test_set( 'queried_term', new WP_Term( 9, 'product_cat', 'Abbigliamento' ) );
		lmat_test_set( 'term_links', array( 9 => 'https://example.test/categoria-prodotto/abbigliamento/' ) );

		$url = $this->integration->term_archive_translation_url( '', new Lmat_Test_Language( 'de' ) );

		$this->assertSame( 'https://example.test/de/categoria-prodotto/abbigliamento/', $url );
	}

	/**
	 * With shared mode off, both handlers stand aside and Linguator resolves as it does today.
	 *
	 * @return void
	 */
	public function test_shared_mode_off_changes_nothing() {
		lmat_test_set( 'options', array() );
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );
		lmat_test_set( 'permalinks', array( 42 => 'https://example.test/prodotto/tassa-di-socio/' ) );

		$this->assertSame( '', $this->integration->product_translation_url( '', new Lmat_Test_Language( 'de' ) ) );
	}

	/**
	 * A URL another handler already resolved is never overwritten.
	 *
	 * @return void
	 */
	public function test_an_already_resolved_url_wins() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );

		$this->assertSame(
			'https://example.test/de/other/',
			$this->integration->product_translation_url( 'https://example.test/de/other/', new Lmat_Test_Language( 'de' ) )
		);
	}

	/**
	 * A request that is neither a product nor a product term archive is left alone.
	 *
	 * @return void
	 */
	public function test_other_requests_are_left_alone() {
		$this->assertSame( '', $this->integration->product_translation_url( '', new Lmat_Test_Language( 'de' ) ) );
		$this->assertSame( '', $this->integration->term_archive_translation_url( '', new Lmat_Test_Language( 'de' ) ) );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Object_Translation_Url_Test`
Expected: FAIL — `Call to undefined method ...::product_translation_url()`.

- [ ] **Step 4: Write the implementation**

Add to `integrations/woocommerce/woocommerce.php`, alongside the existing `shop_translation_url()`. Add `use WP_Term;` to the file's imports.

```php
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
```

- [ ] **Step 5: Register the handlers**

In `register_shared_mode()`:

```php
		// Same priority as the shop handler: each stands aside when another has already resolved a URL.
		add_filter( 'lmat_pre_translation_url', array( $this, 'product_translation_url' ), 15, 2 );
		add_filter( 'lmat_pre_translation_url', array( $this, 'term_archive_translation_url' ), 15, 2 );
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 7 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-object-translation-url.php
git commit -m "fix: keep the language switcher on shared products and product term archives"
```

---

### Task 14: The SEO adapter

With one shared post there is one set of SEO meta, so a German product page would otherwise carry an Italian `<title>` and meta description. SEOPress is the plugin active on the target site — Yoast is installed but not active, and both Yoast and Rank Math already have Linguator integrations, so they are left alone in this phase.

**Files:**
- Create: `integrations/woocommerce/seo.php`
- Modify: `integrations/woocommerce/woocommerce.php`, `integrations/woocommerce/load.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-seo.php`

**Interfaces:**
- Consumes: `Translation_Repository`, `Context_Gate`, `Frontend`.
- Produces `Linguator\Integrations\woocommerce\Seo` with:
  - `const FIELD_TITLE = 'seo_title'`, `FIELD_DESCRIPTION = 'seo_description'`, `FIELD_OG_TITLE = 'og_title'`, `FIELD_OG_DESCRIPTION = 'og_description'`
  - `__construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend )`
  - `init() : void`
  - `title( $value )`, `description( $value )`, `og_title( $value )`, `og_description( $value )`

- [ ] **Step 1: Confirm the SEOPress filter names against the installed plugin**

Do not write the hook names from memory. Read them from the copy running on the clone:

```bash
cd /d/Local_Sites/dev-linguatorbeltracing/app/public/wp-content/plugins/wp-seopress
grep -rn "apply_filters( *'seopress_titles_title'" --include=*.php . | head
grep -rn "apply_filters( *'seopress_titles_desc'" --include=*.php . | head
grep -rn "apply_filters( *'seopress_social_og_title'\|apply_filters( *'seopress_social_og_desc'" --include=*.php . | head
```

Record the exact names and argument counts found, and use those in Step 3. If a name differs from the one below, the plan's name is wrong and the grep is right.

- [ ] **Step 2: Write the failing tests**

Create `tests/woocommerce/test-seo.php`:

```php
<?php
/**
 * Covers translated SEO meta on shared products and product term archives.
 *
 * @package Linguator
 */

use PHPUnit\Framework\TestCase;
use Linguator\Integrations\woocommerce\Context_Gate;
use Linguator\Integrations\woocommerce\Frontend;
use Linguator\Integrations\woocommerce\Seo;
use Linguator\Integrations\woocommerce\Translation_Repository;

class Seo_Test extends TestCase {

	/**
	 * Storage.
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The adapter under test.
	 *
	 * @var Seo
	 */
	protected $seo;

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
		$gate             = new Context_Gate();
		$this->seo        = new Seo( $this->repository, $gate, new Frontend( $this->repository, $gate ) );

		$this->repository->save(
			42,
			'de',
			array(
				Seo::FIELD_TITLE          => array( 'value' => 'Mitgliedsbeitrag kaufen', 'source' => 'Compra la tassa di socio' ),
				Seo::FIELD_DESCRIPTION    => array( 'value' => 'Deutsche Beschreibung', 'source' => 'Descrizione italiana' ),
				Seo::FIELD_OG_TITLE       => array( 'value' => 'OG DE', 'source' => 'OG IT' ),
				Seo::FIELD_OG_DESCRIPTION => array( 'value' => 'OG Beschreibung', 'source' => 'OG descrizione' ),
			),
			0
		);
	}

	/**
	 * All four fields are served on a product page.
	 *
	 * @return void
	 */
	public function test_all_four_fields_are_localized_on_a_product() {
		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );

		$this->assertSame( 'Mitgliedsbeitrag kaufen', $this->seo->title( 'Compra la tassa di socio' ) );
		$this->assertSame( 'Deutsche Beschreibung', $this->seo->description( 'Descrizione italiana' ) );
		$this->assertSame( 'OG DE', $this->seo->og_title( 'OG IT' ) );
		$this->assertSame( 'OG Beschreibung', $this->seo->og_description( 'OG descrizione' ) );
	}

	/**
	 * An untranslated field falls back to what the SEO plugin computed.
	 *
	 * @return void
	 */
	public function test_untranslated_field_falls_back() {
		lmat_test_set( 'curlang', 'fr' );
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );

		$this->assertSame( 'Compra la tassa di socio', $this->seo->title( 'Compra la tassa di socio' ) );
	}

	/**
	 * A product term archive reads its title from the term store.
	 *
	 * @return void
	 */
	public function test_term_archive_uses_the_term_store() {
		$this->repository->save(
			9,
			'de',
			array( Seo::FIELD_TITLE => array( 'value' => 'Bekleidung kaufen', 'source' => 'Compra abbigliamento' ) ),
			0,
			Translation_Repository::TYPE_TERM
		);

		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_product_tax', 'product_cat' );
		lmat_test_set( 'queried_term', new WP_Term( 9, 'product_cat', 'Abbigliamento' ) );

		$this->assertSame( 'Bekleidung kaufen', $this->seo->title( 'Compra abbigliamento' ) );
	}

	/**
	 * Pages that are neither a product nor a product term archive are left to the SEO plugin.
	 *
	 * @return void
	 */
	public function test_other_pages_are_left_alone() {
		lmat_test_set( 'curlang', 'de' );

		$this->assertSame( 'Qualcosa', $this->seo->title( 'Qualcosa' ) );
	}

	/**
	 * The default language and the admin are untouched.
	 *
	 * @return void
	 */
	public function test_canonical_contexts_are_untouched() {
		lmat_test_set( 'is_product', true );
		lmat_test_set( 'queried_object_id', 42 );

		lmat_test_set( 'curlang', 'it' );
		$this->assertSame( 'Compra la tassa di socio', $this->seo->title( 'Compra la tassa di socio' ) );

		lmat_test_set( 'curlang', 'de' );
		lmat_test_set( 'is_admin', true );
		$this->assertSame( 'Compra la tassa di socio', $this->seo->title( 'Compra la tassa di socio' ) );
	}

	/**
	 * Without SEOPress the adapter registers nothing at all.
	 *
	 * @return void
	 */
	public function test_nothing_is_registered_without_seopress() {
		$this->seo->init();

		$this->assertFalse( has_filter( 'seopress_titles_title' ) );
	}

	/**
	 * With SEOPress present the four filters are registered.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_filters_are_registered_with_seopress() {
		define( 'SEOPRESS_VERSION', '8.0' );

		$repository = new Translation_Repository();
		$gate       = new Context_Gate();
		$seo        = new Seo( $repository, $gate, new Frontend( $repository, $gate ) );
		$seo->init();

		$this->assertTrue( has_filter( 'seopress_titles_title' ) );
		$this->assertTrue( has_filter( 'seopress_titles_desc' ) );
		$this->assertTrue( has_filter( 'seopress_social_og_title' ) );
		$this->assertTrue( has_filter( 'seopress_social_og_desc' ) );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Seo_Test`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the implementation**

Create `integrations/woocommerce/seo.php`. Use the hook names confirmed in Step 1 if they differ from these.

```php
<?php
/**
 * Translated SEO meta for shared products and product term archives.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use WP_Term;

/**
 * Serves translated title, meta description and Open Graph text to the active SEO plugin.
 *
 * Written against one SEO plugin at a time. SEOPress is the one in use on the target site and the
 * one implemented in Phase 1; Yoast and Rank Math already have Linguator integrations.
 *
 * Canonical URLs are not touched here: they are produced by the language and links layer. So is
 * hreflang, which the shared-object URL handlers restore.
 *
 * @since 2.3.0
 */
class Seo {

	const FIELD_TITLE          = 'seo_title';
	const FIELD_DESCRIPTION    = 'seo_description';
	const FIELD_OG_TITLE       = 'og_title';
	const FIELD_OG_DESCRIPTION = 'og_description';

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * The localization context gate.
	 *
	 * @since 2.3.0
	 *
	 * @var Context_Gate
	 */
	protected $gate;

	/**
	 * Language helper.
	 *
	 * @since 2.3.0
	 *
	 * @var Frontend
	 */
	protected $frontend;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 * @param Context_Gate           $gate       The localization context gate.
	 * @param Frontend               $frontend   Language helper.
	 */
	public function __construct( Translation_Repository $repository, Context_Gate $gate, Frontend $frontend ) {
		$this->repository = $repository;
		$this->gate       = $gate;
		$this->frontend   = $frontend;
	}

	/**
	 * Setups filters, when an SEO plugin this adapter knows is active.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		if ( ! defined( 'SEOPRESS_VERSION' ) ) {
			return;
		}

		add_filter( 'seopress_titles_title', array( $this, 'title' ), 10, 1 );
		add_filter( 'seopress_titles_desc', array( $this, 'description' ), 10, 1 );
		add_filter( 'seopress_social_og_title', array( $this, 'og_title' ), 10, 1 );
		add_filter( 'seopress_social_og_desc', array( $this, 'og_description' ), 10, 1 );
	}

	/**
	 * Localizes the document title.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value What the SEO plugin computed.
	 * @return string
	 */
	public function title( $value ) {
		return $this->localize( $value, self::FIELD_TITLE );
	}

	/**
	 * Localizes the meta description.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value What the SEO plugin computed.
	 * @return string
	 */
	public function description( $value ) {
		return $this->localize( $value, self::FIELD_DESCRIPTION );
	}

	/**
	 * Localizes the Open Graph title.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value What the SEO plugin computed.
	 * @return string
	 */
	public function og_title( $value ) {
		return $this->localize( $value, self::FIELD_OG_TITLE );
	}

	/**
	 * Localizes the Open Graph description.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value What the SEO plugin computed.
	 * @return string
	 */
	public function og_description( $value ) {
		return $this->localize( $value, self::FIELD_OG_DESCRIPTION );
	}

	/**
	 * Resolves the object being displayed and reads one field for it.
	 *
	 * @since 2.3.0
	 *
	 * @param string $value What the SEO plugin computed, used as the fallback.
	 * @param string $field Repository field key.
	 * @return string
	 */
	protected function localize( $value, $field ) {
		if ( ! $this->gate->should_localize() || $this->frontend->is_default_language() ) {
			return $value;
		}

		$language = $this->frontend->current_language();

		if ( '' === $language ) {
			return $value;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = (int) get_queried_object_id();

			if ( $product_id <= 0 ) {
				return $value;
			}

			return $this->repository->get_value( $product_id, $language, $field, (string) $value );
		}

		if ( function_exists( 'is_tax' ) && is_tax( Term_Translations::TAXONOMIES ) ) {
			$term = get_queried_object();

			if ( ! $term instanceof WP_Term ) {
				return $value;
			}

			return $this->repository->get_value( (int) $term->term_id, $language, $field, (string) $value, Translation_Repository::TYPE_TERM );
		}

		return $value;
	}
}
```

- [ ] **Step 5: Register and require**

Add `require_once __DIR__ . '/seo.php';` to `load.php` and to `tests/bootstrap.php`. In `register_shared_mode()`:

```php
		$this->seo = new Seo( $this->repository, $this->gate, $this->frontend );
		$this->seo->init();
```

with the matching `protected $seo = null;` property and its docblock.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 7 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add integrations/woocommerce/seo.php integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-seo.php
git commit -m "feat: serve translated SEO meta for shared products through SEOPress"
```

---

### Task 15: The minimal entry panel

The panel exists so Phase 1 is usable by a human, not to be the final editing experience. A language selector, the source text read-only beside each field, one input per translatable field — including the four SEO fields and the attribute label map — and the per-field status. No AI, no bulk, no rich editing: those are Phase 2.

The parts worth unit-testing are the two pure ones: which fields the panel offers for a given product, and how a submitted payload is sanitized into the repository's shape. Metabox registration and rendering are verified on the clone.

**Files:**
- Create: `integrations/woocommerce/admin/product-panel.php`, `integrations/woocommerce/admin/views/product-panel.php`
- Modify: `integrations/woocommerce/woocommerce.php`, `integrations/woocommerce/load.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-product-panel.php`

**Interfaces:**
- Consumes: `Translation_Repository`, `Attribute_Translations`, `Seo`.
- Produces `Linguator\Integrations\woocommerce\Admin\Product_Panel` with:
  - `const NONCE_ACTION = 'lmat_wc_product_panel'`, `const NONCE_FIELD = 'lmat_wc_product_panel_nonce'`
  - `const TYPE_TEXT = 'text'`, `const TYPE_HTML = 'html'`
  - `__construct( Translation_Repository $repository )`
  - `init() : void`
  - `descriptors( $product ) : array` — a list of `array( 'key', 'label', 'source', 'type' )`
  - `collect( array $raw, array $descriptors ) : array` — the repository payload
  - `register_metabox( $post_type = '', $post = null ) : void`, `render( $post ) : void`, `save( $post_id, $post = null ) : void`

- [ ] **Step 1: Add the harness stubs**

Append to `tests/bootstrap.php`, and add `'product_attributes' => array(),` to the reset state:

```php
function wp_list_pluck( $list, $field ) {
	$values = array();

	foreach ( (array) $list as $item ) {
		if ( is_array( $item ) && isset( $item[ $field ] ) ) {
			$values[] = $item[ $field ];
		}
	}

	return $values;
}

function lmat_test_product_attributes( $product_id ) {
	$attributes = (array) lmat_test_get( 'product_attributes', array() );

	return isset( $attributes[ (int) $product_id ] ) ? $attributes[ (int) $product_id ] : array();
}
```

Give `Lmat_Test_Product` a `get_attributes()` method returning `lmat_test_product_attributes( $this->id )`, so the panel reads attributes the same way it will from a real `WC_Product`: an attribute name mapped to its list of canonical option values.

Add the require:

```php
require_once dirname( __DIR__ ) . '/integrations/woocommerce/admin/product-panel.php';
```

- [ ] **Step 2: Write the failing tests**

Create `tests/woocommerce/test-product-panel.php`:

```php
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
	 * Text fields are stripped of markup; the two rich fields keep post-safe HTML.
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
	 * fields into the record.
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
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php /tmp/phpunit-9.phar --filter Product_Panel_Test`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the controller**

Create `integrations/woocommerce/admin/product-panel.php`:

```php
<?php
/**
 * The minimal translation entry panel on the product edit screen.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use Linguator\Integrations\woocommerce\Attribute_Translations;
use Linguator\Integrations\woocommerce\Seo;
use Linguator\Integrations\woocommerce\Translation_Repository;

/**
 * Lets a human enter, for one language at a time, everything Phase 1 renders.
 *
 * The rule the field set follows: anything Phase 1 displays must be enterable in Phase 1. A
 * translated field with no input is a field that stays empty forever.
 *
 * @since 2.3.0
 */
class Product_Panel {

	const NONCE_ACTION = 'lmat_wc_product_panel';
	const NONCE_FIELD  = 'lmat_wc_product_panel_nonce';
	const TYPE_TEXT    = 'text';
	const TYPE_HTML    = 'html';

	/**
	 * Translation storage.
	 *
	 * @since 2.3.0
	 *
	 * @var Translation_Repository
	 */
	protected $repository;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param Translation_Repository $repository Translation storage.
	 */
	public function __construct( Translation_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Setups hooks.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 10, 2 );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Registers the metabox on the product edit screen.
	 *
	 * @since 2.3.0
	 *
	 * @param string $post_type Post type being edited.
	 * @param object $post      The post. Unused.
	 * @return void
	 */
	public function register_metabox( $post_type = '', $post = null ) {
		if ( 'product' !== $post_type ) {
			return;
		}

		add_meta_box(
			'lmat-wc-translations',
			esc_html__( 'Translations', 'translate-words' ),
			array( $this, 'render' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Returns the translatable fields for one product.
	 *
	 * @since 2.3.0
	 *
	 * @param object $product The product.
	 * @return array List of array( 'key', 'label', 'source', 'type' ).
	 */
	public function descriptors( $product ) {
		$descriptors = array(
			array(
				'key'    => 'name',
				'label'  => esc_html__( 'Product name', 'translate-words' ),
				'source' => $this->source( $product, 'get_name' ),
				'type'   => self::TYPE_TEXT,
			),
			array(
				'key'    => 'short_description',
				'label'  => esc_html__( 'Short description', 'translate-words' ),
				'source' => $this->source( $product, 'get_short_description' ),
				'type'   => self::TYPE_HTML,
			),
			array(
				'key'    => 'description',
				'label'  => esc_html__( 'Description', 'translate-words' ),
				'source' => $this->source( $product, 'get_description' ),
				'type'   => self::TYPE_HTML,
			),
			array(
				'key'    => Seo::FIELD_TITLE,
				'label'  => esc_html__( 'SEO title', 'translate-words' ),
				'source' => '',
				'type'   => self::TYPE_TEXT,
			),
			array(
				'key'    => Seo::FIELD_DESCRIPTION,
				'label'  => esc_html__( 'Meta description', 'translate-words' ),
				'source' => '',
				'type'   => self::TYPE_TEXT,
			),
			array(
				'key'    => Seo::FIELD_OG_TITLE,
				'label'  => esc_html__( 'Open Graph title', 'translate-words' ),
				'source' => '',
				'type'   => self::TYPE_TEXT,
			),
			array(
				'key'    => Seo::FIELD_OG_DESCRIPTION,
				'label'  => esc_html__( 'Open Graph description', 'translate-words' ),
				'source' => '',
				'type'   => self::TYPE_TEXT,
			),
		);

		foreach ( $this->attributes( $product ) as $attribute => $options ) {
			$descriptors[] = array(
				'key'    => Attribute_Translations::label_field( $attribute ),
				'label'  => sprintf(
					/* translators: %s is an attribute name. */
					esc_html__( 'Attribute label: %s', 'translate-words' ),
					$attribute
				),
				'source' => (string) $attribute,
				'type'   => self::TYPE_TEXT,
			);

			foreach ( (array) $options as $option ) {
				$descriptors[] = array(
					'key'    => Attribute_Translations::option_field( $attribute, $option ),
					'label'  => sprintf(
						/* translators: 1: attribute name, 2: option value. */
						esc_html__( 'Option label: %1$s / %2$s', 'translate-words' ),
						$attribute,
						$option
					),
					'source' => (string) $option,
					'type'   => self::TYPE_TEXT,
				);
			}
		}

		return $descriptors;
	}

	/**
	 * Turns a submitted payload into the repository's field shape.
	 *
	 * Only keys present in the descriptor list are accepted, and each is sanitized by its declared
	 * type. A key absent from the payload is not written at all, which is what keeps "never
	 * translated" distinct from "intentionally empty".
	 *
	 * @since 2.3.0
	 *
	 * @param array $raw         The submitted values, already unslashed.
	 * @param array $descriptors The descriptor list this payload must match.
	 * @return array
	 */
	public function collect( array $raw, array $descriptors ) {
		$fields = array();

		foreach ( $descriptors as $descriptor ) {
			$key = isset( $descriptor['key'] ) ? (string) $descriptor['key'] : '';

			if ( '' === $key || ! array_key_exists( $key, $raw ) ) {
				continue;
			}

			$value = (string) $raw[ $key ];

			$fields[ $key ] = array(
				'value'  => self::TYPE_HTML === $descriptor['type'] ? wp_kses_post( $value ) : sanitize_text_field( $value ),
				'source' => isset( $descriptor['source'] ) ? (string) $descriptor['source'] : '',
			);
		}

		return $fields;
	}

	/**
	 * Reads one source value off the product, tolerating a product object that lacks the getter.
	 *
	 * @since 2.3.0
	 *
	 * @param object $product The product.
	 * @param string $getter  Getter name.
	 * @return string
	 */
	protected function source( $product, $getter ) {
		if ( ! is_object( $product ) || ! method_exists( $product, $getter ) ) {
			return '';
		}

		return (string) $product->{$getter}();
	}

	/**
	 * Returns the product's attributes as name mapped to canonical option values.
	 *
	 * @since 2.3.0
	 *
	 * @param object $product The product.
	 * @return array
	 */
	protected function attributes( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}

		$attributes = array();

		foreach ( (array) $product->get_attributes() as $name => $attribute ) {
			if ( is_object( $attribute ) && method_exists( $attribute, 'get_options' ) ) {
				// WC_Product::get_attributes() keys by the sanitized name; get_name() has the one a human typed.
				$label = method_exists( $attribute, 'get_name' ) ? (string) $attribute->get_name() : (string) $name;

				$attributes[ $label ] = (array) $attribute->get_options();
				continue;
			}

			$attributes[ (string) $name ] = (array) $attribute;
		}

		return $attributes;
	}
}
```

- [ ] **Step 5: Add render and save**

Continue the same class:

```php
	/**
	 * Renders the metabox.
	 *
	 * @since 2.3.0
	 *
	 * @param object $post The product post.
	 * @return void
	 */
	public function render( $post ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : false;

		if ( ! $product ) {
			return;
		}

		$language    = $this->selected_language();
		$descriptors = $this->descriptors( $product );
		$record      = $this->repository->get_record( (int) $post->ID, $language );
		$repository  = $this->repository;

		include __DIR__ . '/views/product-panel.php';
	}

	/**
	 * Stores the submitted translations.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $post_id The product ID.
	 * @param object $post    The post. Unused.
	 * @return void
	 */
	public function save( $post_id, $post = null ) {
		$post_id = (int) $post_id;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$language = isset( $_POST['lmat_wc_language'] ) ? sanitize_key( wp_unslash( $_POST['lmat_wc_language'] ) ) : '';
		$revision = isset( $_POST['lmat_wc_revision'] ) ? absint( wp_unslash( $_POST['lmat_wc_revision'] ) ) : 0;

		if ( '' === $language ) {
			return;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : false;

		if ( ! $product ) {
			return;
		}

		$raw = isset( $_POST['lmat_wc_fields'] ) && is_array( $_POST['lmat_wc_fields'] )
			? array_map( 'wp_unslash', $_POST['lmat_wc_fields'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field type in collect().
			: array();

		$fields = $this->collect( $raw, $this->descriptors( $product ) );

		if ( empty( $fields ) ) {
			return;
		}

		$result = $this->repository->save( $post_id, $language, $fields, $revision );

		if ( is_wp_error( $result ) ) {
			set_transient( 'lmat_wc_panel_error_' . get_current_user_id(), $result->get_error_message(), 60 );
		}
	}

	/**
	 * Returns the language the panel is editing.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	protected function selected_language() {
		// Read only: this selects which language the panel displays, and changes nothing.
		$requested = isset( $_GET['lmat_wc_language'] ) ? sanitize_key( wp_unslash( $_GET['lmat_wc_language'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $requested ) {
			return $requested;
		}

		$languages = function_exists( 'linguator_languages_list' ) ? (array) linguator_languages_list() : array();
		$default   = function_exists( 'linguator_default_language' ) ? (string) linguator_default_language( 'slug' ) : '';

		foreach ( $languages as $slug ) {
			if ( (string) $slug !== $default ) {
				return (string) $slug;
			}
		}

		return $default;
	}
```

The save handler is deliberately strict, in this order: autosave, nonce, capability. `collect()` is the only place a submitted value is written, and it accepts nothing outside the descriptor list, so a crafted payload cannot reach an arbitrary field key.

- [ ] **Step 6: Write the view**

Create `integrations/woocommerce/admin/views/product-panel.php`:

```php
<?php
/**
 * The translation entry panel markup.
 *
 * @package Linguator
 *
 * @var string $language    The language being edited.
 * @var array  $descriptors The translatable fields.
 * @var array  $record      The stored record for this language.
 * @var object $repository  Translation storage.
 * @var object $post        The product post.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use Linguator\Integrations\woocommerce\Admin\Product_Panel;

wp_nonce_field( Product_Panel::NONCE_ACTION, Product_Panel::NONCE_FIELD );
?>
<input type="hidden" name="lmat_wc_language" value="<?php echo esc_attr( $language ); ?>" />
<input type="hidden" name="lmat_wc_revision" value="<?php echo esc_attr( (string) $record['revision'] ); ?>" />

<p>
	<label for="lmat-wc-language-switch"><?php echo esc_html__( 'Language', 'translate-words' ); ?></label>
	<select id="lmat-wc-language-switch" name="lmat_wc_language_switch">
		<?php foreach ( (array) linguator_languages_list() as $slug ) : ?>
			<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $language ); ?>>
				<?php echo esc_html( $slug ); ?>
			</option>
		<?php endforeach; ?>
	</select>
</p>

<table class="widefat striped">
	<tbody>
	<?php foreach ( $descriptors as $descriptor ) : ?>
		<?php
		$status = $repository->get_status( (int) $post->ID, $language, $descriptor['key'], $descriptor['source'] );
		$value  = isset( $record['fields'][ $descriptor['key'] ]['value'] ) ? (string) $record['fields'][ $descriptor['key'] ]['value'] : '';
		$id     = 'lmat-wc-' . sanitize_key( $descriptor['key'] );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $descriptor['label'] ); ?></label>
				<p class="description"><?php echo esc_html( $status ); ?></p>
			</th>
			<td>
				<p class="description"><?php echo esc_html( $descriptor['source'] ); ?></p>
				<?php if ( Product_Panel::TYPE_HTML === $descriptor['type'] ) : ?>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="lmat_wc_fields[<?php echo esc_attr( $descriptor['key'] ); ?>]" rows="4" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="lmat_wc_fields[<?php echo esc_attr( $descriptor['key'] ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="large-text" />
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
```

The language selector submits with the form and is read back from `$_GET` on the next page load; enqueue a two-line admin script if you prefer switching without saving, but do not add inline event handlers to the markup.

- [ ] **Step 7: Register and require**

Add `require_once __DIR__ . '/admin/product-panel.php';` to `load.php`. Register it only in the admin, inside `register_shared_mode()`:

```php
		if ( is_admin() ) {
			$this->product_panel = new Admin\Product_Panel( $this->repository );
			$this->product_panel->init();
		}
```

with the matching `protected $product_panel = null;` property and its docblock. The sub-namespace is reachable as `Admin\Product_Panel` from the integration's own namespace, so no `use` statement is needed.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php /tmp/phpunit-9.phar`
Expected: PASS — all previous tests plus the 7 new ones.

Run: `LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar`
Expected: PASS.

- [ ] **Step 9: Verify the panel on the clone**

Open a product in the clone's admin, switch the panel to German, enter a name, save, and confirm the value comes back after a reload and that an untouched field reports the missing status. Then change the Italian product name and confirm the German field becomes `needs_review` while the German text keeps being displayed on the front end.

- [ ] **Step 10: Commit**

```bash
git add integrations/woocommerce/admin/ integrations/woocommerce/load.php integrations/woocommerce/woocommerce.php tests/bootstrap.php tests/woocommerce/test-product-panel.php
git commit -m "feat: add the minimal translation entry panel"
```

---

### Task 16: The settings toggle

One boolean, off by default, in the existing Linguator settings, using the existing persistence and authorization flow. The settings screen is a React app whose bundles are committed, so this task carries a build step and a deliberate check that the build did not rewrite bundles this change has nothing to do with.

**Files:**
- Modify: `admin/settings/views/src/components/general.jsx`
- Modify: the built bundle under `admin/assets/` that webpack regenerates for it

**Interfaces:**
- Consumes: the option registered in Task 3, which reaches the screen as `data.wc_shared_product_identity` because the REST endpoint returns `$this->options->get_all()`.
- Produces: the toggle, and the payload key `wc_shared_product_identity` on save.

- [ ] **Step 1: Install the toolchain**

`node_modules` is not in the repository and is git-ignored. Node 22 and npm 10 are already on this machine.

```bash
cd "D:/VS_Projects/Other PR/translate-words"
npm ci
```

Use `npm ci`, not `npm install`: `package-lock.json` is committed and must not be rewritten by this task.

- [ ] **Step 2: Record the pre-build state of the committed bundles**

```bash
git status --short admin/assets
git stash list
```

Expect no changes under `admin/assets`. Anything already modified there is unrelated to this task and must be dealt with before building, or the build's diff cannot be read.

- [ ] **Step 3: Add the state to the General component**

In `admin/settings/views/src/components/general.jsx`, beside the other booleans near the top of the component:

```jsx
    const [sharedProductIdentity, setSharedProductIdentity] = useState(data.wc_shared_product_identity !== undefined ? data.wc_shared_product_identity : false); // For shared WooCommerce product identity
```

In the `sameChecker` object that decides whether the save button is enabled, add:

```jsx
            sharedProductIdentity: true
```

and the comparison beside the other ones:

```jsx
        const sharedIdentityBackendValue = data.wc_shared_product_identity !== undefined ? data.wc_shared_product_identity : false;
        if (sharedProductIdentity !== sharedIdentityBackendValue) {
            sameChecker.sharedProductIdentity = false
        }
```

Add `sharedProductIdentity` to that effect's dependency array, which currently ends with `menuSyncVisibility`.

- [ ] **Step 4: Add the key to both save payloads**

`general.jsx` builds the payload in two places. Add to each, beside `menu_sync_visibility`:

```jsx
                    wc_shared_product_identity: sharedProductIdentity,
```

Both must be changed. Changing only one produces a toggle that saves in some paths and silently loses the value in others.

- [ ] **Step 5: Render the toggle**

After the Menu Sync section, following the same structure the other switches use:

```jsx
                <hr className="w-full border-b-0 border-x-0 border-t border-solid border-t-border-subtle" />
                {/* Shared WooCommerce product identity */}
                <div className='switcher'>
                    <Container.Item>
                        <h3 className='flex items-center gap-2'>
                            <Share2 className="flex-shrink-0 size-5 text-icon-secondary" />
                            {__('Shared WooCommerce product identity', 'translate-words')}
                        </h3>
                        <p>
                            {__('Keep one product across all languages: one product ID, SKU, price and stock, with only the text translated. Cannot be enabled while translated products already exist.', 'translate-words')}
                        </p>
                    </Container.Item>
                    <Container.Item className='flex items-center justify-end' style={{paddingRight: '30%'}}>
                        <Switch
                            aria-label="Switch Element"
                            id="wc-shared-product-identity"
                            onChange={() => {
                                setSharedProductIdentity(!sharedProductIdentity)
                            }}
                            size="sm"
                            value={sharedProductIdentity}
                        />
                    </Container.Item>
                </div>
```

`Share2` is already imported at the top of the file by the Usage Data Sharing section; do not add a duplicate import.

- [ ] **Step 6: Build and read the diff**

```bash
npm run build
git status --short admin/assets
```

Only the bundle containing the settings app should have changed. If webpack rewrote other bundles — a version stamp, a dependency hash, a line ending — restore them:

```bash
git checkout -- <each unrelated file>
```

An upstream pull request that rewrites unrelated minified bundles is a pull request maintainers cannot review. Say in the PR description which bundle was rebuilt and with which Node and npm versions.

- [ ] **Step 7: Verify in the clone**

Copy the changed source and the rebuilt bundle into the clone's plugin directory, then in the clone's admin:

1. The toggle appears in the General tab, off.
2. Turning it on and saving succeeds. `get_option('linguator')['wc_shared_product_identity']` is `true`.
3. Turning it off and saving succeeds, and the front end output returns to what it was.
4. The activation guard refuses when it should. Create the refusal condition on purpose:

```bash
cd /d/Local_Sites/dev-linguatorbeltracing/app/public
WP="php /c/Users/matte/bin/wp-cli.phar --path=."

$WP eval '$id = <a product id>; linguator_save_post_translations( array( "it" => $id, "de" => <another product id> ) ); echo "linked\n";'
```

Then try to enable shared mode from the settings screen. It must refuse and name the product. Undo the link afterwards, and confirm `Shared_Mode::blockers()` returns an empty array again.

If the refusal message does not reach the screen, the gap is in how the settings REST response surfaces blocking option errors, not in the guard. Fix it there rather than weakening the guard.

- [ ] **Step 8: Commit**

```bash
git add admin/settings/views/src/components/general.jsx admin/assets/<rebuilt bundle>
git commit -m "feat: add the shared product identity toggle to the settings screen"
```

---

### Task 17: Regression proof and the pull request

The unit suite proves the pieces. This task proves the whole: that with shared mode off the site emits exactly what it emits today, and that with it on nothing but text differs.

**Files:**
- Modify: `/d/Local_Sites/dev-linguatorbeltracing/tools/suite.sh` (the capture harness, outside the repository)
- Modify: `readme.txt` on `main` only, never in the pull request
- Create: `docs/superpowers/root-cause/2026-XX-XX-woocommerce-shared-identity-phase-1-verification.md` on `main`

- [ ] **Step 1: Extend the capture suite**

`suite.sh` currently captures seven pages: shop IT, shop DE, an ordinary page in both languages, the front page, a product category archive and the paged shop. Add four more, because they are where this phase's changes land:

- a single product page in Italian
- the same product in German
- a product category archive in Italian
- the same category in German

```bash
bash /d/Local_Sites/dev-linguatorbeltracing/tools/suite.sh phase1-off
```

- [ ] **Step 2: Prove shared mode off changes nothing**

With the branch installed in the clone and `wc_shared_product_identity` **false**, capture and compare against a capture taken from the switcher-fix state:

```bash
$WP eval '$o = get_option("linguator"); $o["wc_shared_product_identity"] = false; update_option("linguator", $o);'
bash /d/Local_Sites/dev-linguatorbeltracing/tools/suite.sh phase1-off
diff -ru --strip-trailing-cr <switcher-state capture dir> <phase1-off capture dir>
```

Expected: **no differences at all**. Any difference is a leak: something in this phase is acting while shared mode is off. Find it before continuing; that is the whole reversibility guarantee.

- [ ] **Step 3: Prove shared mode on changes only text**

```bash
$WP eval '$o = get_option("linguator"); $o["wc_shared_product_identity"] = true; update_option("linguator", $o);'
bash /d/Local_Sites/dev-linguatorbeltracing/tools/suite.sh phase1-on
diff -ru --strip-trailing-cr <phase1-off capture dir> <phase1-on capture dir>
```

Read every differing line. Each one must be either a translated string, or a language-prefixed URL on the German pages. Prices, SKUs, IDs, form field names, `value` attributes and add-to-cart payloads must be identical.

- [ ] **Step 4: Prove hreflang came back**

```bash
curl -s http://dev-linguatorbeltracing.local/prodotto/<slug>/ | grep 'rel="alternate"'
```

Expected: four `<link rel="alternate">` lines, one per language, each on the same product. Before this phase there were none on a product page; this is the assertion the design's amended SEO section calls for.

- [ ] **Step 5: Prove nothing was created**

```bash
$WP eval 'echo wp_count_posts("product")->publish, "/", wp_count_posts("product")->private, "\n";'
$WP eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"product_variation\""), "\n";'
```

Expected, unchanged from the Task 11 baseline: 65 publish, 56 private, 248 variations.

- [ ] **Step 6: Prove visibility is unchanged**

Fifty-six of the 121 products are private, and the design promises that a translation never widens
access. Products stop being filtered by language under shared mode, so this has to be measured, not
assumed.

```bash
$WP eval '
foreach ( array( "publish", "private", "draft" ) as $status ) {
  $q = new WP_Query( array( "post_type" => "product", "post_status" => $status, "fields" => "ids", "posts_per_page" => -1 ) );
  echo $status, ": ", $q->found_posts, "
";
}'
```

Then, logged out, request a private product's URL under `/de/` and confirm it returns 404 or a
redirect exactly as it does under `/`. A private product must not become reachable in any language.

- [ ] **Step 7: Check the WooCommerce extensions on the target site**

`woo-multi-currency` and `woocommerce-members-only` both wrap product data and are active on the target site. Exercise them rather than assuming compatibility: switch currency on a German product page and confirm the price behaves as it does in Italian, and confirm a members-only product's visibility is unchanged in every language. Record what was observed, including "no effect", which is itself a result.

- [ ] **Step 8: Write the verification document**

On `main`, record the measured figures, the capture diffs, the two order IDs from Task 11, the hreflang output and the extension observations. This is what makes the next session able to trust the work without rerunning it.

- [ ] **Step 9: Prepare the pull request**

```bash
git checkout feat/woocommerce-shared-product-identity-phase-1
git diff --stat fix/woocommerce-shop-language-switcher..HEAD
```

Check the file list **one by one**. Nothing under `docs/` may appear: project documentation lives on `main` and never enters an upstream pull request. PR #20 was checked this way and this branch must be too.

Push to the fork, since `origin` is read-only for this account:

```bash
git push fork feat/woocommerce-shared-product-identity-phase-1
```

Open the pull request against `CoolPluginsTeam/translate-words`, based on the switcher branch, and say in the description:

- that it is **stacked on PR #20** and should be reviewed after it
- which bundle under `admin/assets/` was rebuilt, with the Node and npm versions used
- that `@since 2.3.0` presumes upstream's release numbering, and can be changed on request
- that `readme.txt` is deliberately not included, for the same reason as in PR #20
- that `integrations/integration-build.php` already carries the `woocommerce` entry from PR #20, and that no generator for it exists in the repository

- [ ] **Step 10: Final verification before claiming completion**

```bash
cd "D:/VS_Projects/Other PR/translate-words"
php /tmp/phpunit-9.phar
LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar
git status --short
```

Both suites green, working tree clean. Report the actual counts, not "tests pass".

---

## Why the tasks are in this order

The user asked for one specific ordering: attack the variation and cart matching first, with a complete purchase of a variable product in German as the first verifiable milestone, before building the repository, rendering and panel in full. The reasoning: 44 variable products and 248 variations are on the line, and a wrong match means a customer buys the wrong thing. If the architecture cannot hold that invariant, it is better to find out on day two than at the end.

Tasks 1 to 6 are the smallest scaffold the matching work cannot be done without: a harness that can simulate a request, the setting, a repository, the context gate and the identity switch. Task 7 adds three product fields, not "rendering in full" — without it the milestone purchase would show German attribute labels beside an Italian product name, which proves less than it should. Tasks 8 to 11 are the matching work itself, ending in the purchase.

Everything after Task 11 — terms, URLs, SEO, panel, settings toggle — is work whose failure mode is a visible defect on a page. The work before Task 11 has a failure mode that is an order for the wrong item. That is the whole reason for the split.

## Self-review

Run against the spec after the plan was complete.

**Spec coverage.** Every section of the design maps to a task: decisions 1-7 to Tasks 2, 3, 4, 6, 12 and 13; the Phase 1 scope list to Tasks 7, 8, 12 and 14; the data model, its three field states, revisions, priming and REST privacy to Tasks 4 and 10; identity and query behaviour to Tasks 6 and 13; rendering and the context gate to Tasks 5 and 7; attributes and variations to Tasks 8 and 9; orders to Task 11; the SEO adapter to Task 14; the entry panel to Task 15; settings and the activation guard to Tasks 2, 3 and 16; the error-handling table to Tasks 2, 4, 10 and 17.

Two gaps were found and closed rather than noted:

1. **The activation guard filter passed the wrong argument as the value under test.** The option's sanitizer passed `true` first and the value second, while `Shared_Mode::guard()` reads its first argument as the value. As written, enabling would have been checked against a constant. The filter now passes the value itself.
2. **Nothing asserted that visibility is unchanged**, although 56 of the 121 products are private and the design promises a translation never widens access. Task 17 now measures it, logged out, per language.

The spec's own component table was also amended to name the four files this plan adds beyond it: `shared-mode.php`, `context-gate.php`, `variations.php` and `cart.php`.

**Placeholders.** No step says "TBD", "add error handling" or "similar to Task N". Every code step carries the code. The angle-bracket values that remain — `<variable product id>`, `<order id>`, `<slug>`, `<rebuilt bundle>`, the capture directory names — are values that only exist on the running clone, and each appears inside a manual verification step that says where to read it.

**Type consistency.** `Translation_Repository::get_value()` and `save()` keep the same parameter order in all seven tasks that call them. `Frontend::current_language()` and `is_default_language()` are public because Tasks 8, 9, 12 and 14 depend on them. `Attribute_Translations::label_field()` and `option_field()` are used with identical arguments in Tasks 8, 9, 10 and 15. `Context_Gate::should_localize()` has one signature everywhere. Every stub a task's tests use is introduced in that task or an earlier one.

## A known limitation, recorded on purpose

`Attribute_Translations::normalize_attribute()` collapses `Taglia`, `pa_taglia` and `attribute_taglia` to one key, because WooCommerce hands the same attribute to the display hooks under all three spellings depending on the call site. On a site that has both a global `pa_size` taxonomy and a product-local attribute named "Size", those two would share a key. The target site has **zero** global attribute taxonomies, so it cannot happen there. It is recorded here rather than solved with a longer key that would still be ambiguous.
