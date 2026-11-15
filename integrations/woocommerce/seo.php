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
 * Filter names verified against the installed SEOPress copy (10.2):
 * - `seopress_titles_title`   applied in src/Services/Metas/Title/TitleMeta.php:79,124 and
 *                              inc/functions/options-titles-metas.php:420.
 * - `seopress_titles_desc`    applied in src/Services/Metas/Description/DescriptionMeta.php:97,144
 *                              and inc/functions/options-titles-metas.php:759.
 * - `seopress_social_og_title` applied in
 *                              src/Services/Metas/SocialFacebook/Specifications/Title/AbstractTitleSpecification.php:20
 *                              and inc/functions/options-social.php:480.
 * - `seopress_social_og_desc` applied in
 *                              src/Services/Metas/SocialFacebook/Specifications/Description/AbstractDescriptionSpecification.php:20,
 *                              src/Services/Metas/SocialTwitter/Specifications/Description/AbstractDescriptionSpecification.php:19
 *                              and inc/functions/options-social.php:619.
 *
 * These filters receive only the computed string, never the object being displayed, so the object
 * has to be resolved from the current request instead of from a filter argument. Only two shapes of
 * request are recognised: a single product view (is_product(), read via get_queried_object_id()) and
 * a product term archive (is_tax() against Term_Translations::TAXONOMIES, read via
 * get_queried_object()). Anything else — a blog post, the front page, a search page, an unrelated
 * taxonomy archive — falls through untouched, because neither branch matches and the SEO plugin's own
 * value is returned as-is.
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
