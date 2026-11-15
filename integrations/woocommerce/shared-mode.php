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
