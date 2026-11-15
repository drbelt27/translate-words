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
	 * Uses a boolean per context rather than a reference count, so re-entrant suspension of the
	 * same context would have the inner resume() lift the outer one. This is benign for WooCommerce's
	 * actual flows, which do not nest.
	 *
	 * @since 2.3.0
	 *
	 * @var array<string, bool>
	 */
	protected $suspended = array();

	/**
	 * Brackets the email context during which localization must not happen.
	 *
	 * Email bodies read the snapshot frozen at purchase, never live translations. The order context
	 * is bracketed by the Orders adapter around checkout.
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
