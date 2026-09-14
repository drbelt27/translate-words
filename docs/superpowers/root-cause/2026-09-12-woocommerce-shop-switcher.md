# Root cause: language switcher links the WooCommerce Shop page to the target-language home page

Investigated on 2026-09-12 against the working tree at commit `8dadb33` (Linguator 2.2.0).
Method: `superpowers:systematic-debugging`, Phase 1 (source-level trace). No runtime reproduction
was performed in this session; every claim below is anchored to a file and line in this repository.

## Reported symptom

On a site with an Italian shop at `/shop/` and its German translation at `/de/shop-2/`, selecting
`DE` in the Linguator Elementor language switcher navigates to the German home page instead of the
German shop page. The Linguator IT <-> DE translation relationship for the two pages exists.

## How WordPress resolves the Shop page

WooCommerce registers the `product` post type with `has_archive` set to the URI of the page stored in
the `woocommerce_shop_page_id` option. The configured shop page is therefore served as the **product
post type archive**, not as an ordinary page. On that request:

- `is_page()` is `false`
- `is_post_type_archive( 'product' )` is `true`
- `get_queried_object()` returns a `WP_Post_Type`, so `get_queried_object_id()` returns `0`

The translated shop page (`/de/shop-2/` in the report) is an ordinary WordPress page: WooCommerce
knows only one shop page, so `is_shop()` is `false` there and `is_page()` is `true`.

## Failing code path

`Linguator_Frontend_Links::get_translation_url()` at
[frontend-links.php:54](../../../frontend/services/frontend-links.php#L54) is the single entry point
used by every switcher. The trace for a request to `/shop/`:

1. [frontend-links.php:80](../../../frontend/services/frontend-links.php#L80) applies
   `lmat_pre_translation_url`. The only core handler,
   `Linguator_Frontend_Static_Pages::linguator_pre_translation_url()`
   ([frontend-static-pages.php:118](../../../frontend/controllers/frontend-static-pages.php#L118)),
   returns immediately because `$queried_object_id` is `0`. No handler knows about WooCommerce:
   a repository-wide search finds no WooCommerce integration, only two unrelated comments in the
   Yoast and Rank Math sitemap code.
2. The `is_page()` branch at
   [frontend-links.php:87](../../../frontend/services/frontend-links.php#L87) is never reached, so
   **the Linguator page-translation relationship of the shop page is never consulted**. This is the
   defect.
3. Execution falls into the post type archive branch at
   [frontend-links.php:151](../../../frontend/services/frontend-links.php#L151), which has three
   possible outcomes, all wrong for this case:
   - `product` is not in the translated post types list
     ([translated-post.php:166](../../../includes/models/translated/translated-post.php#L166)):
     `$url` stays empty.
   - `product` is translated but `count_posts()` returns `0` for the target language: the default of
     `lmat_hide_archive_translation_url` is `true`, so `$url` stays empty.
   - `product` is translated and the target language has products:
     `linguator_get_archive_url()`
     ([frontend-links.php:214](../../../frontend/services/frontend-links.php#L214)) **synthesises**
     the URL by swapping the language segment of the current request
     (`switch_language_in_link()`), producing `/de/shop/`. That ignores the real slug of the
     translated page and points at a URL that does not exist.
4. `get_translation_url()` returns an empty string.
5. `Linguator_Switcher::get_elements()` at
   [switcher.php:187](../../../includes/controllers/switcher.php#L187) applies its documented
   fallback: `$url = empty( $url ) || $args['force_home'] ? $this->links->get_home_url( $language ) : $url;`
   The switcher item becomes the target-language home page and is flagged `no-translation`.

```
/shop/  -> is_post_type_archive('product'), queried_object_id = 0
        -> lmat_pre_translation_url: no handler matches
        -> is_page() branch skipped, page translation never read
        -> post type archive branch yields '' (or a synthesised /de/shop/)
        -> switcher.php:187 falls back to get_home_url( $language )
        -> German home page
```

## Why Elementor is not involved

`integrations/elementor/lmat-widget.php:651` calls `linguator_the_languages( array( 'raw' => 1 ) )`,
which runs `Linguator_Switcher::get_elements()`. The widget consumes the same API and therefore
inherits the defect verbatim. No Elementor-specific code is implicated and none is needed for the fix.

## Why the reverse direction already works

From `/de/shop-2/` the request is an ordinary page, so the `is_page()` branch at
[frontend-links.php:87](../../../frontend/services/frontend-links.php#L87) resolves the Italian
translation and returns `get_page_link()`. This matches the report that only IT -> DE was broken, and
it means the fix only has to handle the archive side of the pair.

## Scope of the defect beyond the switcher

`Linguator_Frontend_Filters_Links::wp_head()` at
[frontend-filters-links.php:235](../../../frontend/filters/frontend-filters-links.php#L235) builds the
`rel="alternate" hreflang` block from the **same** `get_translation_url()` call. The shop page
therefore also emits wrong or missing `hreflang` alternates today. Correcting
`lmat_pre_translation_url` fixes both consumers at once, which keeps the fix inside one
architectural responsibility.

Canonical URLs are produced elsewhere (`frontend/services/canonical.php`, plus the active SEO
plugin) and are **not** touched by this code path. They must be inspected separately on the live
markup rather than assumed correct or assumed broken.

## Confirmed hypothesis

> The language switcher has no code path that maps a post type archive backed by a real WordPress
> page to that page's Linguator translation. The WooCommerce shop page is exactly that shape, so the
> translation relationship is never read and the switcher falls back to the home page.

The active site workaround (Code Snippets snippet 17) confirms the hypothesis empirically: hooking
`lmat_pre_translation_url` and returning `get_permalink( linguator_get_post( wc_get_page_id( 'shop' ), $lang ) )`
removes the symptom. The fix below moves that behaviour into the plugin as a proper integration.

## Runtime verification

Performed on 2026-09-14 against a full production clone running in Local
(`D:\Local_Sites\dev-linguatorbeltracing`, WordPress 7.1, WooCommerce 11.1.0, Elementor 4.2.4,
SEOPress, Linguator 2.2.0). Every line below is an observation, captured with `curl` from the
rendered HTML.

### Environment

- Languages: `it` (default), `de`, `fr`, `en`.
- `woocommerce_shop_page_id = 24619`, language `it`, permalink `/shop/`.
- German translation: post `27384`, `/de/motorrad-shop/`. French and English have **no** shop
  translation, so a single request exercises both the "translation exists" and the "no translation"
  paths.
- The translated slug is `motorrad-shop`, not the `shop-2` from the original report. The integration
  resolves it correctly, which confirms it does not depend on the slug.
- Note: `page_for_posts` is also `24619`. WooCommerce's product archive routing still wins on
  `/shop/` — `get_queried_object_id()` is `0` and `is_posts_page` is false there — which is why the
  core static-pages handler does not resolve this case.
- Yoast is installed but **not active**; SEOPress is the active SEO plugin.

### Step 0b — the clone reproduces the defect

Snippet 17 deactivated, Linguator 2.2.0 unmodified, request to `/shop/`:

```
switcher:  de -> /de/startseite/     (the German home page)
           fr -> /fr/accueil/
           en -> /en/welcome/
hreflang:  no hreflang block emitted at all
```

The reported defect is reproduced exactly, and the missing `hreflang` block confirms that
`get_translation_url()` returns nothing for every language on this request.

### The fix, snippet 17 still deactivated

```
switcher:  de -> /de/motorrad-shop/
           fr -> /fr/accueil/        (unchanged: no translation)
           en -> /en/welcome/        (unchanged: no translation)
hreflang:  it -> /shop/
           de -> /de/motorrad-shop/
canonical: /shop/                    (unchanged)
```

Compared against the same page served with snippet 17 active, the two documents differ only in
per-request volatile values (Elementor's CSS cache-busting version, the WooCommerce cart token, and
generated `quantity_*` input ids). The native integration is a drop-in replacement for the snippet.

### No regressions

The same seven pages were captured twice — once with the integration installed, once with the
plugin reverted to its original files — and the extracted switcher, `hreflang`, `canonical` and
`robots` values were compared. The **only** differences across all seven pages are the three lines
that constitute the fix:

| Page | With the fix | Original |
| --- | --- | --- |
| `/shop/` switcher, `de` | `/de/motorrad-shop/` | `/de/startseite/` |
| `/shop/` hreflang | `it` and `de` emitted | none emitted |
| `/shop/page/2/` switcher, `de` | `/de/motorrad-shop/` | `/de/startseite/` |

Identical in both states: `/de/motorrad-shop/` (the reverse direction, resolved by the core
`is_page()` branch as predicted), `/chi-siamo/`, `/de/uber-uns/`, the front page, and
`/categoria-prodotto/senza-categoria/`.

### Other checks

- **Elementor switcher**: the widget calls `linguator_the_languages( array( 'raw' => 1 ) )`, and all
  captures above are of that widget's rendered markup. No Elementor-specific code was needed.
- **Both directions**: `/shop/` offers `de -> /de/motorrad-shop/`; `/de/motorrad-shop/` offers
  `it -> /shop/`. `hreflang` is symmetric on both.
- **Coexistence with snippet 17**: with the snippet reactivated alongside the integration the output
  is unchanged. The callback returns `$url` untouched when a previous handler has already resolved
  it, so there is no double handling. This validates deploying to production with the snippet still
  active.
- **Unreadable translation**: with post `27384` set to `draft`, the switcher falls back to the German
  home page and no `hreflang` is emitted. The snippet has no such check and would have published a
  link to a draft.
- **Redirects**: `/shop/`, `/de/motorrad-shop/` and `/shop/page/2/` each return `200` with zero
  redirects. `/de/` makes a single expected hop to `/de/startseite/`. No loops.
- **Paged archive**: `/shop/page/2/` offers `de -> /de/motorrad-shop/`, that is page 1 of the
  translated shop. This is the documented, deliberate limitation. Its `canonical` correctly stays on
  `/shop/page/2/`, and Linguator emits no `hreflang` on paged views by its own pre-existing rule.

### Pre-existing findings, outside this task's scope

- **The XML sitemap contains no translated pages.** SEOPress's `page-sitemap1.xml` lists 9 URLs, of
  which the only shop entry is `/shop/`; no `/de/`, `/fr/` or `/en/` page appears anywhere in it.
  This cannot be attributed to a filter that only fires on the shop archive, and it is unchanged by
  this work. Linguator ships no SEOPress frontend integration, only SEO field translation in the
  editor (`modules/page-translation/src/component/translate-seo-fields/seo-press.js`).
- **Product category archives** still offer the target-language home page
  (`/categoria-prodotto/senza-categoria/` gives `de -> /de/startseite/`). Identical before and after;
  translated taxonomy terms are separate scope.
- **`robots` meta** could not be assessed on this clone: `blog_public` was set to `0` as a safety
  measure during setup. What can be stated is that the value is identical with and without the fix.
