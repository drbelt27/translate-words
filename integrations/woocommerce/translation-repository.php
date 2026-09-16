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
	 * A field's status is not part of the stored shape: it is derived at read time by get_status()
	 * from the field's source_hash against the current source, and is deliberately never persisted,
	 * so there is nothing here that could go stale and be trusted by mistake.
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
