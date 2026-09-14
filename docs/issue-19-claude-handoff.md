# Claude Code handoff: issue #19

## Read first

1. `AGENTS.md` — Italian, concise user communication; English code, comments, and documentation.
2. `docs/issue-19-design.md` — current design, source evidence, API behavior matrix, proposed file boundaries, and validation sequence.
3. `.claude/rules/` and relevant `.codex/skills/` — apply WordPress security and the style of touched files. `.claude/CLAUDE.md` describes a different plugin; do not use its plugin name, architecture, text domain, or version assumptions.

Use Superpowers for design refinement, planning, TDD, and verification. Use `wp-plugin-development` and `wp-rest-api`; use Superdesign if changing the visual proposal. Respect user requests over generic skill ceremony and keep changes focused.

## Objective and decisions

Study and implement, after design agreement, an optional WooCommerce integration for Linguator issue #19. One product keeps its original ID across languages, including variable products and the original variation IDs from the first release. Store only localized text separately. Reuse Linguator languages, provider configuration, translation processing, switchers, and UI conventions; preserve WooCommerce commercial operations and ordinary translated posts/pages.

The latest user requirement is a checkbox that automatically includes all language translations in native WooCommerce product responses, including the products list, without query parameters. This is part of the planned feature, not an optional future enhancement.

### API contract to preserve

- Proposed setting: `woocommerce_rest_include_translations`, boolean, default off; separate from the shared-product mode switch. Persist through existing Linguator settings.
- Off + no parameters: original WooCommerce response.
- On + no parameters: original top-level fields plus `lmat_translations` for every product/variation returned.
- `lang=xx` selects localized top-level text without changing IDs or commercial fields, with either flag state.
- `lmat_include_translations=true|false` explicitly overrides automatic inclusion. With `lang` it controls the map independently of the selected display language.
- Apply the same rules to `wc/v3/products`, `wc/v3/products/{id}`, `wc/v3/products/{id}/variations`, and `wc/v3/products/{id}/variations/{variation_id}`.
- Keep one list item per original resource; preserve pagination headers, ordering, filters, canonical attributes, and the parent's variation-ID array. All-language output is an additional field, not duplicate products.
- Respect `_fields` and normal API authorization. Limit queries to the requested page. Validate language/boolean inputs; represent missing, partial, and intentionally empty translations correctly. Exclude private translation storage from `meta_data` explicitly.
- Read-only extension in this increment: no implicit translation writes through native PUT/POST/PATCH/batch. Reject ambiguous integration parameters on writes. The management-API flag does not enable public Store API translation dumps or alter orders, exports, or webhooks.
- Store API has a separate public display adapter using supported extension mechanisms. Do not confuse management REST with storefront Store API.

## Work completed

- Read issue #19 and its comments: open, no maintainer response at the 2026-09-12 inspection.
- Inspected local Linguator 2.2.0 at commit `8dadb33`; confirmed distinct-ID translation relationships, post duplication save paths, language-taxonomy query filtering, and ID-based link resolution.
- Found no dedicated WooCommerce integration in the integration manifest. Existing product excerpt editor support does not prove full compatibility.
- Compared native WooCommerce REST/Store API extension points with official sources linked in the design. Target WooCommerce version is not established; verify inherited product hooks against that version.
- Added project instructions, architectural proposal, API contract, and Superdesign context. No PHP/JS plugin implementation, runtime test, commit, push, PR, release, or migration was performed.

## Visual design

- Preview: https://p.superdesign.dev/draft/efe151e0-6edd-41ba-8721-e0a9ed034806
- Canvas: https://superdesign.dev/teams/53bb6ec2-6438-4c90-b60e-cf44edc8d040/projects/eef5138d-dbd5-4fe5-be7e-18db2a0a1cf5
- Version 2: product text, variation rows, tabs, and local save/discard demonstration. No live AI, WooCommerce, language switching, or storefront connection. The new API checkbox is specified in the design but is not depicted in this product-editor draft.
- Resume via `.superdesign/resume.json`; do not create a new project or regenerate context unnecessarily. `.superdesign/tmp/` is ignored and contains disposable draft files. The prototype's CDN/inline scripts are design artifacts, not production implementation patterns.
- Superdesign CLI authentication succeeded. The user explicitly approved sending four context files and the panel brief: `.superdesign/design-system.md`, `.superdesign/init/theme.md`, `.superdesign/init/components.md`, `modules/page-translation/src/popup-setting-modal/header.js`. This is not blanket approval to export other repository content.
- Generated HTML and its saved version were checked; prototype JavaScript passed `node --check`. Full browser rendering was not verified: the configured Playwright browser executable was unavailable. Do not claim browser QA passed.

## Next actions

1. Inspect Git status and preserve all existing files. Prior work includes `.gitignore`, `AGENTS.md`, `docs/`, and `.superdesign/`; treat these as the current handoff baseline, not disposable changes.
2. Reconcile the current design with the user's latest requirements. Product-panel layout has not been explicitly selected/approved. This handoff is not authorization to skip the design agreement required by `AGENTS.md`.
3. Determine the actual WordPress/WooCommerce versions, product editor, theme/builders, cart/checkout blocks, enabled integrations, CRM API usage, and existing duplicated translations. If absent, ask one concise bundled question; do not fabricate the target environment.
4. Locate the integration-manifest generator/build contract and autoload process. `integrations/integration-build.php` is marked generated; do not silently hand-edit it. Source header declares WP 6.8+/PHP 7.2+; do not raise requirements unintentionally.
5. Prepare an implementation-ready plan using the agreed design. The existing document is an architectural sequence, not executable code or a fully resolved hook matrix.
6. Establish a disposable WP/WooCommerce test environment. First prove a manually stored translation through variable-product purchase with stable IDs. Then add provider/UI adapters and the API flag/response matrix with meaningful regression tests.
7. Verify page/bulk/inline translation, synchronization, custom fields, all switcher modes, mixed catalog queries, SEO, cache, REST/Store API, and supported builders. Add small adapters where contracts differ; never fake a language-to-ID translation group by repeating the same ID.

## Validation and scope limits

- `npm test` is a placeholder; Composer declaring PHPUnit/WPCS does not establish a runnable suite. Set up and verify the actual test bootstrap before claiming test coverage.
- API acceptance must exercise both flag states, explicit overrides, product and variation lists/details, missing/invalid language data, `_fields`, permissions, metadata privacy, stable commercial data, and cache isolation.
- Reproduce legacy-catalog conflicts before enabling shared mode. No automatic merge, deletion, re-keying of existing products, or migration of CRM/order references is authorized.
- Advanced localized slugs, custom SEO field translation, category/tag translation, localized search indexing, specialized product extensions, and broad builder support require explicit scope decisions. Baseline compatibility with enabled integrations must still be verified.
- Report verified outcomes and unresolved limitations concisely. Do not equate source inspection or a visual prototype with a working WooCommerce integration. Do not commit/push/publish as part of merely resuming this handoff.
