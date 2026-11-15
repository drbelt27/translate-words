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
