# Handoff — resume here

Written 2026-09-15 before a machine restart; updated 2026-09-15 after the spec review and the plan.
Read this first, then the documents it points to.

## Where the work stands

Two tracks, both about the same WooCommerce integration.

| Track | State |
| --- | --- |
| **WooCommerce shop language switcher** | Done and verified. Pull request [#20](https://github.com/CoolPluginsTeam/translate-words/pull/20) is open upstream. Not merged. Not deployed to production. |
| **Issue #19, shared product identity, Phase 1** | Design agreed, reviewed with the user and amended. Implementation plan written and committed. **No plugin code written yet.** Executing the plan is the next thing to do. |

## Read these, in order

1. `docs/superpowers/plans/2026-09-15-woocommerce-shared-product-identity-phase-1.md` — the implementation
   plan. Seventeen tasks, test-first, each with the code to write. Start here.
2. `docs/superpowers/specs/2026-09-15-woocommerce-shared-product-identity-phase-1-design.md` — the Phase 1
   design, reviewed with the user on 2026-09-15 and amended. The plan argues from it.
3. `docs/superpowers/root-cause/2026-09-12-woocommerce-shop-switcher.md` — the switcher defect, plus the runtime verification run against the clone.
4. `docs/superpowers/plans/2026-09-12-linguator-woocommerce-shop-switcher.md` — the plan the switcher fix was built from. Its Task 6 still has the production rollout steps, which have **not** been performed.
5. `AGENTS.md` — project instructions. Note that `.claude/CLAUDE.md` describes a different plugin and is not a source of truth here.

## Git layout

```
main                                              56e8ffe  project docs only, no plugin fix
fix/woocommerce-shop-language-switcher            8164a0c  the switcher fix + tests  -> PR #20
feat/woocommerce-shared-product-identity-phase-1  8164a0c  created, empty, stacked on the switcher branch
```

- `origin` is the upstream repo `CoolPluginsTeam/translate-words`; the account has **READ** access only.
- `fork` is `drbelt27/translate-words`, created for PR #20. Push feature branches there.
- Phase 1 is deliberately **stacked on the switcher branch**, because it extends
  `integrations/woocommerce/`, which exists only there. Branching from `main` would recreate
  `load.php` and `woocommerce.php` and collide with PR #20 at merge time.
- Project documentation stays on `main` and must never enter an upstream pull request. PR #20 was
  checked file by file for this.

To start Phase 1 work:

```bash
git checkout feat/woocommerce-shared-product-identity-phase-1
```

## Decisions already taken — do not reopen these

Agreed with the user on 2026-09-15. The reasoning is in the spec.

1. Issue #19 is split into four phases. Phase 1 only: shared identity, text storage, rendering,
   variations, cart, checkout, order snapshots, SEO meta, minimal entry panel.
2. Storage is **post meta through a repository**. Not a custom table, not hidden shadow posts.
3. Product categories, tags and brands get the same shared-identity plus translated-label treatment
   as products.
4. URLs in Phase 1 carry the **language prefix only**. Translated permalink base and slugs are
   Phase 1b, which is committed work, not optional.
5. SEO title, meta description and Open Graph are translated in Phase 1, through an adapter written
   for SEOPress.
6. Shared mode is opt-in and off by default, with an activation guard that refuses to enable when a
   duplicated translated catalogue already exists.

## The next action

Execute the plan, starting at Task 1, on `feat/woocommerce-shared-product-identity-phase-1`.

The ordering the user asked for is already built into it: the variation and cart matching work comes
first and ends, at Task 11, in a complete purchase of a variable product in German on the clone,
before rendering and the panel are built out in full. 44 variable products and 248 variations are on
the line, and a wrong match means a customer buys the wrong thing.

### Decided during the 2026-09-15 spec review — do not reopen

- **Product category archives are in Phase 1.** Making `product_cat` shared disables the `is_tax()`
  branch of `get_translation_url()`, so without a handler the switcher sends the visitor to the home
  page on all 14 active categories.
- **The revision guard stays best-effort.** It is not atomic; Phase 2 closes it, because bulk
  translation is what makes the race real.
- **The settings toggle goes in the React settings screen**, not a WooCommerce settings tab. That
  carries `npm ci` plus `npm run build` and a rebuilt bundle in the pull request; Task 16 says how to
  keep that diff contained.

### Found by reading the code during that review

- **hreflang disappears on shared product pages** unless `lmat_pre_translation_url` is extended,
  because the alternates loop prints nothing when only the self link resolves. Task 13.
- **The localization context gate is the highest-risk surface.** If a getter localizes during order
  creation, the wrong language is frozen into order history permanently. Task 5, one test per
  context.

The invariant itself: **a translated label never takes part in variation matching.** WooCommerce keeps
matching on canonical option values everywhere — the variation form, `woocommerce_available_variation`,
the AJAX `get_variation` response, and `add_to_cart`.

## Test environment

A full production clone runs in Local at `D:\Local_Sites\dev-linguatorbeltracing`, site
`dev-linguator.beltracing`, URL `http://dev-linguatorbeltracing.local`.

**After a restart, check these four things before trusting anything:**

1. **Docker must be off.** Docker Desktop holds ports 80 and 443 through the WSL network layer and
   stops Local's router from starting. It does not show as a listening socket on the host, so
   `netstat` will not reveal it. The user found this; turning Docker off fixed it.
2. **The MySQL port moves.** Local reassigns it across restarts and service swaps. Read the current
   port, then make `wp-config.php` agree:

   ```bash
   netstat -ano | grep LISTENING | grep -E ":100[0-9][0-9] "
   grep DB_HOST /d/Local_Sites/dev-linguatorbeltracing/app/public/wp-config.php
   ```

   If Local reports `Unable to start site` or `Unable to swap services` naming a port, the cause is
   almost always that a `mysqld` is still alive on the *previous* port while Local pings the new one.
   Shut it down gracefully rather than killing it:

   ```bash
   MYSQLADMIN="/c/Users/matte/AppData/Roaming/Local/lightning-services/mysql-8.4.0+2/bin/win64/bin/mysqladmin.exe"
   "$MYSQLADMIN" --host=127.0.0.1 --port=<old port> --user=root --password=root shutdown
   ```

3. **Outbound HTTP is blocked** in `wp-config.php` via `WP_HTTP_BLOCK_EXTERNAL`. Leave it that way.
   Without it, plugins call their production endpoints on every request and pages exceed Apache's
   40-second FastCGI timeout, which surfaces as a 500, not as a slow page.
4. **Snippets 17 and 18 are deactivated** in the clone's database, and the switcher fix is installed
   in the clone's plugin directory. The clone therefore reflects the post-fix target state.

Clone setup already done: production `wp-config.php` backed up to `app/wp-config.production.bak.php`;
URLs search-replaced; W3 Total Cache drop-ins removed; `blog_public = 0`; and twelve plugins
deactivated that reach external services — backwpup, wordfence, w3-total-cache, google-site-kit,
stripe, both wp-mail-smtp plugins, mcp-adapter, enable-abilities-for-mcp, simple-jwt-login,
woo-update-manager, widget-google-reviews.

### Running things against the clone

```bash
cd /d/Local_Sites/dev-linguatorbeltracing/app/public
php /c/Users/matte/bin/wp-cli.phar --path=. <command>
```

The markup capture scripts used for the switcher verification are kept at
`/d/Local_Sites/dev-linguatorbeltracing/tools/`:

```bash
bash /d/Local_Sites/dev-linguatorbeltracing/tools/capture.sh <url> <label>   # one page
bash /d/Local_Sites/dev-linguatorbeltracing/tools/suite.sh <state-label>     # the seven-page set
```

`suite.sh` captures the regression set: shop IT, shop DE, an ordinary page in both languages, the
front page, a product category archive, and the paged shop. Capture once per state and diff the two
outputs — that is how the switcher fix was proven to change exactly three lines and nothing else.

### Unit tests

PHPUnit is **not** in the repository. The PHAR was downloaded into a session scratchpad that will not
survive the restart. Fetch it again:

```bash
curl -sL -o /tmp/phpunit-9.phar https://phar.phpunit.de/phpunit-9.phar
cd "D:/VS_Projects/Other PR/translate-words"
php /tmp/phpunit-9.phar                                  # 27 tests
LMAT_TEST_WITHOUT_WOOCOMMERCE=1 php /tmp/phpunit-9.phar  # WooCommerce stubs absent
```

Do not run `composer install`: it would rewrite files already committed under `vendor/`.

## Environment, as measured — do not re-derive by guessing

WordPress 7.1, WooCommerce 11.1.0, Elementor 4.2.4 + Pro, SEOPress active (Yoast installed but
**not** active), W3 Total Cache, Pods, woo-multi-currency, woocommerce-members-only, sra-corsi.

| Fact | Value |
| --- | --- |
| Products | 121 active — 65 publish, 56 private |
| Variations | 248 across 44 variable products |
| Products with a translation | 0. All 121 are Italian. |
| Global attribute taxonomies | 0. Every variation attribute is product-local. |
| `product_cat` terms | 14, in use, none translated |
| Languages | `it` default with hidden prefix, `de`, `fr`, `en`; directory URL mode |
| Shop page | id 24619, Italian, `/shop/`. German translation id 27384, `/de/motorrad-shop/`. No French or English shop translation. |
| Also | `page_for_posts` is 24619, the same page as the shop. Unusual, harmless here, but it surprises anyone reading the query behaviour. |

## Open items, none of them blocking the plan

- **PR #20 production rollout.** Nothing has been deployed to beltracing.ch and **snippet 17 is still
  active there**. It must stay active until the plugin is deployed. Fix and snippet coexisting was
  verified on the clone, so deploying with the snippet still on is low risk. The sequence is Task 6
  of the switcher plan.
- **The XML sitemap contains no translated pages.** SEOPress lists 9 page URLs, none of them `/de/`,
  `/fr/` or `/en/`. Pre-existing, unrelated to any of this work, and a real SEO gap.
- **Product category archives** still send the visitor to the home page on language switch. Same
  family as the fixed defect, but on translated taxonomy terms. Out of the scope that was agreed.
- **`readme.txt` changelog** was deliberately left out of PR #20, because it presumes upstream's
  release numbering. The entry exists on `main`. The pull request offers to add it if maintainers
  prefer.

## Things that cost time once already

- `integrations/integration-build.php` declares itself auto-generated, but **no generator exists in
  this repository**. The `woocommerce` entry was added by hand, in the position a directory scan
  produces, and PR #20 says so and asks maintainers to regenerate it if they have the tooling.
- The clone's plugin files use CRLF while the repository uses LF. Diff them with
  `diff --strip-trailing-cr`, or every file looks changed.
- `.claude/settings.json` used to deny reading `tests/`, which silently blocked creating the test
  suite. The deny was removed with the user's agreement.
