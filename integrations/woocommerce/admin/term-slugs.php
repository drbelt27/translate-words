<?php
/**
 * Translated slug fields on the product term edit screens.
 *
 * @package Linguator
 */

namespace Linguator\Integrations\woocommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use Linguator\Integrations\woocommerce\Slug_Store;
use Linguator\Integrations\woocommerce\Translation_Repository;

/**
 * Lets a human enter, per non-default language, the translated slug of a product category, tag,
 * brand or global attribute term.
 *
 * Mirrors Product_Panel: the nonce, the editing capability and the language allow-list are checked
 * before anything is written, and the write itself always goes through Slug_Store, which enforces
 * uniqueness per language, taxonomy and parent. Term names are not entered here (Phase 2): a slug
 * is derived only from a translated name that already exists.
 *
 * @since 2.3.0
 */
class Term_Slugs {

	const NONCE_ACTION = 'lmat_wc_term_slugs';
	const NONCE_FIELD  = 'lmat_wc_term_slugs_nonce';
	const FIELD        = 'lmat_wc_term_slug';

	/**
	 * Translation storage, read for an existing translated term name.
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
	 * The release hook is registered on every request, because a term is also deleted over REST;
	 * the fields and the save only exist in the admin. The taxonomies are read on `init` priority
	 * 99, after WooCommerce has registered the global attribute taxonomies (priority 5).
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'delete_term', array( $this, 'release' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'init', array( $this, 'register_fields' ), 99, 0 );
			add_action( 'edited_term', array( $this, 'save' ), 10, 3 );
		}
	}

	/**
	 * Adds the slug fields to the edit screen of every shared taxonomy.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function register_fields() {
		foreach ( $this->taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_field' ), 10, 1 );
		}
	}

	/**
	 * Returns the taxonomies whose terms carry translated slugs.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	public function taxonomies() {
		$taxonomies = array( 'product_cat', 'product_tag' );

		if ( taxonomy_exists( 'product_brand' ) ) {
			$taxonomies[] = 'product_brand';
		}

		foreach ( (array) get_taxonomies( array(), 'names' ) as $taxonomy ) {
			if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}

	/**
	 * Renders the slug field of every non-default language.
	 *
	 * @since 2.3.0
	 *
	 * @param object $term The term being edited.
	 * @return void
	 */
	public function render_field( $term ) {
		$languages = Slug_Store::slug_languages();

		if ( empty( $languages ) ) {
			return;
		}

		$term_id = (int) $term->term_id;
		$slugs   = array();

		foreach ( $languages as $lang_slug ) {
			$slugs[ $lang_slug ] = Slug_Store::get( $term_id, $lang_slug, Translation_Repository::TYPE_TERM );
		}

		$error = $this->pull_error();

		include __DIR__ . '/views/term-slugs.php';
	}

	/**
	 * Stores the submitted translated slugs of a term.
	 *
	 * Verified in this order: the taxonomy is a shared one, the nonce, then the capability against
	 * this exact term. Quick Edit never submits the nonce, so it is excluded without a branch.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID. Unused.
	 * @param string $taxonomy The taxonomy.
	 * @return void
	 */
	public function save( $term_id, $tt_id = 0, $taxonomy = '' ) {
		$term_id = (int) $term_id;

		if ( ! in_array( $taxonomy, $this->taxonomies(), true ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$submitted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] )
			? wp_unslash( $_POST[ self::FIELD ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per language below.
			: array();
		$errors    = array();

		foreach ( $submitted as $lang_slug => $raw_slug ) {
			$lang_slug = sanitize_key( $lang_slug );

			if ( ! is_string( $raw_slug ) || ! Slug_Store::is_slug_language( $lang_slug ) ) {
				continue;
			}

			$result = $this->save_slug( $term_id, $taxonomy, $lang_slug, sanitize_title( $raw_slug ) );

			if ( is_wp_error( $result ) ) {
				$errors[] = $lang_slug . ': ' . $result->get_error_message();
			}
		}

		if ( ! empty( $errors ) ) {
			set_transient( self::error_key(), implode( ' ', $errors ), 60 );
		}
	}

	/**
	 * Releases the translated slugs of a permanently deleted term, so another term can take them.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $term_id  The deleted term ID.
	 * @param int    $tt_id    The term taxonomy ID. Unused.
	 * @param string $taxonomy The taxonomy.
	 * @return void
	 */
	public function release( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( in_array( $taxonomy, $this->taxonomies(), true ) ) {
			Slug_Store::forget( (int) $term_id, Translation_Repository::TYPE_TERM );
		}
	}

	/**
	 * Returns the save error of the current user and clears it, so it is shown once.
	 *
	 * @since 2.3.0
	 *
	 * @return string Empty when there is none.
	 */
	public function pull_error() {
		$error = (string) get_transient( self::error_key() );
		delete_transient( self::error_key() );

		return $error;
	}

	/**
	 * Returns the transient name that carries a save error to the next render.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	protected static function error_key() {
		return 'lmat_wc_term_slug_error_' . get_current_user_id();
	}

	/**
	 * Stores the submitted slug, or derives one from an existing translated name when there is none yet.
	 *
	 * An existing slug is never replaced by a derived one. Clearing a stored slug is refused by
	 * Slug_Store::set() like any other invalid slug.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy.
	 * @param string $language Language slug.
	 * @param string $slug     The sanitized submitted slug.
	 * @return true|\WP_Error
	 */
	protected function save_slug( $term_id, $taxonomy, $language, $slug ) {
		$type = Translation_Repository::TYPE_TERM;

		if ( '' !== $slug || '' !== Slug_Store::get( $term_id, $language, $type ) ) {
			return Slug_Store::set( $term_id, $language, $slug, $type, $taxonomy );
		}

		$record = $this->repository->get_record( $term_id, $language, $type );
		$name   = isset( $record['fields']['name']['value'] ) ? (string) $record['fields']['name']['value'] : '';
		$slug   = '' === $name ? '' : Slug_Store::derive( $name, $term_id, $language, $type, $taxonomy );

		return '' === $slug ? true : Slug_Store::set( $term_id, $language, $slug, $type, $taxonomy );
	}
}
