<?php
/**
 * The per-language permalink bases screen.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use Linguator\Integrations\woocommerce\Slug_Store;

/**
 * Lets an administrator set, per non-default language, the product, category, tag and attribute bases.
 *
 * It is its own page under Settings rather than a section of WooCommerce's Permalinks screen: that
 * screen is saved by WordPress core, which flushes the rewrite rules itself, so a section there would
 * flush twice. Here the save goes only through Slug_Store::set_base(); the rewrite flush comes from
 * Slug_Routing, which watches the bases option, and this class adds none.
 *
 * @since 2.3.0
 */
class Permalink_Bases {

	const NONCE_ACTION = 'lmat_wc_permalink_bases';
	const NONCE_FIELD  = 'lmat_wc_permalink_bases_nonce';
	const FIELD        = 'lmat_wc_bases';
	const PAGE         = 'lmat-wc-permalink-bases';

	/**
	 * Messages of the last save, shown by render() in the same request.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	protected $errors = array();

	/**
	 * Warnings of the last save: a base that collides with an existing page. They never block a save.
	 *
	 * @since 2.3.0
	 *
	 * @var string[]
	 */
	protected $warnings = array();

	/**
	 * Setups hooks.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 10, 0 );
	}

	/**
	 * Adds the page under Settings and saves a submission before the page is rendered.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function register_page() {
		$hook = add_options_page(
			esc_html__( 'Translated permalink bases', 'translate-words' ),
			esc_html__( 'Translated permalinks', 'translate-words' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'save' ), 10, 0 );
		}
	}

	/**
	 * Returns the labels of the four bases, keyed by base type.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	public function labels() {
		return array(
			'product'   => __( 'Product base', 'translate-words' ),
			'category'  => __( 'Category base', 'translate-words' ),
			'tag'       => __( 'Tag base', 'translate-words' ),
			'attribute' => __( 'Attribute base', 'translate-words' ),
		);
	}

	/**
	 * Renders the page.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$labels   = $this->labels();
		$bases    = array();
		$errors   = $this->errors;
		$warnings = $this->warnings;

		foreach ( Slug_Store::slug_languages() as $lang_slug ) {
			foreach ( array_keys( $labels ) as $base_type ) {
				$bases[ $lang_slug ][ $base_type ] = Slug_Store::get_base( $lang_slug, $base_type );
			}
		}

		include __DIR__ . '/views/permalink-bases.php';
	}

	/**
	 * Stores the submitted bases.
	 *
	 * Verified in this order: the nonce, then the capability. An empty field is left alone (a base
	 * cannot be cleared), and an invalid one is reported without stopping the others.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function save() {
		$this->errors   = array();
		$this->warnings = array();

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$submitted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] )
			? wp_unslash( $_POST[ self::FIELD ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by Slug_Store::set_base().
			: array();

		foreach ( $submitted as $lang_slug => $by_type ) {
			$lang_slug = sanitize_key( $lang_slug );

			if ( ! is_array( $by_type ) || ! Slug_Store::is_slug_language( $lang_slug ) ) {
				continue;
			}

			foreach ( $by_type as $base_type => $raw_base ) {
				if ( ! is_string( $raw_base ) || '' === trim( $raw_base ) || ! in_array( $base_type, Slug_Store::BASE_TYPES, true ) ) {
					continue;
				}

				$result = Slug_Store::set_base( $lang_slug, $base_type, $raw_base );

				if ( is_wp_error( $result ) ) {
					$this->errors[] = $lang_slug . ' / ' . $base_type . ': ' . $result->get_error_message();
					continue;
				}

				$stored = Slug_Store::get_base( $lang_slug, $base_type );

				if ( get_page_by_path( $stored ) ) {
					/* translators: 1: language, 2: permalink base. */
					$this->warnings[] = sprintf( __( '%1$s: the base "%2$s" is also the path of an existing page, so one of them may become unreachable.', 'translate-words' ), $lang_slug, $stored );
				}
			}
		}
	}
}
