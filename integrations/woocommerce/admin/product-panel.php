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
 * This is the first adapter on the branch that accepts input from a browser and writes it to the
 * database, rather than only filtering what is displayed. save() therefore verifies, strictly in
 * this order: this is not an autosave or a revision save, the panel's own nonce, and the editing
 * capability against the actual product being saved. collect() is the single place a submitted
 * value is turned into the repository's shape, and it accepts nothing outside the descriptor list
 * it was given, so a crafted payload cannot reach an arbitrary field key. Canonical data — IDs,
 * SKU, price, stock, attribute keys, attribute option values, term IDs, term slugs, order IDs — is
 * never among the fields this panel offers; only labels and display text are translatable here.
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
	 * type: sanitize_text_field() for a plain text field, wp_kses_post() for the two rich fields.
	 * A key absent from the payload is not written at all, which is what keeps "never translated"
	 * distinct from "intentionally empty". This is the only place a submitted value is written, and
	 * it is the security boundary between the browser and storage: nothing outside the descriptor
	 * list this panel built from the product's own canonical fields can reach the repository, so a
	 * crafted payload cannot write an arbitrary meta field.
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
	 * Verified strictly in this order: not an autosave, not a revision save (both would otherwise
	 * write a half-filled payload over real translations), the panel's own nonce, and the editing
	 * capability against this exact product. Quick Edit never submits the panel's nonce field, so
	 * it is excluded by the nonce presence check without a dedicated branch.
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

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
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
}
