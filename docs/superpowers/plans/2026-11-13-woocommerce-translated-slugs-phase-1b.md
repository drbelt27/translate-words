# WooCommerce Translated Slugs (Phase 1b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give shared WooCommerce products, product categories/tags/brands and global attribute terms a per-language slug and per-language permalink base, with no duplicated objects.

**Architecture:** A `Slug_Store` keeps plain meta per object and language plus one bases option. A `Slug_Resolver` is the only place that decides rules 1-4 and builds `permalink( object, lang )`. A routing class maps an inbound translated slug to the canonical one before WordPress resolves the URL; an outbound class makes every emitted URL (links, switcher, hreflang, canonical, sitemap, JSON-LD) go through the resolver.

**Tech Stack:** PHP >= 7.2, WordPress 6.8, WooCommerce, PHPUnit 9 isolated harness (`tests/bootstrap.php`, stubs, no WordPress loaded).

**Spec:** `docs/superpowers/specs/2026-11-13-woocommerce-translated-slugs-phase-1b-design.md` (builds on the Phase 1 design on `main`).

## Global Constraints

- PHP >= 7.2: no typed properties, no arrow functions, no `??=`. Docblocks, not type hints, as in the existing integration.
- Namespace `Linguator\Integrations\woocommerce`; files in `integrations/woocommerce/`; classes `Slug_Store`, `Slug_Resolver`, `Slug_Routing`, `Slug_Links`; every file starts with the `ABSPATH` guard; `@since 2.3.0`.
- Storage keys, verbatim: post/term meta `_lmat_wc_slug_<lang>` (plain string), `_lmat_wc_slug_old_<lang>` (array), option `lmat_wc_permalink_bases` = `{ lang: { product, category, tag, attribute } }` plus `old` history per language and base type.
- Rule 1: translated slug exists in language L, so the canonical slug under `/L/` returns **404**. Rule 2: no translated slug, so the canonical slug keeps working under `/L/`. Rule 3: same for bases, per base type. Rule 4: the default language always uses the canonical slug and base. Rule 5: one object, one ID; slugs never change IDs or variation matching.
- Superseded translated slug or base: single 301 to the current one. Retired canonical URL: real HTTP 404, never soft, never a redirect to home.
- Everything is gated by shared mode (`wc_shared_product_identity`). Shared mode off changes nothing visible and keeps stored slugs.
- Text domain `translate-words`; tabs, Yoda conditions, `esc_*` at output, nonce plus `current_user_can( 'edit_post' )` / `edit_term` / `manage_options` on every save, `wp_unslash()` then sanitize.
- LF line endings. Never run `composer install` in the repo (tracked `vendor/` autoloader files).
- Test command (new shell, `php` on PATH): `php "$APPDATA/Composer/vendor/phpunit/phpunit/phpunit" --configuration phpunit.xml.dist`, and again with `LMAT_TEST_WITHOUT_WOOCOMMERCE=1`. Baseline before Task 1: 164 tests, 2 skipped, 0 failed.

## Review Focus

Inputs the spec implies but no task's own happy path covers; each has a test in the owning task.

1. A slug that sanitizes to empty or to a reserved word (`feed`, `page`, `embed`, `amp`, purely numeric like `2`): must be rejected, not saved. Task 1.
2. Request variants of a valid translated URL: mixed case, trailing slash missing, percent-encoded characters, `/page/2/` pagination on a translated category. Must resolve like the plain form. Task 3.
3. A translated slug whose object is later trashed or deleted: must not keep resolving or block the slug for others. Task 1.
4. A crawler with no cookie and no `Accept-Language` requesting a prefixed URL: no language redirect, correct status. Task 8.
5. A translated parent category with an untranslated child, and the reverse. Task 3.

---

## File Structure

- Create `integrations/woocommerce/slug-store.php`: storage, sanitizing, uniqueness, derivation, bases, history.
- Create `integrations/woocommerce/slug-resolver.php`: rules 1-4, `permalink()`, `resolve()`.
- Create `integrations/woocommerce/slug-routing.php`: rewrite rules, inbound mapping, 404/301 execution.
- Create `integrations/woocommerce/slug-links.php`: `post_type_link`, `term_link`, switcher, hreflang, canonical, sitemap, JSON-LD, og:url.
- Create `integrations/woocommerce/admin/term-slugs.php`: slug field on category, tag and brand screens.
- Create `integrations/woocommerce/admin/permalink-bases.php`: per-language bases screen.
- Modify `integrations/woocommerce/admin/product-panel.php`, `woocommerce.php`, `seo.php`, `load.php`, `tests/bootstrap.php`.
- Tests: `tests/woocommerce/test-slug-store.php`, `test-slug-resolver.php`, `test-slug-routing.php`, `test-slug-links.php`, `test-term-slugs.php`, `test-permalink-bases.php`.

---

### Task 1: Slug_Store

**Files:**
- Create: `integrations/woocommerce/slug-store.php`
- Modify: `tests/bootstrap.php` (require the file; add stubs for term meta, `sanitize_title`, `wp_cache_*` if missing)
- Test: `tests/woocommerce/test-slug-store.php`

**Interfaces:**
- Consumes: `Translation_Repository::TYPE_POST`, `::TYPE_TERM`.
- Produces: `Slug_Store` with
  - `const META_PREFIX = '_lmat_wc_slug_'`, `META_OLD_PREFIX = '_lmat_wc_slug_old_'`, `BASES_OPTION = 'lmat_wc_permalink_bases'`, `MAX_LENGTH = 200`, `RESERVED = array( 'feed', 'page', 'embed', 'amp', 'attachment', 'comments', 'trackback' )`
  - `get( $object_id, $language, $type = 'post' )` returns string, `''` when none
  - `set( $object_id, $language, $slug, $type = 'post', $taxonomy = '' )` returns `true|WP_Error`; pushes the previous slug to history
  - `derive( $name, $object_id, $language, $type = 'post', $taxonomy = '' )` returns a unique slug string, `''` if nothing usable
  - `find( $slug, $language, $type = 'post', $taxonomy = '' )` returns object ID or 0, current slugs only
  - `find_superseded( $slug, $language, $type = 'post', $taxonomy = '' )` returns object ID or 0
  - `get_base( $language, $base_type )`, `set_base( $language, $base_type, $base )` (`true|WP_Error`), `is_superseded_base( $base, $language, $base_type )` returns bool
  - `forget( $object_id, $type )` clears an object's slugs

- [ ] **Step 1: Write failing tests in `test-slug-store.php`**
  - `test_set_then_get_round_trips` : `set( 5, 'de', 'open-day-de' )` is `true`; `get( 5, 'de' )` is `'open-day-de'`.
  - `test_set_sanitizes_and_bounds` : input `'Open Day!!'` stores `'open-day'`; a 300-char slug is cut to `Slug_Store::MAX_LENGTH`.
  - `test_rejects_empty_after_sanitize_and_reserved` : `set( 5, 'de', '!!!' )`, `'feed'`, `'page'`, `'2'` each return `WP_Error` and store nothing.
  - `test_conflict_with_other_translated_slug_is_rejected` : product 5 has `'x'` in `de`; `set( 6, 'de', 'x' )` is `WP_Error`; `set( 6, 'fr', 'x' )` is `true`.
  - `test_conflict_with_untranslated_canonical_slug_is_rejected` : product 7 has canonical `'y'` and no `de` slug; `set( 6, 'de', 'y' )` is `WP_Error`.
  - `test_changing_a_slug_keeps_the_old_one_as_superseded` : set `'a'` then `'b'`; `find( 'b', 'de' )` is 5; `find( 'a', 'de' )` is 0; `find_superseded( 'a', 'de' )` is 5.
  - `test_derive_appends_numeric_suffix_on_conflict` : `derive( 'Open Day', 6, 'de' )` is `'open-day-2'` when `'open-day'` is taken.
  - `test_forget_releases_the_slug` (Review Focus 3): after `forget( 5, 'post' )`, `set( 6, 'de', 'a' )` is `true`.
  - `test_bases_round_trip_and_supersede` : `set_base( 'de', 'product', 'produkt' )` then `'artikel'`; `is_superseded_base( 'produkt', 'de', 'product' )` is true.

- [ ] **Step 2: Run to verify failure.** Run: `phpunit --filter Slug_Store_Test`. Expected: FAIL, class `Slug_Store` not found.
- [ ] **Step 3: Implement `Slug_Store` as above.** Lookup by plain meta key plus value; cache the per-language slug to ID map for the request (`wp_cache_*`) and invalidate on `set`/`forget`. Conflict check covers the same object type (and taxonomy, and parent for hierarchical terms), within one language.
- [ ] **Step 4: Run to verify pass.** Expected: PASS; full suite unchanged apart from the new tests.
- [ ] **Step 5: Commit** `feat: store translated slugs and permalink bases per language`.

### Task 2: Slug_Resolver

**Files:**
- Create: `integrations/woocommerce/slug-resolver.php`
- Test: `tests/woocommerce/test-slug-resolver.php`

**Interfaces:**
- Consumes: `Slug_Store` (Task 1), `linguator_default_language( 'slug' )`.
- Produces: `Slug_Resolver` with
  - `__construct( Slug_Store $store )`
  - `const CANONICAL_UNDER_PREFIX = 'not_found'` (the one switch for the 404-versus-301 SEO trade-off; the other accepted value is `'redirect'`)
  - `permalink( $type, $object_id, $language, $taxonomy = '' )` returns the full URL string in `$language`, translated slug and base where they exist, canonical otherwise, default language always canonical
  - `resolve( $slug, $type, $language, $taxonomy = '' )` returns `array( 'action' => 'pass'|'map'|'redirect'|'not_found', 'object_id' => int, 'slug' => string, 'url' => string )` where `slug` is the canonical slug to hand to WordPress
  - `resolve_base( $base, $base_type, $language )` same shape, for the base segment

- [ ] **Step 1: Write failing tests in `test-slug-resolver.php`**
  - `test_rule_1_canonical_slug_404s_when_translated_exists` : product 5 canonical `open-day`, `de` slug `open-day-de`; `resolve( 'open-day', 'post', 'de' )['action']` is `'not_found'`.
  - `test_rule_2_canonical_slug_passes_when_no_translation` : no `de` slug; action is `'pass'`.
  - `test_translated_slug_maps_to_canonical` : `resolve( 'open-day-de', 'post', 'de' )` is `'map'`, `slug` is `'open-day'`, `object_id` is 5.
  - `test_superseded_slug_redirects_to_current_url` : action `'redirect'`, `url` equals `permalink( 'post', 5, 'de' )`.
  - `test_rule_3_base_follows_the_same_rule_per_base_type` : product base translated, category base not; `resolve_base( 'prodotto', 'product', 'de' )` is `'not_found'`, `resolve_base( 'categoria-prodotto', 'category', 'de' )` is `'pass'`.
  - `test_rule_4_default_language_is_always_canonical` : in `it`, `permalink()` ignores stored slugs.
  - `test_canonical_under_prefix_switch` : with the constant overridden to `'redirect'`, rule 1 returns `'redirect'` to the translated URL.
  - `test_permalink_for_a_term_with_translated_parent_and_untranslated_child` (Review Focus 5): `/de/<de-base>/<de-parent>/<canonical-child>/`, and the reverse.

- [ ] **Step 2: Run to verify failure.** Expected: FAIL, class not found.
- [ ] **Step 3: Implement `Slug_Resolver`.** Build URLs with the existing `Linguator_Links_Model::add_language_to_link()` through the same helper `switch_language_in_url()` already used in `woocommerce.php`; do not hand-roll the prefix.
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** `feat: add the slug resolver enforcing the translated-slug rules`.

### Task 3: Slug_Routing (inbound)

**Files:**
- Create: `integrations/woocommerce/slug-routing.php`
- Modify: `integrations/woocommerce/woocommerce.php` (construct and `init()` it where `Seo` is, shared mode only; narrow `keep_shared_object_url_from_redirecting` so it still cancels core's redirect for translated URLs but not for retired canonical ones); `integrations/woocommerce/load.php`; `tests/bootstrap.php`
- Test: `tests/woocommerce/test-slug-routing.php`

**Interfaces:**
- Consumes: `Slug_Resolver::resolve()`, `::resolve_base()`.
- Produces: `Slug_Routing` with `__construct( Slug_Resolver $resolver, Slug_Store $store )`, `init()`, `map_request( $query_vars )` returns the query vars with a translated slug replaced by the canonical one (and sets the not-found / redirect outcome on the request), `rewrite_rules( $rules )` returns rules including one set per non-default language with a translated base, `flush_if_bases_changed()`.

- [ ] **Step 1: Write failing tests in `test-slug-routing.php`**
  - `test_translated_product_slug_is_mapped_to_canonical_query_var` : request `/de/produkt/open-day-de/` yields `product => 'open-day'`.
  - `test_retired_canonical_slug_sets_404_and_never_redirects_home`.
  - `test_superseded_slug_produces_one_301_to_the_current_url` (no chain).
  - `test_request_variants_resolve_like_the_plain_form` (Review Focus 2): upper-case, missing trailing slash, percent-encoded slug, and `/page/2/` on a translated category.
  - `test_translated_parent_with_untranslated_child_resolves` and the reverse (Review Focus 5).
  - `test_rewrite_rules_exist_only_for_languages_with_a_translated_base`.
  - `test_nothing_changes_when_shared_mode_is_off`.

- [ ] **Step 2: Run to verify failure.** Expected: FAIL.
- [ ] **Step 3: Implement `Slug_Routing`.** Hook early in request parsing (`request` filter or `parse_request`); the builder picks the hook per WordPress version. Flush rewrite rules only when `lmat_wc_permalink_bases` changes.
- [ ] **Step 4: Run to verify pass** in both modes.
- [ ] **Step 5: Commit** `feat: resolve translated slugs and bases before WordPress parses the URL`.

### Task 4: Slug_Links (outbound links and switcher)

**Files:**
- Create: `integrations/woocommerce/slug-links.php`
- Modify: `integrations/woocommerce/woocommerce.php` (`product_translation_url()` and `term_archive_translation_url()` return `Slug_Resolver::permalink()` for the *target* language)
- Test: `tests/woocommerce/test-slug-links.php`; extend `test-object-translation-url.php`

**Interfaces:**
- Consumes: `Slug_Resolver::permalink()`.
- Produces: `Slug_Links` with `__construct( Slug_Resolver $resolver )`, `init()`, `post_link( $url, $post )` (hooked on `post_type_link`, products only), `term_link( $url, $term, $taxonomy )` (hooked on `term_link`, `product_cat`/`product_tag`/`product_brand`/`pa_*`).

- [ ] **Step 1: Write failing tests**
  - `test_product_permalink_uses_the_translated_slug_in_the_current_language`.
  - `test_switcher_url_for_a_product_targets_the_translated_slug_of_the_other_language`.
  - `test_term_archive_switcher_url_uses_the_translated_term_slug_and_base`.
  - `test_untranslated_object_keeps_the_canonical_slug_in_every_language`.
  - `test_pa_term_link_uses_translated_attribute_slug`.
  - `test_building_links_for_a_catalogue_page_issues_one_slug_query_not_one_per_product`: `Slug_Store` primes slug meta for the loop's product IDs in one call, like `Cart` already primes translation meta (needs `Slug_Store::prime( array $object_ids, $language, $type )`).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement `Slug_Links` and update the two switcher methods.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** `feat: emit translated product and term URLs and switcher targets`.

### Task 5: Crawler-facing URLs (hreflang, canonical, sitemap, structured data)

**Files:**
- Modify: `integrations/woocommerce/slug-links.php`, `integrations/woocommerce/seo.php` (canonical and `og:url` through SEOPress), `modules/sitemaps/` consumers as needed
- Test: extend `tests/woocommerce/test-slug-links.php`, `test-seo.php`

**Interfaces:**
- Consumes: `Slug_Resolver::permalink()`.
- Produces: `Slug_Links::canonical_url( $url )`, `::sitemap_url( $url, $object )`, `::schema_url( $markup )`, each returning the translated URL for the current language.

- [ ] **Step 1: Write failing tests**
  - `test_hreflang_alternates_are_translated_urls_and_reciprocal`.
  - `test_canonical_is_self_referencing_on_the_translated_url`.
  - `test_sitemap_lists_one_translated_url_per_language_never_both_forms`.
  - `test_json_ld_product_url_and_og_url_are_translated`.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement the four filters; route SEOPress canonical/`og:url` through `Slug_Links`.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** `feat: serve translated URLs to search engines and structured data`.

### Task 6: Product slug field and derivation

**Files:**
- Modify: `integrations/woocommerce/admin/product-panel.php` (`descriptors()`, `collect()`, `save()`, view), `integrations/woocommerce/admin/views/product-panel.php`
- Test: extend `tests/woocommerce/test-product-panel.php`

**Interfaces:**
- Consumes: `Slug_Store::set()`, `::derive()`.
- Produces: a per-language slug field in the panel; on save, a conflict `WP_Error` is shown with the existing `lmat_wc_panel_error_<user>` transient mechanism.

- [ ] **Step 1: Write failing tests**
  - `test_panel_saves_an_entered_slug_through_the_store`.
  - `test_empty_slug_is_derived_from_the_translated_name`.
  - `test_existing_slug_is_never_overwritten_by_a_later_name_change`.
  - `test_conflicting_slug_shows_an_error_and_keeps_the_old_one`.
  - `test_save_requires_nonce_and_edit_post_capability` (unchanged behaviour, assert it still holds with the new field).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Add the slug descriptor and wire `save()` to `Slug_Store`.** Escape at output; unslash then `sanitize_title`.
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** `feat: let the product panel enter and derive translated slugs`.

### Task 7: Term slugs screen and permalink bases screen

**Files:**
- Create: `integrations/woocommerce/admin/term-slugs.php`, `integrations/woocommerce/admin/permalink-bases.php`
- Modify: `integrations/woocommerce/load.php`, `integrations/woocommerce/woocommerce.php`, `tests/bootstrap.php`
- Test: `tests/woocommerce/test-term-slugs.php`, `test-permalink-bases.php`

**Interfaces:**
- Consumes: `Slug_Store::set()`, `::set_base()`.
- Produces: `Term_Slugs` with `__construct( Slug_Store $store )`, `init()`, `render_field( $term )`, `save( $term_id, $tt_id, $taxonomy )`; `Permalink_Bases` with `__construct( Slug_Store $store )`, `init()`, `render()`, `save()`. The builder puts the bases screen where the existing settings screen already works (Linguator settings, or beside WooCommerce permalinks).

- [ ] **Step 1: Write failing tests**
  - `test_term_screen_saves_a_translated_slug_for_category_tag_brand_and_attribute_terms` (`product_cat`, `product_tag`, `product_brand`, and every `pa_*` taxonomy).
  - `test_empty_term_slug_is_derived_from_the_translated_term_name_when_one_exists`, and left empty when none exists.
  - `test_term_screen_skips_brand_when_the_taxonomy_does_not_exist`.
  - `test_term_slug_save_requires_nonce_and_edit_term_capability`.
  - `test_bases_screen_saves_per_language_per_base_type_and_flushes_once`.
  - `test_bases_screen_warns_but_does_not_block_a_base_that_collides_with_a_page_path`.
  - `test_bases_screen_requires_manage_options`.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement both classes.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** `feat: add slug fields on product terms and per-language permalink bases`.

### Task 8: Attribute filters, crawler rules and the clone proof (PR)

**Files:**
- Modify: `integrations/woocommerce/slug-routing.php` (attribute query-var mapping)
- Test: `tests/woocommerce/test-slug-routing.php`; manual proof on `dev-linguatorbeltracing.local`

**Interfaces:**
- Consumes: `Slug_Resolver`, `Slug_Store::find()`.
- Produces: `Slug_Routing::map_attribute_value( $value, $taxonomy, $language )` returns the canonical term slug, accepting translated or canonical values; `$_GET` is never rewritten globally.

- [ ] **Step 1: Write failing tests**
  - `test_filter_query_accepts_translated_and_canonical_values`.
  - `test_prefixed_url_without_cookie_or_accept_language_is_not_redirected` (Review Focus 4).
  - `test_retired_url_returns_a_real_404_status`.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement `map_attribute_value` and the hook that reads the layered-nav query var.**
- [ ] **Step 4: Run the full suite in both modes.** Expected: all pass, 0 failed, no new skips beyond the existing 2.
- [ ] **Step 5: Clone proof, then PR.** With shared mode on: German product, category, tag and attribute-filter URLs; switcher and hreflang land on translated URLs; the canonical slug 404s once a translated one exists; an untranslated product still opens. Then `curl -sI -A "Googlebot" ...` and `-A "GPTBot" ...`, no cookie: statuses, one canonical, reciprocal hreflang, JSON-LD `url`, translated text in raw HTML. Run `phpcs` on the changed files only and fix what is new. Commit `test: prove translated slugs against search and AI crawlers`; push; open the PR against the fork's `main`.
