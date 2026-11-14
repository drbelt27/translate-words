# Shared WooCommerce product identity — Phase 1b design: translated slugs and permalink bases

Status: draft for approval. Builds on Phase 1 (`2026-09-15-woocommerce-shared-product-identity-phase-1-design.md`, on `main`).

## Intent

Phase 1 serves one shared product (one ID) with per-language text, but every URL still carries the
canonical Italian slug and base: `/de/prodotto/open-day-2026/`. Phase 1b lets each non-default
language have its own slug and its own permalink base, without duplicating any product, term or
variation: `/de/produkt/open-day-2026-de/`.

The user asked for this explicitly ("translated slugs", Phase 1 decision 3). The reason, as stated:
a webmaster curates URLs per market, and the site must not break while that work is in progress.

## Goals

1. Translated **slug** per language for: `product`, `product_cat`, `product_tag`, `product_brand`, and
   global attribute option terms (`pa_*`).
2. Translated **permalink base** per language: product base, category base, tag base, attribute base
   (WooCommerce Settings → Permalinks), brand base if WooCommerce exposes one.
3. One resolution rule for every language: see "Rules".
4. Slugs are entered by a webmaster, or filled automatically from the translated name when one exists.
5. Everything is gated by shared mode. Shared mode off restores today's behaviour and stored slugs stay on disk.

## Non-goals

- Variations: no public URL, nothing to translate.
- Translated attribute *keys* or option values in matching logic: canonical only, as in Phase 1. Slugs
  only change how an option appears in a URL, never how a variation is matched.
- A dedicated AI call for slugs. Phase 2 translates names with AI; slugs follow from the name (see "Automation").
- Pages and posts: Linguator already handles them.
- Search indexing, Yoast and Rank Math.

## Anti-goals (a failure even if it technically works)

- A product, category or tag disappearing from a language because no slug was entered yet.
- Changing a published URL silently, so inbound links 404. Every URL that was valid and is replaced
  (superseded translated slug, canonical slug under a prefix, canonical base) 301s to its successor.
- Any slug work changing term IDs, product IDs or variation matching.

## Rules (decided with the user)

1. If an object has a translated slug in language L, `/L/<base>/<canonical-slug>/` returns a single
   **301** to the translated URL. (Changed from 404 by the user on 2026-11-13, to keep the ranking of
   URLs a crawler indexed while the object was untranslated.)
2. If an object has **no** translated slug in L, the canonical slug keeps working under `/L/`. This is
   the approved exception: without it the German catalogue would vanish the moment 1b is enabled.
3. The same two rules apply to the permalink base: once a language has a translated base, the canonical
   base 301s to the translated base under that prefix; until then it keeps working.
4. The default language always uses the canonical slug and base. Translated slugs exist only for non-default languages.
5. One object, one ID. Slugs are alternate URL names for the same object.

## Constraints

- Phase 1 storage is post/term meta read through `Translation_Repository`, as a serialized record per
  language. A slug must be found **by value** (`/de/produkt/<slug>/` → object), which a serialized
  blob cannot do. So slugs get their own plain meta key. This is a deliberate exception to the
  "everything in the repository record" shape, not a second source of truth for text.
- `product_cat`, `product_tag`, `product_brand` are shared taxonomies (removed from Linguator's
  translated list). Their terms have no entry screen today. 1b adds a minimal one for slugs only.
- The language prefix is added by Linguator's links model. The Phase 1 fix `e27b4eb` cancels
  `redirect_canonical()` for shared objects under a prefix. 1b changes that guard: it must still allow
  the translated URL, and it now also produces the 301 for superseded slugs (below).
- The unit suite runs with stubs, WordPress is not loaded. New logic needs tests in that harness, and
  the real behaviour is proved on the clone site `dev-linguatorbeltracing.local`.

## Approaches considered

| | How | Trade-off |
| --- | --- | --- |
| **A (chosen)** | Plain meta per language and object, translated slug mapped to the canonical slug before WordPress resolves the URL. | One storage model, no schema change. Uniqueness is enforced in code at save, the same guarantee WordPress gives `post_name`. |
| B | Dedicated lookup table with a unique index. | Database-level uniqueness and faster lookup. Needs a schema migration and a second home for the data, and Phase 1 already rejected custom tables as disproportionate. |

Chosen by the user: A.

## Design

### Storage (my call)

- Object slug: post meta / term meta `_lmat_wc_slug_<lang>` (plain string).
- Superseded slugs: `_lmat_wc_slug_old_<lang>` (array), kept so old URLs can 301.
- Bases: one option `lmat_wc_permalink_bases`, `{ lang: { product, category, tag, attribute } }`.
- Language codes and key names follow the existing `_lmat_wc_i18n_<lang>` convention.

### Uniqueness and conflicts

Per language and object type, a slug must not equal another object's translated slug, nor the
canonical slug of another object that has **no** translated slug in that language (it would be
ambiguous under rule 2). Manual entry that conflicts is rejected with a visible error. Automatic
derivation appends a numeric suffix (`-2`), like `wp_unique_post_slug()`. Slugs go through `sanitize_title()`.

### Resolution (one resolver)

A single `Slug_Resolver` owns three things, so no caller reimplements them:
`permalink( object, lang )`, `resolve( slug, type, lang )`, and `is_superseded( slug, object, lang )`.

- **Inbound:** early in request parsing, a translated slug (or base) under a non-default prefix is
  mapped to the canonical slug/query var, and WordPress resolves the object normally. Rule 1 and 3
  404s are produced here.
- **Outbound:** `post_type_link` and `term_link` return the translated URL for the current language.
  The switcher (`product_translation_url`, `term_archive_translation_url`), hreflang, canonical link,
  SEOPress canonical and the sitemaps module must all call `permalink( object, lang )` for the *target*
  language, not the current one. Verify by rendering, not by reading source.
- **Rewrite rules:** one rule set per non-default language with a translated base. Flushed when bases
  change, never on every request.
- **Redirects:** a superseded translated slug or base 301s to the current one, so changing one never
  breaks inbound links. Old bases are kept in the option under `old`, per language and base type. The
  canonical slug or base under a prefix is also 301'd to the translated one (rules 1 and 3). Only a slug
  that never belonged to any object returns a real 404.
- **Hierarchical terms** (`product_cat` paths like `/kategorie/parent/child/`): each path segment is
  resolved independently. A translated parent with an untranslated child gives
  `/kategorie/<de-parent>/<canonical-child>/`. Uniqueness is checked per language, type and parent.
- **Base types are independent:** rule 3 applies per base type (product, category, tag, attribute), not per language.
- **Attribute terms:** the translated slug appears in archive URLs (where WooCommerce makes the
  attribute public). Layered-nav (filter) links stay canonical in 1b: WooCommerce's layered-nav
  widget builds its links from the raw `$_GET`, so emitting a translated value would make an active
  filter impossible to remove by clicking, and filter URLs are not canonical targets, so the SEO
  effect is small (decision recorded 2026-11-13; revisit with a dedicated change). In `filter_*` / `pa_*` query strings both the translated
  and the canonical value are accepted (no 404 rule for query strings), mapped to canonical at the
  point WooCommerce reads the query var. `$_GET` is never rewritten globally.

### Entry

- Product: a slug field per language in the existing product panel (`admin/product-panel.php`).
- Terms: a minimal translated-slug field per language on the product category, tag and brand edit screens (new in 1b).
- Bases: a per-language section next to WooCommerce's permalink settings, or in the Linguator settings
  screen. The builder picks the one that matches how the settings screen already works.

### Automation (user rule: "slugs can be translated automatically if AI is active")

- When a translated name exists and the slug is empty, the slug is derived from it with `sanitize_title()`.
- A slug already present is never overwritten by a later name change; URLs do not move unless the webmaster moves them.
- Phase 2's AI fills names, so AI-active sites get automatic slugs with no AI code in 1b. A direct AI
  call for slugs is out of scope; if wanted it widens the phase.
- The webmaster can always edit a derived slug.

## My calls to check

- Dedicated plain meta keys instead of the repository record.
- Old slugs and old bases kept and 301'd.
- Terms get a slug-only screen; their names stay Phase 2.
- Rule 3 mirrors rule 1 for bases, per base type.
- Hierarchical terms resolve segment by segment (see Design).
- Attribute filter query strings accept both translated and canonical values.
- Uniqueness is checked within an object type only. Saving a base that collides with an existing
  page path shows a warning but does not block.
- `product_brand` is handled only when `taxonomy_exists( 'product_brand' )`; otherwise it is skipped.
  Phase 1 already lists it in the shared taxonomies, so no new floor is introduced.
- If the default language changes, stored translated slugs stay on disk untouched. They are ignored
  while that language is the default and apply again if it is not. No migration.

## Left to the builder

- Exact hooks for early resolution, per WordPress and WooCommerce version, and the concrete rewrite rules.
- Whether the base settings live in WooCommerce's permalink page or Linguator's settings.
- Brand base, if WooCommerce does not expose one.
- Test layout inside the existing `tests/woocommerce/` harness.

## Search engines, AI crawlers and sustainability (user requirement)

The user asked that this holds up for classic SEO and for AI crawlers. These are acceptance criteria,
not extras: a translated URL that search engines cannot read correctly defeats the purpose of 1b.

**Every URL a crawler can read comes from `permalink( object, lang )`:**
- `<link rel="canonical">`: self-referencing, on the translated URL of that language.
- `hreflang` alternates and `x-default`: translated URLs, reciprocal between every pair of languages.
- XML sitemaps: one entry per language with its translated URL. Never both the canonical and the translated one.
- Structured data (WooCommerce JSON-LD `url`, breadcrumbs) and Open Graph `og:url` / SEOPress canonical.
- Internal links: related products, cart, widgets, menus, search results, term archives.

**Crawler behaviour:**
- A slug that never belonged to any object returns a real HTTP 404 status, never a soft 404 and never a redirect to the home page.
- A superseded translated URL returns a single 301 to the current one, never a chain.
- Language comes from the URL prefix only. Cookie and `Accept-Language` detection must not redirect a
  request that already carries a language prefix (it must never reroute a crawler that sends no cookie).
- All translated text and structured data are in the server-rendered HTML, with the right `lang`
  attribute. AI crawlers and many search bots do not run JavaScript, so nothing may depend on it.
- Product text is already served per language by the Phase 1 getters; 1b only changes the URL around it.

**SEO decision (user, 2026-11-13):** under rule 2, a crawler can index the canonical (Italian) slug
under `/de/` while a product is untranslated. When the webmaster later adds the German slug, rule 1
301s that indexed URL to the translated one, so its ranking moves with it. This replaces the earlier
"404" wording. The choice stays isolated in one resolver constant (`CANONICAL_UNDER_PREFIX`, now `'redirect'`).

**Sustainability:**
- Slug → object lookup is cached per language and request, and meta for archive pages is primed in one query
  (Phase 1 already primes catalogue loops). No per-product query on category or shop pages.
- Rewrite rules are flushed only when a base changes, never per request.
- Shared mode off, or the plugin deactivated, leaves all stored slugs in place and changes nothing visible.
- Slugs are sanitized and bounded in length; a manual conflicting entry is rejected, not silently altered.

## Verification

- Unit tests: resolver rules 1–4, conflicts, derivation, superseded-slug redirect, shared mode off.
- Clone site: German product, category, tag and attribute filter URLs; switcher and hreflang land on the
  translated URL; canonical slug 404s once a translated one exists; an untranslated product still opens.
- Sitemap and SEOPress canonical show translated URLs.
- Canonical slug under a prefix, once a translated slug exists: a single 301 (never a chain) to the translated URL.
- Crawler check with `curl` using a Googlebot and a GPTBot user agent, no cookie: correct status codes,
  no language redirect on prefixed URLs, one canonical, reciprocal hreflang, JSON-LD `url` translated,
  translated text present in the raw HTML.
