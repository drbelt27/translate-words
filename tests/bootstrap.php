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
		'is_shop'           => false,
		'is_front_page'     => false,
		'is_product'        => false,
		'is_product_tax'    => false,
		'queried_term'      => null,
		'shop_page_id'      => -1,
		'translations'      => array(),
		'permalinks'        => array(),
		'unreadable_ids'    => array(),
		'linguator'         => true,
		'hooks'             => array(),
		'post_meta'         => array(),
		'term_meta'         => array(),
		'primed_posts'      => array(),
		'primed_terms'      => array(),
		'is_admin'          => false,
		'doing_ajax'        => false,
		'doing_cron'        => false,
		'rest_request'      => false,
		'wp_cli'            => false,
		'curlang'           => 'it',
		'default_lang'      => 'it',
		'languages'         => array( 'it', 'de', 'fr', 'en' ),
		'options'           => array(),
		'products'          => array(),
		'product_ids'       => array(),
		'post_translations' => array(),
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

/**
 * Simulates WordPress's get_post_meta().
 *
 * @since 2.3.0
 *
 * @param int    $id     Post ID.
 * @param string $key    Meta key.
 * @param bool   $single Whether to return a single value.
 * @return mixed
 */
function get_post_meta( $id, $key = '', $single = false ) {
	return lmat_test_get_meta( 'post_meta', $id, $key, $single );
}

/**
 * Simulates WordPress's update_post_meta().
 *
 * @since 2.3.0
 *
 * @param int    $id    Post ID.
 * @param string $key   Meta key.
 * @param mixed  $value Meta value.
 * @return true
 */
function update_post_meta( $id, $key, $value ) {
	return lmat_test_set_meta( 'post_meta', $id, $key, $value );
}

/**
 * Simulates WordPress's delete_post_meta().
 *
 * @since 2.3.0
 *
 * @param int    $id  Post ID.
 * @param string $key Meta key.
 * @return true
 */
function delete_post_meta( $id, $key ) {
	$meta = (array) lmat_test_get( 'post_meta', array() );
	unset( $meta[ (int) $id ][ $key ] );
	lmat_test_set( 'post_meta', $meta );

	return true;
}

/**
 * Simulates WordPress's get_term_meta().
 *
 * @since 2.3.0
 *
 * @param int    $id     Term ID.
 * @param string $key    Meta key.
 * @param bool   $single Whether to return a single value.
 * @return mixed
 */
function get_term_meta( $id, $key = '', $single = false ) {
	return lmat_test_get_meta( 'term_meta', $id, $key, $single );
}

/**
 * Simulates WordPress's update_term_meta().
 *
 * @since 2.3.0
 *
 * @param int    $id    Term ID.
 * @param string $key   Meta key.
 * @param mixed  $value Meta value.
 * @return true
 */
function update_term_meta( $id, $key, $value ) {
	return lmat_test_set_meta( 'term_meta', $id, $key, $value );
}

/**
 * Simulates WordPress's delete_term_meta().
 *
 * @since 2.3.0
 *
 * @param int    $id  Term ID.
 * @param string $key Meta key.
 * @return true
 */
function delete_term_meta( $id, $key ) {
	$meta = (array) lmat_test_get( 'term_meta', array() );
	unset( $meta[ (int) $id ][ $key ] );
	lmat_test_set( 'term_meta', $meta );

	return true;
}

/**
 * Simulates WordPress's update_postmeta_cache(), recording which post IDs were primed.
 *
 * @since 2.3.0
 *
 * @param int[] $ids Post IDs.
 * @return true
 */
function update_postmeta_cache( $ids ) {
	lmat_test_set( 'primed_posts', array_merge( (array) lmat_test_get( 'primed_posts', array() ), array_map( 'intval', (array) $ids ) ) );

	return true;
}

/**
 * Simulates WordPress's update_termmeta_cache(), recording which term IDs were primed.
 *
 * @since 2.3.0
 *
 * @param int[] $ids Term IDs.
 * @return true
 */
function update_termmeta_cache( $ids ) {
	lmat_test_set( 'primed_terms', array_merge( (array) lmat_test_get( 'primed_terms', array() ), array_map( 'intval', (array) $ids ) ) );

	return true;
}

/**
 * Simulates WordPress's is_admin().
 *
 * @since 2.3.0
 *
 * @return bool
 */
function is_admin() {
	return (bool) lmat_test_get( 'is_admin', false );
}

/**
 * Simulates WordPress's wp_doing_ajax().
 *
 * @since 2.3.0
 *
 * @return bool
 */
function wp_doing_ajax() {
	return (bool) lmat_test_get( 'doing_ajax', false );
}

/**
 * Simulates WordPress's wp_doing_cron().
 *
 * @since 2.3.0
 *
 * @return bool
 */
function wp_doing_cron() {
	return (bool) lmat_test_get( 'doing_cron', false );
}

/**
 * Simulates WooCommerce's is_product().
 *
 * @since 2.3.0
 *
 * @return bool
 */
function is_product() {
	return (bool) lmat_test_get( 'is_product', false );
}

/**
 * Simulates WordPress's __(), returning the string unchanged.
 *
 * @since 2.3.0
 *
 * @param string $text   Text to translate.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return $text;
}

/**
 * Simulates WordPress's esc_html__(), returning the string unchanged.
 *
 * @since 2.3.0
 *
 * @param string $text   Text to translate.
 * @param string $domain Text domain.
 * @return string
 */
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

/**
 * Simulates WordPress's esc_html(), returning the string unchanged.
 *
 * @since 2.3.0
 *
 * @param string $text Text to escape.
 * @return string
 */
function esc_html( $text ) {
	return $text;
}

/**
 * Simulates WordPress's esc_attr(), returning the string unchanged.
 *
 * @since 2.3.0
 *
 * @param string $text Text to escape.
 * @return string
 */
function esc_attr( $text ) {
	return $text;
}

/**
 * Simulates WordPress's esc_url(), returning the URL unchanged.
 *
 * @since 2.3.0
 *
 * @param string $url URL to escape.
 * @return string
 */
function esc_url( $url ) {
	return $url;
}

/**
 * Simulates WordPress's sanitize_key().
 *
 * @since 2.3.0
 *
 * @param string $key Key to sanitize.
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * Simulates WordPress's sanitize_title().
 *
 * @since 2.3.0
 *
 * @param string $title Title to sanitize.
 * @return string
 */
function sanitize_title( $title ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
}

/**
 * Simulates WordPress's sanitize_text_field().
 *
 * @since 2.3.0
 *
 * @param string $text Text to sanitize.
 * @return string
 */
function sanitize_text_field( $text ) {
	return trim( wp_strip_all_tags( (string) $text ) );
}

/**
 * Simulates WordPress's wp_strip_all_tags().
 *
 * @since 2.3.0
 *
 * @param string $text Text to strip.
 * @return string
 */
function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

/**
 * Simulates WordPress's wp_kses_post(), returning the value unchanged.
 *
 * @since 2.3.0
 *
 * @param string $value Value to filter.
 * @return string
 */
function wp_kses_post( $value ) {
	return $value;
}

/**
 * Simulates WordPress's wp_unslash().
 *
 * @since 2.3.0
 *
 * @param mixed $value Value to unslash.
 * @return mixed
 */
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * Simulates WordPress's absint().
 *
 * @since 2.3.0
 *
 * @param mixed $value Value to cast.
 * @return int
 */
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

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $id        Product ID.
	 * @param string $type      Product type.
	 * @param int    $parent_id Parent product ID, 0 for a top-level product.
	 */
	public function __construct( $id, $type = 'simple', $parent_id = 0 ) {
		$this->id        = (int) $id;
		$this->type      = $type;
		$this->parent_id = (int) $parent_id;
	}

	/**
	 * Returns the product ID.
	 *
	 * @since 2.3.0
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Returns the parent product ID.
	 *
	 * @since 2.3.0
	 *
	 * @return int
	 */
	public function get_parent_id() {
		return $this->parent_id;
	}

	/**
	 * Returns the product type.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	public function get_type() {
		return $this->type;
	}
}

/**
 * Simulates WooCommerce's wc_get_product().
 *
 * @since 2.3.0
 *
 * @param int $id Product ID.
 * @return Lmat_Test_Product|false
 */
function wc_get_product( $id ) {
	$products = (array) lmat_test_get( 'products', array() );

	return isset( $products[ (int) $id ] ) ? $products[ (int) $id ] : false;
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

/**
 * Simulates WordPress's get_posts(), returning the simulated product catalogue.
 *
 * @since 2.3.0
 *
 * @param array $args Query arguments, unused by the stub.
 * @return int[]
 */
function get_posts( $args = array() ) {
	return (array) lmat_test_get( 'product_ids', array() );
}

/**
 * Simulates Linguator's translation group lookup for a post.
 *
 * @since 2.3.0
 *
 * @param int $post_id Post ID.
 * @return int[] Translations, keyed by language slug.
 */
function linguator_get_post_translations( $post_id ) {
	$translations = (array) lmat_test_get( 'post_translations', array() );

	return isset( $translations[ (int) $post_id ] ) ? $translations[ (int) $post_id ] : array();
}

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

require_once dirname( __DIR__ ) . '/integrations/woocommerce/context-gate.php';
require_once dirname( __DIR__ ) . '/integrations/woocommerce/woocommerce.php';
require_once dirname( __DIR__ ) . '/integrations/woocommerce/shared-mode.php';
require_once dirname( __DIR__ ) . '/integrations/woocommerce/translation-repository.php';
