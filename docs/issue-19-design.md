# Issue #19: shared WooCommerce product identity

Status: proposed architecture and implementation sequence, updated with the requested automatic API translation flag; no plugin behavior implemented. Claude Code handoff: `docs/issue-19-claude-handoff.md`.
Baseline: local commit `8dadb33`, Linguator 2.2.0. Issue inspected on 2026-09-12; open, no maintainer comments.
User requirement: include simple products, variable products, and variations in the first version.

## Verified findings

- `includes/models/translated/translated-object.php:157` stores language-to-object-ID relationships; `validate_translations()` checks that each object has the matching language. Mapping one ID to every language would violate this model.
- `includes/models/translated/translated-post.php:166` selects translated post types through `lmat_get_post_types`; this is an early hook and the result is cached after theme setup.
- `modules/rest/v1/bulk-translation.php:1618` saves translated content through `Linguator_Sync_Post_Model::copy_post()`. `modules/sync/sync-post-model.php:191` inserts a post. The existing save destination is therefore not reusable unchanged.
- `modules/page-translation/page-translation.php` expects a new translated post or a pending translated post. Its classic-editor adapter writes directly into title, content, and excerpt inputs. Reusing it on the original product would overwrite source text.
- `includes/other/query.php:178` filters queries by language taxonomy. `frontend/services/frontend-links.php:54` resolves language-switch URLs through translated IDs, with `lmat_pre_translation_url` as an extension point.
- `integrations/integration-build.php` contains no WooCommerce module. Small editor accommodations for product excerpts do not establish complete WooCommerce compatibility.
- Settings use React, Force UI, Tailwind, Lucide, and WordPress i18n. PHP uses Linguator namespaces, hook-based loaders, and the `translate-words` text domain.
- No project test suite/configuration or local WordPress/WooCommerce environment was found in the inspected checkout. `npm test` is a failing placeholder; Composer declares PHPUnit/WPCS dependencies and scripts, which does not prove an executable test setup.

## Recommended approach

Decision summary: **one optional WooCommerce integration, one translation panel, and the existing Linguator translation engine**. WooCommerce keeps product/variation identity and all commercial operations. The integration owns only localized text and the adapters required to display and edit it.

Add an optional integration under `integrations/woocommerce/`, disabled by default. Keep the existing translated-post model for ordinary content. In shared mode, product identity and commercial data remain owned by WooCommerce; Linguator stores and renders localized text separately.

Simplicity rule: reuse the existing language registry, switcher, provider configuration, AI translation services, and visual components. Add only the missing product text repository and WooCommerce adapters. Do not introduce a second language registry, AI engine, custom product tables, general-purpose translation framework, or separate dashboard. Group responsibilities into small files only when justified by actual code, rather than treating the file map below as a mandatory class count.

The existing inline-translation module is an editor tool: its classic adapter calls `props.setContent(value)`. It is not a runtime multilingual product store and cannot replace this integration by itself. Its UI/provider parts may still be reusable.

Alternatives: a CRM canonical-ID mapping is smaller but leaves duplicate WooCommerce products and does not meet the requested identity contract. Replacing Linguator's global translation model has a much wider regression surface. The isolated WooCommerce integration best matches the requirement and repository structure.

### Identity and storage

- Keep one parent product and its existing variation records. Never create translated products or variations, or translate SKU, price, stock, tax, dimensions, attribute keys, term IDs, or attribute option slugs.
- Proposed storage: one private, versioned translation metadata record per object and target language, accessed through WooCommerce CRUD metadata methods behind a dedicated repository. Read original content in edit context. Avoid a single multilingual blob that lets one language update overwrite another.
- Parent fields: name, description, short description. Variation fields: description; derive the displayed variation name from the localized parent name and attribute labels.
- Include display translations for variation attribute labels/options. Global terms retain their canonical IDs/slugs and use a separate translation metadata store via WordPress term APIs. Custom product attributes retain their canonical values and use a product-local label map. Never feed translated labels into variation matching.
- Store source language, source fingerprint, and status alongside translated fields. Missing translation displays source content; an intentionally empty field is distinct from a missing field. Source edits mark affected translations as needing review. Concurrent writes to the same language must reject stale revisions.

### Rendering and language context

- Exclude shared-mode products and variations from ordinary post duplication/language assignment. Account for existing product taxonomy settings; global removal from a post-type list alone does not prove mixed search and archive queries work.
- Resolve language through Linguator, retaining the original product slug. Adapt product links and the existing switcher so language changes keep the same product. Validate direct URLs, redirects, categories, shop pagination, and mixed searches.
- Use WooCommerce view getters for localized product fields and narrowly scoped WordPress display filters where templates read post fields directly. Do not mutate canonical objects or globally replace `get_post()` results.
- Handle classic variation JSON and AJAX requests as well as Store API requests used by blocks. A blanket REST/AJAX exclusion would break the storefront. Separate storefront language context from administrative/CRM API context explicitly.
- Default management REST responses, exports, and webhooks retain canonical identity and source content. Include explicit opt-in translation reads on native WooCommerce product/variation routes in the initial design, as specified below.
- Cart and order items keep the same product/variation IDs. Capture the checkout language and localized display labels when creating order items; historical order text must not change with the viewer language. Validate stock deduction, refunds, emails, and CRM order reads.
- Key rendered caches by language and invalidate affected entries after translation/source changes. Avoid per-field database queries in catalog loops. Language-aware full-page caches and cross-domain cart sessions require environment-specific validation.

### Native WooCommerce API translation reads

Requirement added on 2026-09-12: expose translations through existing WooCommerce endpoints. This means an extension using supported WordPress/WooCommerce mechanisms, not built-in multilingual support in unmodified WooCommerce. The following parameters and response fields are proposed integration contracts, not currently implemented features.

Add a separate boolean setting, proposed key `woocommerce_rest_include_translations`, default `false`, inside the existing Linguator settings system. UI label: **Include all translations in WooCommerce API responses**. Help text: **Automatically include translations in product and variation responses, including lists. Product IDs, prices and stock remain shared.** Show it in the WooCommerce settings section, enabled only when shared-product mode is active. Reuse the existing settings authorization and sanitization flow; do not add a second settings store.

The flag controls automatic inclusion of an additional `lmat_translations` object, keyed by configured language slug. It never turns one product into multiple list entries. Top-level fields remain in the source language unless `lang` is explicitly requested.

| Flag | Query parameters | Top-level text | `lmat_translations` |
| --- | --- | --- | --- |
| Off | None | Source | Omitted |
| On | None | Source | All configured languages |
| Off | `lang=de` | German, with documented source fallback | Omitted |
| On | `lang=de` | German, with documented source fallback | All configured languages |
| Either | `lmat_include_translations=true` | Source, or explicit `lang` | All configured languages |
| Either | `lmat_include_translations=false` | Source, or explicit `lang` | Omitted |

Precedence: an explicitly supplied `lmat_include_translations` boolean overrides the saved flag for that request; `lang` independently selects top-level display text. Reject invalid booleans and unknown languages with 400. Respect `_fields`: an explicitly excluded translation field is not returned or unnecessarily loaded. Parameters are proposed and must be registered with schemas on supported routes.

Apply this exact behavior to all four route shapes:

- `GET /wp-json/wc/v3/products/100`
- `GET /wp-json/wc/v3/products?page=2&per_page=20`
- `GET /wp-json/wc/v3/products/100/variations/101`
- `GET /wp-json/wc/v3/products/100/variations?page=1&per_page=20`

With the flag enabled, each product in the normal products array contains its own translation map without requiring `lang` or `lmat_include_translations`. A variable parent's standard `variations` array remains an array of IDs; full variation translations are returned by the native variation detail/list routes. Do not embed all child variations in every parent response.

Include the source language and explicitly mark missing/partial translations. Do not claim source fallback text is a completed translation. Preserve intentionally empty fields. Language maps contain only allowlisted product/variation text, translated attribute display labels, and necessary status metadata; no private storage internals.

- Apply the same read contract to product and variation collections. Preserve pagination, ordering, and existing source-based search semantics; localized search indexing is separate scope.
- Reuse Linguator's `lang` convention: `includes/controllers/rest-request.php:179` already reads it. Validate explicitly on supported WooCommerce routes because the existing handler silently falls back for invalid languages. Unknown languages return 400; missing field translations use source content with explicit language/fallback metadata. Do not use browser cookies to select language for management API responses.
- Prefer additional REST fields with declared schemas and WooCommerce response extension hooks; preserve native controllers, authentication, permissions, and `_fields` filtering. The variation controller exposes `woocommerce_rest_prepare_product_variation_object`. Confirm the corresponding product controller/inherited hooks against the supported WooCommerce version before implementation.
- Keep canonical attribute identifiers and option values unchanged even on localized responses. Expose translated attribute labels separately; a client must not mistake translated display text for an update/matching value.
- Treat translation payloads as read-only in this increment. Standard POST/PUT/PATCH/batch writes retain canonical WooCommerce semantics; reject integration language parameters on writes instead of silently overwriting source content. Translation writes remain explicit authorized operations. GET localization never saves a product or changes its modified date.
- Return only allowlisted translation fields and necessary status metadata. Internal storage keys, source fingerprints, prompts, and private translation metadata must not leak through native `meta_data`; a leading underscore alone is not an API access policy. Full maps require the existing authenticated product-read permissions and must respect product visibility/context.
- For public Store API consumers (`wc/store/v1`), use the documented `woocommerce_store_api_register_endpoint_data()`/ExtendSchema mechanism under `extensions.linguator`. Expose only public display translations, never administrative drafts or private metadata. This is a distinct adapter from management REST; test product, variation selection, cart, and checkout responses independently.
- Add API regression tests for default responses, one-language and all-language reads, collections and `_fields`, missing/invalid translations, canonical attributes, unchanged IDs/commercial fields, authorization, private metadata, cache isolation, and rejected ambiguous writes.
- Test every flag/query combination above on both detail and collection routes, for products and variations. List length, order, `X-WP-Total`, and `X-WP-TotalPages` must not change when translations are included. Prime translation data for the current page; avoid one database query per product/language/field and never load the full catalog. Cache keys/invalidation must account for the effective include flag, language, selected fields, permissions/context, and translation updates.
- This flag applies only to authenticated management REST product/variation GET responses in shared mode. It does not automatically expand public Store API responses, order responses, exports, webhooks, write/batch responses, or unrelated Linguator routes. Shared mode disabled means this adapter is inactive even if its saved flag is true.

The only additional UI is the checkbox in the existing WooCommerce settings section. No additional screen is required. The current Superdesign product-editor draft does not yet depict that settings checkbox. Support for a native endpoint does not imply automatic support in every external client; consumers of `lmat_translations` or `extensions.linguator` must understand the documented fields.

## UI proposal for Superdesign

[Review the product translation panel](https://p.superdesign.dev/draft/efe151e0-6edd-41ba-8721-e0a9ed034806), version 2. The draft includes product fields, expandable variation rows, tab selection, and local save/discard feedback. It is a visual prototype; AI calls, rich-text editing, other languages, and storefront previews are not connected to WordPress. Browser rendering was not verified in this session because the configured Playwright browser executable is unavailable.

1. A WooCommerce settings section using existing Force UI controls: shared mode, the automatic API translations checkbox described above, supported product types, and a clear status if legacy translated products prevent activation.
2. A product translation panel in the existing product editor: target language, source and translated name/descriptions, existing AI provider action, manual editing, save state, and preview.
3. Expandable variation rows using existing WooCommerce variation organization: localized description and attribute labels, with shared product/variation IDs shown as read-only context. Paginate large variation sets.
4. Text labels for missing, translating, translated, needs review, and failed states; preserve unsaved edits on errors and warn before changing language with unsaved edits. Keyboard-accessible controls and narrow-screen stacking.

Keep the current storefront layout. Reuse Linguator's green `#30B230`, gray `#F3F4F6` background, white bordered panels, inherited typography, and existing icons. Scope styles to the new panel. No new graphics library, decorative illustrations, or redesign is required. The new panel is a proposed target, not an existing screen to reproduce.

## Proposed file boundaries

| Location | Responsibility |
| --- | --- |
| `integrations/woocommerce/load.php` | Optional activation and early Linguator hooks |
| `integrations/woocommerce/woocommerce.php` | Integration coordinator |
| `integrations/woocommerce/translation-repository.php` | Versioned product/variation text storage |
| `integrations/woocommerce/attribute-translations.php` | Canonical attribute identity and localized labels |
| `integrations/woocommerce/frontend.php` | Display, language context, product URLs, storefront API adapters |
| `integrations/woocommerce/admin.php` and `views/` | Product/variation translation panel and scoped assets |
| `integrations/woocommerce/rest-controller.php` | Authorized translation reads/writes and payload validation |
| `integrations/woocommerce/rest-api.php` | Translation read adapters and schemas for native WooCommerce REST/Store API routes |
| `integrations/woocommerce/orders.php` | Order language and display-text snapshots |
| `admin/settings/views/src/components/general.jsx`, `modules/rest/v1/settings.php` | Shared-mode and automatic API translation settings, persistence, incompatible-state validation |
| `modules/page-translation/`, `modules/bulk-translation/`, `modules/rest/v1/bulk-translation.php` | Reuse provider processing; select a separate product translation destination |
| `modules/rest/v1/languages.php`, ordinary editor/duplication entry points | Reject ordinary translated-post creation/linking for shared products |
| `tests/woocommerce/` and test bootstrap | Integration and regression coverage |

These are proposed new files, not implemented APIs. `integration-build.php` declares itself generated: locate its generator/build contract before registering the module; do not silently hand-edit it. Verify autoload generation and affected JS entry points before implementation.

## Implementation sequence and acceptance gates

1. Confirm WooCommerce/WordPress versions, product editor, theme, cart/checkout blocks, CRM endpoints, and existing translated catalog. Establish a disposable integration test environment and baseline simple/variable fixtures.
2. Add failing storage/identity tests, then the optional integration, repositories, and protected translation API. Reject unsupported product types, unknown languages, mismatched variation parents, stale writes, and unauthorized updates. Sanitize rich descriptions without flattening permitted HTML.
3. Implement and test catalog/query/link/display adapters. Verify both languages reach the same IDs; pages/posts retain existing translation behavior. Check missing/empty/stale translations and cache isolation.
4. Implement and test variation labels, matching, AJAX/Store API, cart, checkout, and order snapshots. Test default attributes, custom/global attributes, any-value variations, out-of-stock variations, refunds, and concurrent sessions in different languages.
5. After UI review, build the scoped settings/product panels and provider destination adapter. Verify manual and AI editing, save failures, retries, no source overwrites, and large variation lists. Bulk translation must use the same repository contract or reject shared products until its adapter is ready.
6. Implement and test the native REST read adapter and automatic-inclusion flag across product/variation details and collections, using the complete precedence matrix above. Verify settings persistence, default-off behavior, explicit overrides, permissions, pagination, `_fields`, metadata protection, and page-bounded loading.
7. Run focused PHP tests, changed-file PHPCS/PHP lint, affected JS builds, and browser scenarios. Compare product/variation counts, IDs, SKU, stock, prices, and order references before/after. Test disabled mode, WooCommerce absent, and supported version boundaries.

### Minimum compatibility matrix

| Existing contract | Minimal integration treatment | Required evidence |
| --- | --- | --- |
| Language registry and lifecycle | Use initialized Linguator APIs; register post-type hooks before their cached list is built | Same configured languages in admin, frontend, AJAX, and REST contexts |
| Page, bulk, and inline AI translation | Reuse providers and extraction; direct product results to the translation repository | All three paths preserve original fields and create no product/variation IDs |
| Language switchers and URLs | Extend existing link resolution; support explicit product IDs and hide-if-missing options | Same product after language switch, no missing link/404 or redirect loop |
| Product queries and synchronized metadata | Keep commercial fields canonical; isolate translation keys from ordinary metadata copy/sync | Stable counts, mixed searches, product/category archives, and unchanged stock/SKU/prices |
| Variable product purchase | Localize labels only; retain canonical attribute values and existing variation IDs | Classic and block storefronts select and order the same variation |
| CRM, orders, and management APIs | Preserve native IDs and source-data contract; snapshot customer-facing order text | Order exports, webhooks, refunds, and CRM reads retain identity |
| SEO, cache, and builders | Inspect actual enabled integrations and adapt their translated-post assumptions explicitly | Canonical/hreflang/sitemap checks, per-language cache separation, and supported template rendering |

Prioritize a manually saved translation passing the complete variable-product purchase flow before adding AI and bulk UI. This proves the architecture with the smallest useful implementation; AI cannot compensate for a broken identity or rendering contract.

## Boundaries and unresolved prerequisites

- Ecosystem compatibility means preserving existing contracts and testing each relevant integration, not promising universal support. Inventory enabled Linguator modules and test shared products with page/bulk/inline translation, synchronization, custom fields, switchers, REST, SEO, caches, and active builders. Existing post/page flows must keep their original behavior. Where a module requires distinct translated IDs, add a small explicit adapter or report the unsupported operation; never pretend repeated identical IDs satisfy that contract.
- Concrete SEO dependency: `integrations/wpseo/wpseo.php` and `integrations/rankmath-seo/rankmath-lmat.php` branch on `linguator_is_translated_post_type()`. Excluding products from ordinary translation changes those paths. Baseline canonical/hreflang/sitemap compatibility must be tested and adapted before claiming compatibility with either integration; translating custom SEO fields can remain separate scope.
- Prefer existing public hooks and WooCommerce CRUD methods for long-term compatibility. If a required extension point is absent, propose one narrow, backward-compatible hook for maintainer review instead of rewriting a core subsystem.

- Existing translated products require a separate audited migration: selecting canonical IDs, preserving CRM/order references, redirects, and backups. First activation must detect and block incompatible catalogs rather than hide or merge them automatically. Disabling shared mode preserves metadata and must not reactivate an incompatible duplicate catalog implicitly.
- Translated slugs, full SEO integration, category/tag translation, localized search indexing, third-party builders, and specialized product extensions are separate scope. Existing categories/tags must still work with canonical terms. Basic product URL/canonical behavior is part of storefront validation.
- Variable product support is required now, not a later milestone. Attribute display translation is recommended to make that support useful in other languages.
- No runtime reproduction or WooCommerce compatibility claim is made by this analysis. Maintainer acceptance is still unknown. The product-panel draft exists; the implementation-ready plan and version-specific hook matrix still require the checks above.

## Sources

- [Issue #19](https://github.com/CoolPluginsTeam/translate-words/issues/19)
- [WooCommerce CRUD objects](https://developer.woocommerce.com/docs/best-practices/data-management/crud-objects/)
- [WooCommerce view/edit getter behavior](https://woocommerce.github.io/code-reference/files/woocommerce-includes-abstracts-abstract-wc-data.html)
- [Variable product source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-product-variable.html)
- [Cart source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-cart.html)
- [WooCommerce REST product controller](https://woocommerce.github.io/code-reference/files/woocommerce-includes-rest-api-controllers-version3-class-wc-rest-products-controller.html)
- [WooCommerce REST variation controller](https://woocommerce.github.io/code-reference/files/woocommerce-includes-rest-api-controllers-version3-class-wc-rest-product-variations-controller.html)
- [Store API extensible endpoints](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/available-endpoints-to-extend)
