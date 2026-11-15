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

		require_once __DIR__ . '/shared-mode.php';
		require_once __DIR__ . '/context-gate.php';
		require_once __DIR__ . '/translated-object-types.php';
		require_once __DIR__ . '/frontend.php';
		require_once __DIR__ . '/attribute-translations.php';
		require_once __DIR__ . '/variations.php';
		require_once __DIR__ . '/cart.php';
		require_once __DIR__ . '/orders.php';
		require_once __DIR__ . '/term-translations.php';
		require_once __DIR__ . '/seo.php';
		require_once __DIR__ . '/admin/product-panel.php';
		require_once __DIR__ . '/woocommerce.php';

		add_action( 'lmat_init', array( Linguator_Integrations::instance()->woocommerce = new Linguator_WooCommerce(), 'init' ) );
	},
	0
);
