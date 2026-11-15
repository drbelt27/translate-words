# Shared WooCommerce product identity — Phase 1 design

Status: agreed with the user on 2026-09-15; reviewed with the user on 2026-09-15 and amended below.
Not implemented.
Baseline: local commit `251aa3c`, Linguator 2.2.0.
Supersedes the Phase 1 portions of `docs/issue-19-design.md`, which remains the reference for the
later phases. Upstream issue: [#19](https://github.com/CoolPluginsTeam/translate-words/issues/19).

## Problem

One product must keep a single identity across every language: one product ID, one set of variation
IDs, one SKU, one price, one stock figure, one order history. Only the text differs per language.

Linguator's existing model translates a post by creating a second post in the target language and
linking the two. Applied to WooCommerce that produces a duplicated catalogue: two product IDs, two
stock counters, two sets of orders, and a CRM that can no longer tell which product was sold.

## Measured environment

Taken from a full production clone on 2026-09-14 (WordPress 7.1, WooCommerce 11.1.0,
Elementor 4.2.4 + Pro, SEOPress, W3 Total Cache, Pods, woo-multi-currency):

| Fact | Value | Why it matters |
| --- | --- | --- |
| Products | 121 active (65 publish, 56 private) | The private half means visibility rules must be preserved exactly |
| Variations | 248 across 44 variable products | Variation support is not optional; it is over a third of the catalogue |
| Products with a translation | **0** — all 121 are Italian | No duplicated legacy catalogue to migrate on this site |
| Global attribute taxonomies | **0** | Every variation attribute is product-local; no global term translation needed |
| `product_cat` terms | 14, actively used, none translated | Category labels are part of the storefront a customer reads |
| Languages | `it` (default, prefix hidden), `de`, `fr`, `en` | Four languages, directory URL mode |
| `product` in Linguator's translated post types | **yes**, along with `product_cat`, `product_tag`, `product_brand` | Shared mode must take them out of that model |

The business symptom: the Italian shop lists 30 purchasable products, the German shop lists 1.

## Phases

Issue #19 covers five subsystems and is too large for one specification. It is split into phases,
each of which produces working, testable software and its own pull request.

| Phase | Content |
| --- | --- |
| **1 (this document)** | Shared identity, text storage, storefront rendering, variations, cart, checkout, order snapshots, SEO meta, minimal entry panel |
| 1b | Translated permalink base and per-language slugs, with redirects and conflict handling |
| 2 | Full product translation panel, manual editing, AI provider reuse, bulk translation |
| 3 | `lmat_translations` on native WooCommerce REST routes, the `woocommerce_rest_include_translations` setting, Store API adapter |

## Decisions taken

Recorded because they constrain everything below, and because two of them — categories and SEO meta —
overrule the earlier design document, which placed both out of scope.

1. **Storage: post meta, one record per object per language**, read through a repository. Not a
   custom table (disproportionate at this catalogue size, and it would leave WooCommerce's CRUD),
   and not hidden shadow posts (they reintroduce the extra IDs the issue exists to remove).
2. **Product categories, tags and brands get the same treatment as products**: one canonical term,
   translated label stored separately. Leaving them as translated taxonomies while products become
   shared would put two contradictory models in the same query layer.
3. **URLs in Phase 1 carry the language prefix only** — `/de/prodotto/tassa-di-socio/`. Translated
   base and slug are Phase 1b. This overrules nothing in the earlier document, which also deferred
   them, but the user has asked for translated slugs, so 1b is committed work rather than a maybe.
4. **SEO meta is in Phase 1**, through a dedicated adapter. The earlier document placed custom SEO
   field translation out of scope; with a single shared post, a German product page would otherwise
   carry an Italian `<title>` and meta description.
5. **Shared mode is opt-in and off by default.**
6. **Product category, tag and brand archives get the language-switch fix in Phase 1.** Making those
   taxonomies shared takes them out of `is_translated_taxonomy()`, which disables the `is_tax()`
   branch of `Linguator_Frontend_Links::get_translation_url()`. Without a handler the switcher falls
   back to the home page on all 14 active categories. Reviewed and moved into scope on 2026-09-15.
7. **Concurrent-write protection is best-effort in Phase 1.** `revision` catches an editor working
   from stale data; it is not atomic against two simultaneous writes. Accepted deliberately — see
   *Concurrency, as actually guaranteed*. Phase 2 hardens it, because bulk translation is what makes
   the race real.

## Scope of Phase 1

Translated per language:

- Product: name, short description, description
- Variation: description
- Product-local attributes: attribute label and option labels
- `product_cat`, `product_tag`, `product_brand`: term name and description
- SEO: document title, meta description, Open Graph title/description — entered through the same
  minimal panel, not through a separate screen

Never translated, ever: product and variation IDs, SKU, price, stock, tax class, dimensions, weight,
attribute *keys*, attribute *option values*, term IDs, term slugs, order IDs.

## Architecture

The work extends `integrations/woocommerce/`, the integration created for the shop language switcher
fix, rather than opening a second WooCommerce integration. A single shared-mode setting gates every
behaviour in this document; with it off, the integration does only what it does today.

The invariant that holds the design together:

> **WooCommerce owns identity and commercial data. Linguator owns text.**

No new IDs, no new tables, no mutation of canonical objects. Every read path goes through
WooCommerce's own view getters or narrowly scoped display filters, so a caller that asks for
canonical data still gets canonical data.

## Components

| File | Responsibility |
| --- | --- |
| `integrations/woocommerce/woocommerce.php` | Coordinator: reads the setting, registers adapters, and extends the existing `lmat_pre_translation_url` handler to single products and product term archives. Extends the existing class. |
| `integrations/woocommerce/shared-mode.php` | Reads the setting and owns the activation guard |
| `integrations/woocommerce/translated-object-types.php` | Removes products and product taxonomies from Linguator's ordinary translation model while shared mode is on |
| `integrations/woocommerce/translation-repository.php` | Read and write per-object per-language text; bulk priming; source fingerprints; status; revisions |
| `integrations/woocommerce/term-translations.php` | The same for term labels and descriptions |
| `integrations/woocommerce/attribute-translations.php` | Product-local attribute labels and option labels, canonical values untouched |
| `integrations/woocommerce/context-gate.php` | `should_localize()`: the one decision every display filter consults |
| `integrations/woocommerce/frontend.php` | Localized WooCommerce getters and the language context |
| `integrations/woocommerce/variations.php` | The variation payload served to the form and to AJAX |
| `integrations/woocommerce/cart.php` | Meta cache priming for cart and catalogue loops |
| `integrations/woocommerce/orders.php` | Purchase language and displayed text, frozen at order creation |
| `integrations/woocommerce/seo.php` | Serves translated title, meta description and Open Graph to the active SEO plugin |
| `integrations/woocommerce/admin/product-panel.php` + view | Minimal entry metabox |
| `admin/settings/views/src/components/`, `modules/rest/v1/settings.php` | The shared-mode toggle and its activation guard |

## Data model

One meta row per object per language. Post meta for products and variations, term meta for terms.

Key: `_lmat_wc_i18n_<language slug>` — for example `_lmat_wc_i18n_de`.

Value:

```php
array(
    'revision' => 7,                       // incremented on every write; used to reject stale writes
    'updated'  => '2026-09-15T09:12:44+00:00',
    'fields'   => array(
        'name' => array(
            'value'       => 'Mitgliedsbeitrag',
            'source_hash' => '9f2c1b7e04a3',  // sha1 of the source field at translation time
            'status'      => 'translated',    // or 'needs_review'
        ),
        'short_description' => array(
            'value'       => '',              // intentionally empty, not missing
            'source_hash' => '3ad9f0c25e11',
            'status'      => 'translated',
        ),
    ),
)
```

Three states are distinguishable, which the earlier document required and which a single flat string
map cannot express:

- **Never translated** — the field key is absent. Display falls back to source.
- **Intentionally empty** — the key is present with `'value' => ''`. Display shows nothing.
- **Stale** — `source_hash` no longer matches the current source. Display still shows the translation;
  the admin sees `needs_review`.

**Concurrency, as actually guaranteed.** `revision` guards a write carrying a revision older than
the stored one: it is rejected rather than merged. That covers the case Phase 1 actually has — an
editor who loaded the panel, went away, and saved over someone else's later work.

It is **not** atomic. `update_post_meta()` offers no compare-and-swap, so two writes that read
revision 7 in the same instant both write revision 8 and the second wins silently. This limitation is
accepted deliberately: Phase 1 ships a single-editor metabox, where the race needs two humans saving
the same product in the same language within the same request window. Phase 2 introduces bulk and AI
translation, which is what makes the race real, and is where a lock belongs. Recorded here so that
nobody reads `revision` as a stronger promise than it is.

**Bulk priming.** WordPress already loads all post meta for the posts in a `WP_Query` result in one
query via the meta cache. The repository therefore reads through `get_post_meta()` / `get_term_meta()`
and calls `update_postmeta_cache()` / `update_termmeta_cache()` for any ID set it is handed outside a
loop. No per-field and no per-language query in catalogue loops, and no new caching layer.

**Privacy.** The leading underscore makes the key protected, which is not by itself an access policy.
The keys are additionally filtered out of WooCommerce REST `meta_data` through
`woocommerce_rest_prepare_product_object` and `woocommerce_rest_prepare_product_variation_object`.
Phase 3 exposes translations deliberately, through its own field, never by leaking storage.

## Identity and query behaviour

While shared mode is on:

- `lmat_get_post_types` removes `product` and `product_variation`; `lmat_get_taxonomies` removes
  `product_cat`, `product_tag` and `product_brand`. Both lists are cached after `after_setup_theme`,
  so the filters are registered early, at `plugins_loaded`.
- The `lmat_language` terms already attached to existing products are **left in place and untouched**.
  Turning shared mode off restores today's behaviour with no recovery step. This is the reversibility
  guarantee, and it is cheap precisely because nothing is deleted.
- Products therefore stop being filtered by language: one catalogue, every language, same IDs.
- Product links keep their canonical permalink and receive the current language prefix through
  Linguator's existing links model.
- The `lmat_pre_translation_url` handler added by the shop switcher fix is extended to **single
  product pages and to `product_cat` / `product_tag` / `product_brand` archives**, so switching
  language stays on the same object instead of falling back to the home page. This is the same defect
  already fixed for the shop archive, on the same surface.

  Both extensions are structurally necessary, not cosmetic. `get_translation_url()` resolves a single
  post through `$this->model->post->get( $id, $language )`, which needs a **translation post** that a
  shared product by definition does not have; and it resolves a term archive through its `is_tax()`
  branch, which is guarded by `is_translated_taxonomy()` and therefore switches off the moment
  `product_cat` becomes shared. Neither falls through to a usable fallback: a term archive lands in
  the generic archive branch, where `count_posts()` counts posts *by language* and returns zero for
  products that no longer carry one, so `lmat_hide_archive_translation_url` suppresses the URL.

- **hreflang follows from the same handler.** The links filter builds its `<link rel="alternate">`
  set from `get_translation_url()` once per language and prints nothing unless more than one URL
  resolves. Without the handler above, a shared product page emits **no hreflang at all** while being
  reachable under four language prefixes. With it, the alternates appear as a by-product and no
  separate hreflang code is written. A test asserts the four alternates on a product page. This
  supersedes the claim under *SEO adapter* that hreflang needs no attention here.

## Rendering

Localized text is served through WooCommerce's view getters, which is what templates, blocks and the
Store API all end up calling:

- `woocommerce_product_get_name`, `woocommerce_product_get_description`,
  `woocommerce_product_get_short_description`
- `woocommerce_product_variation_get_description`

Where templates read post fields directly rather than the product object, narrowly scoped
`the_title` and `get_the_excerpt` filters cover the gap, guarded on product post types and on the
main display context so admin and canonical reads are unaffected.

Requests in the default language take a short path: the repository is not consulted at all.

### The localization context gate

Every filter above — the getters included, not only `the_title` — passes through **one**
`should_localize()` decision. This is the highest-risk surface in the design, because
`woocommerce_product_get_name` fires in contexts that have nothing to do with rendering a storefront
page: the admin product list, REST, cron, WP-CLI, order creation, transactional email, CRM export,
feeds and `WC_Structured_Data`.

The gate returns false for, at minimum:

| Context | Why |
| --- | --- |
| `is_admin()` without a frontend AJAX request | The admin must see canonical text, or an editor renames a product by accident |
| `REST_REQUEST` | Canonical until Phase 3 exposes translations deliberately |
| `DOING_CRON`, `WP_CLI` | No viewer, therefore no display language |
| Order creation and order item generation | The snapshot is written from the **purchase** language, resolved explicitly by `orders.php`, never from ambient context |
| Transactional email rendering | Emails read the frozen snapshot, not live translations |

The failure this prevents is concrete: if the getter localized during order creation, the text frozen
into the order would be the language of whoever or whatever triggered the save — an admin, a cron
job, a webhook — rather than the language the customer bought in. That would corrupt order history
silently and permanently, which is the one failure mode this design exists to avoid.

Each row of that table gets its own unit test. The gate lives in one method so there is exactly one
place to audit.

## Attributes and variations

Product-local attributes carry a canonical name and canonical option values. The integration stores a
label map beside them and localizes only what is rendered.

The rule that protects the cart: **translated labels never take part in variation matching.**
WooCommerce continues to match on canonical option values in the variation form, in
`woocommerce_available_variation`, in the AJAX `get_variation` response and in `add_to_cart`. The
localized label is presentation only.

The display side is served by two hooks, which are part of the deliverable and not an afterthought:

- `woocommerce_attribute_label` — the attribute name (`Größe` instead of `Taglia`)
- `woocommerce_variation_option_name` — the option label inside `wc_get_formatted_variation()`

These matter more than they look. A variation has no name of its own: what the customer reads in the
cart, at checkout and in the order email is the parent product name plus the formatted attribute
pairs. Translating only the variation *description*, as the scope list does, would leave a German
cart showing Italian attribute labels. The attribute label map is therefore what makes the variation
line read correctly, and its coverage is asserted in the cart and checkout tests, not only on the
product page.

A test asserts that a translated label never appears in any value attribute, and that a variation
selected in German resolves to the same variation ID as in Italian.

## Orders

At order creation the integration records, on the order and its items:

- the language the purchase was made in
- the product and variation text as the customer saw it

Historical orders then never change when the viewer's language changes, and refunds, emails, exports
and CRM reads keep referring to the same canonical IDs. Order data is written once, at creation, and
never rewritten by a later translation edit.

## SEO adapter

With one shared post there is one set of SEO meta. The repository stores document title, meta
description and Open Graph title/description per language, and the adapter serves them to the active
SEO plugin through that plugin's own output filters, falling back to the source values when a
translation is absent.

The adapter is written against one SEO plugin at a time and detects which is active; SEOPress is the
one in use on the target site and is the one implemented in Phase 1. Yoast and Rank Math already have
Linguator integrations and are left alone in this phase.

Canonical URLs are not touched here: they are produced by the language and links layer.

`hreflang` **is** affected, contrary to an earlier reading of this design. The links layer works for
the shop archive only because the shop page has a real translated page behind it; a shared product
has none, and the alternates collapse to a single self link, which the filter declines to print. The
fix is not in this adapter — it is the `lmat_pre_translation_url` extension described under *Identity
and query behaviour*, which restores the alternates as a by-product.

## Minimal entry panel

A metabox on the product edit screen: a language selector, the source text shown read-only beside
each field, an input per translatable field, and the per-field status.

The field set is the whole of the Phase 1 scope, **including the four SEO fields** — document title,
meta description, Open Graph title and Open Graph description — and the attribute label map. Anything
Phase 1 renders must be enterable in Phase 1; a translated field with no input is a field that stays
empty forever.

Save writes through the repository with the current revision. No AI, no bulk, no rich editing —
those are Phase 2, and the approved Superdesign draft is that phase's target, not this one.

The panel exists so Phase 1 is usable by a human, not to be the final editing experience.

## Settings and the activation guard

One boolean, off by default, in the existing Linguator settings, using the existing persistence and
authorization flow. No second settings store.

**Activation guard.** Before shared mode can be enabled, the integration scans for products that
already have Linguator translations. If any exist, enabling is refused and the offending products are
listed. No automatic merge, deletion or re-keying. The target site has none, but an upstream user may,
and silently merging two catalogues would be unrecoverable.

## Error handling

| Situation | Behaviour |
| --- | --- |
| Translation missing | Source text is displayed, marked internally as a fallback. A page is never empty. |
| Field intentionally empty | Respected: nothing is displayed. Distinct from missing. |
| Source text changed | Translation is marked `needs_review` and **keeps being displayed**. Flagging is not hiding: slightly dated German beats a sudden reversion to Italian on a product page. |
| Concurrent write, stale revision | Rejected with the current revision reported. Never merged. |
| Concurrent write, same instant | Last write wins. Known Phase 1 limitation, documented above, closed in Phase 2. |
| Private or draft product | Visibility is unchanged. A translation never widens access. |
| WooCommerce inactive | The integration is inert; no fatal error, no data touched. |
| Shared mode turned off | Translations stay in meta; display returns to canonical; output identical to today. |
| Existing duplicated catalogue | Activation refused, with the conflicting products named. |
| Caching | No new cache layer. Page cache already varies by language because directory URLs make each language a distinct URL; the repository rides WordPress's meta cache, which WordPress itself invalidates on save. |

## Testing

**Unit**, on the PHPUnit harness built for the shop switcher fix (state-driven WordPress and
WooCommerce stubs, no WordPress install): repository read/write, the three field states, fallback,
`needs_review` transitions on source change, stale-revision rejection, the attribute label map, the
order snapshot payload, the REST meta filtering, and the assertion that canonical values are never
returned translated.

Added by the 2026-09-15 review, each as a named test rather than an implied one:

- **One test per row of the context gate table** — admin, REST, cron, WP-CLI, order creation, email —
  each asserting canonical text out.
- **Translation URL resolution** for a single shared product and for a `product_cat` archive, both
  returning the prefixed URL of the same object rather than the home page.
- **hreflang** on a shared product page: four alternates present, one per language.
- **Cart and checkout line text**: the attribute label and option label are German, while the value
  carried in the cart item is canonical.

**Integration, on the clone**: the proof that matters is a **complete purchase of a variable product
in German**, comparing before and after the product and variation counts, IDs, SKUs, prices, stock and
order references. Then the same purchase in Italian, resolving to the same variation IDs.

The order produced by the German purchase is inspected directly: the frozen item text must be German
and must have been written from the purchase language, not from whatever context saved the order.
That single assertion is what the context gate exists for.

**Regression**: the seven-page capture suite built on 2026-09-14, extended with product and category
pages, run with shared mode off to prove output is unchanged from today, and with it on to prove
nothing but text differs. The extended set must include a single product page and a `product_cat`
archive in both Italian and German, because those two are where the switcher and hreflang changes
land.

## Out of scope for Phase 1

Translated permalink base and slugs (1b). Full panel, AI and bulk translation (2). `lmat_translations`
on native WooCommerce routes and its setting (3). Localized search indexing. Migration of an existing
duplicated catalogue — detected and refused, never converted. Yoast and Rank Math SEO field
translation. Product reviews. Third-party product extensions beyond those active on the target site.

## Known risks

- **Display filter reach.** Templates, blocks, Elementor widgets and the Store API do not all read
  product text the same way. The getters cover most of it; the gaps are found by rendering, not by
  reading source. The regression capture suite is the instrument for that.
- **woo-multi-currency and woocommerce-members-only** are active on the target site and both wrap
  product data. They must be exercised in the integration tests rather than assumed compatible.
- **`page_for_posts` is set to the shop page** on the target site, which is an unusual configuration.
  It does not affect this design, but it is recorded here because it surprises anyone reading the
  query behaviour.
