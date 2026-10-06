# HANDOVER

Spec: `docs/WST-V1-PLAN.md` (owner-approved, includes decision log D1–D9). Working rules: `CLAUDE.md`.

## Status

**Phase 0 — Setup & spikes: done.** PHPStan level 8 is clean and provider facts are recorded from official sources (Gemini per-model free-tier limits are unpublished and stay unconfirmed). `wp-env` is configured but not run here (no Docker daemon); the container uses MariaDB + `php -S`.

**Phase 1 — Core without providers: done and approved by the owner.**

**Phase 2 — Providers, queue, limiter, usage: started.**
- Done: `bin/capture-translatex-fixtures.php` (key only in the `X-API-Key` header, never printed, a fixture containing the key is refused); keyless fixtures `tests/fixtures/providers/translatex/{missing-key,invalid-key}.json`.
- Finding: TranslateX answers a missing **and** an invalid key with **HTTP 400** and `{"err":"invalid api key"}` (not 401/403). Under D11 the adapter must map this 400 + `err` to `AuthError`; plan §7.2's "classify by HTTP status first" does not hold for this case.
- Blocked: `WST_TRANSLATEX_KEY` is not set in this session (environment variables reach new sessions only). API hosts are reachable: `api.translatex.com` (400 without key), `api.cognitive.microsofttranslator.com` (200), `generativelanguage.googleapis.com` (Google's own 403 without key).

## Completed (Phase 0)

- Plugin scaffold at repo root: `wp-site-translator.php`, `src/Autoloader.php` (PSR-4, no Composer at runtime), `src/Config.php` (all names), `.distignore`, `.gitignore`, `.editorconfig`.
- Dev tooling: `composer.json` (+ lock) with PHPUnit 9.6, wp-phpunit 7.1, WordPress core 7.1 (tests only), WPCS 3 + PHPCompatibilityWP, PHPStan 2 + phpstan-wordpress; `phpunit.xml.dist` (unit), `phpunit-integration.xml.dist` (integration), `phpcs.xml.dist`, `phpstan.neon.dist`; `package.json` with `@wordpress/scripts` 36 and `@wordpress/env` 11; `.wp-env.json` (dev env adds WooCommerce, Elementor, WordPress Importer).
- HTML spike, which is also the Phase 1 extraction core: `src/Html/{Lexer,Extractor,Frame,Segment,Replacer,Text}.php`.
- Fixtures: 30 saved pages in `tests/fixtures/pages/` produced by `bin/capture-fixtures.sh` from a local site with Theme Unit Test data, block test data, Elementor (2 pages incl. Bengali) and WooCommerce sample products, under Twenty Twenty-One / Twenty Twenty-Five / Twenty Twenty. html5lib tokenizer inputs via `bin/fetch-html5lib-tests.sh` (pinned commit, gitignored).
- Tests: `tests/Unit/Html/TextTest.php`, `tests/Integration/Html/{LexerTest,ExtractorTest,RoundTripTest}.php`.

## Completed (Phase 1)

- `src/Database/Schema.php` — §4 tables via dbDelta, `wst_db_version`, post-install table check; `src/Plugin.php` — activation, upgrade check, wiring.
- `src/Settings.php` — `wst_settings` with only the settings that have an effect now: languages, target slug, site mode, discovery switches and caps, link rewriting, hreflang options.
- `src/Languages/{Language,Registry,Current}.php` — 56 curated locales with native names, slugs, RTL and provider code overrides (TranslateX `iw`/`no`/`zh-CN`/`zh-TW`, Microsoft `zh-Hans`/`zh-Hant`/`pt-pt`/`sr-Cyrl`/`fil`); unknown locales get derived defaults.
- `src/Routing/{Urls,Router,PathRules,LanguageUrls}.php` — prefix detection before `parse_request` (stripped for routing, restored after, so canonical redirects keep `/bn/`); locale switch; text direction forced through core's `ltr`/"text direction" string so `is_rtl()` and theme RTL CSS work without a core language pack; `home_url` and `wp_redirect` prefixing (REST, admin, assets, PHP entry points exempt); AJAX/REST language from `wst_lang` or the referer.
- `src/Storage/StringStore.php` — batched lookup (one query + object cache), discovery writes (strings, occurrences, pages, `is_global` at 20 pages), manual saves (inline markup must match, then `wp_kses`), search, pending count.
- `src/Log/Logger.php` — `wst_log` ring buffer (1,000 rows).
- `src/Render/{Pipeline,PageContext,DiscoveryGate,HeadTags}.php` — output buffer on target HTML pages, offset replacement, inline validation, link rewriting, `<html lang dir>`, failure guard (original HTML returned, logged, rethrown under `WP_DEBUG`), §6A gate with hourly caps, uncacheable-while-pending headers, hreflang + `x-default`, drop-region option.
- `src/Switcher/Switcher.php` — `[wst_switcher]`.
- `src/Cli/StringCommand.php` — `wp wst string set|get|list` (permanent).
- `src/Html/InlineMarkup.php` — tag-signature comparison and per-original `wp_kses` allowlist.
- Tests: `tests/Unit/Routing/{UrlsTest,PathRulesTest}.php`, `tests/Integration/{SettingsTest,PluginTest}.php`, `tests/Integration/{Languages,Routing,Storage,Log,Render,Database}/*Test.php`; `tests/phpstan/wp-cli-stubs.php` (local WP-CLI stubs for PHPStan; `php-stubs/wp-cli-stubs` does not support WordPress 7 stubs).

## Decision log (Phase 1)

| # | Decision | Reason |
|---|---|---|
| P1 | **Inline merge needs at least two text runs.** `<li><a href="…">Design</a></li>` is a plain text segment "Design"; `Read <a>more</a>` and `<a>Design</a> (3)` merge. Refines plan §6.2 / G1b. | Found on the live site: every menu and category link became its own inline string containing its URL, so the same word was translated once per link. Merging only helps when text on both sides of a tag can be reordered. |
| P2 | **Inline originals are stored language-neutral**: internal links inside them lose the `/{slug}/` prefix before hashing; translations get the prefix back when rendered. | WordPress builds prefixed links on target pages; without this, changing the slug would orphan every inline translation. |
| P3 | Attributes of `<link>` elements are not translated. | Feed/oEmbed titles in `<head>` are never displayed; they only cost budget. |
| P4 | `block_crawlers` (default on) decides whether bots may discover; §6A's "not a bot" is that default. | §13 defines the setting; no dead option. |
| P5 | An inline translation whose markup no longer matches is not used (original shown, warning logged). Per-segment fallback (§6.5) needs per-segment translations, which arrive with providers in Phase 2. | No silent partial output. |
| P6 | A wildcard rule `/shop/*` matches `/shop` and everything below; `/sale*` matches any path starting with `/sale`; `{{home}}` is `/`. | Plan §9 path rules; shared with Phase 3. |
| P7 | Saving a manual inline translation rejects any markup difference before sanitising (no silent stripping). | Fail loudly; found by a test where `<script>` was stripped and its text kept. |

## Phase 1 acceptance (plan §16) — one line per criterion

- PASS — `/bn/` renders translated text from the DB: `Phase1AcceptanceTest::test_target_url_renders_translated_text_from_the_database` (real `/bn/hello/` request through Router + Pipeline output buffer), plus `PipelineTest::test_stored_translations_replace_text_inline_attributes_and_title`.
- PASS — Default language unaffected: `Phase1AcceptanceTest::test_default_language_request_is_not_buffered_or_changed` (no buffer, output byte-identical), `RouterTest::test_default_language_is_untouched`.
- PASS — Menus/links stay in the language: `Phase1AcceptanceTest::test_menus_and_hard_coded_links_stay_in_the_language` (`wp_nav_menu` post item, `home_url` item and a hard-coded `/contact/` item all prefixed), `RouterTest::test_canonical_redirect_keeps_the_prefix`, `RouterTest::test_internal_redirects_stay_in_the_language`.
- PASS — RTL target flips `dir` and loads theme RTL CSS: `RouterTest::test_rtl_target_flips_direction_and_loads_rtl_stylesheets` (`is_rtl()`, `style-rtl.css`, `dir="rtl"`), `PipelineTest::test_rtl_target_sets_document_direction`.
- PASS — Round-trip identity holds: `RoundTripTest` (30 saved pages + 6,813 html5lib cases), `PipelineTest::test_saved_pages_keep_every_byte_without_translations` (output = input except `<html>`), `PipelineTest::test_saved_pages_only_change_links_with_rewriting_on`.
- PASS — Unit + integration tests green: `composer test:unit` 60 tests, `composer test:integration` 7,017 tests (WP 7.1.2); PHPCS clean; PHPStan level 8 no errors.

## Phase 1 acceptance — live evidence

Live checks on the dev site (WordPress 7.1.2, Twenty Twenty-One, WooCommerce and Elementor from source, `php -S` with opcache) plus the test suites:

| Criterion | Result |
|---|---|
| `/bn/` renders translated text from the DB | Translations seeded with `wp wst string set` (text, inline with link, submit-button attribute) render on `/bn/hello-world/`; the inline translation's author link is prefixed. A tampered inline translation (changed `href`) is rejected by the CLI. `PipelineTest` covers text, inline, attributes, `<title>`. |
| Default language unaffected | With the plugin active and no target, default pages are byte-identical to the plugin-inactive baseline (after removing the site's own nondeterminism: category-order ties and Elementor's CSS `ver`). With target `bn_BD`, the only change is the three intended `hreflang` link tags. TTFB median of 25 (opcache): `/hello-world/` 109 ms active vs 103 ms inactive; `/` 101 vs 115 ms — within noise. |
| Menus/links stay in the language | 93 internal links on `/bn/hello-world/` are prefixed (menus via `home_url`, hard-coded links via rewriting); the only unprefixed internal link is the `hreflang` alternate. Canonical is `/bn/hello-world/`; `/bn/hello-world` → 301 `/bn/hello-world/`; `/bn` → 301 `/bn/`; 404 stays 404; REST `/wp-json/` unprefixed. |
| RTL target flips `dir` and loads theme RTL CSS | Target `ar`: `/ar/hello-world/` has `<html dir="rtl" lang="ar">`, body class `rtl`, and Twenty Twenty-One loads `style-rtl.css`; the default page stays `ltr` with `style.css`. `RouterTest` covers the same. |
| Round-trip identity holds | `RoundTripTest` (30 pages + html5lib) and `PipelineTest`: with no translations and link rewriting off, the pipeline output equals the input except the `<html>` element; with rewriting on, only `href`/`action` values change. |
| Unit + integration tests green | Unit 60, integration 7,014 (WP 7.1.2); PHPCS clean; PHPStan level 8 no errors. |

Also verified live: discovery records strings for an anonymous browser visit (cap of 100 per page per hour reached on the first visit, as configured), not for curl's bot user agent; a pending queue row sends `Cache-Control: no-cache, must-revalidate, max-age=0` and `X-WST-Pending: 1`, a failed row does not; the switcher links each language's equivalent URL with `hreflang`/`lang`/`aria-current`.

**Performance** (opcache on): `Pipeline::process()` without discovery — 311 KB page 55 ms, 277 KB 42 ms, 66 KB Elementor page 13 ms. Uncached target-page TTFB overhead 28 ms (`/bn/hello-world/`) to 66 ms (`/bn/` home with many untranslated strings). Page caches serve target pages without running the pipeline.

## Decision log (Phase 0 gates)

| # | Decision | Reason |
|---|---|---|
| G1 | **HTML handling: the core HTML API (`WP_HTML_Tag_Processor`) as the lexer + our own light element stack + our own offset splicing.** Not `WP_HTML_Processor`, and not our own tokenizer. | The core lexer is spec-compliant (raw text, RCDATA, comments, character references) and maintained upstream; writing our own would be weeks of edge cases. `WP_HTML_Processor` parsed all 30 pages without bailing but is 5–7× slower (83 ms vs 19 ms on a 311 KB page) and can bail on unsupported markup. Our stack handles the HTML5 implied end tags that matter (p, li, dd/dt, option, table parts, headings, a) and foreign content (SVG/MathML switch the lexer namespace). |
| G1a | The only core internal used is the **protected `$bookmarks`** (to read a token's byte span), in `WST\Html\Lexer`. Attribute and `<title>` edits go through the public `set_attribute()` / `set_modifiable_text()` on a one-tag sub-processor, so quoting and escaping stay core's job. | Core keeps offsets private; bookmarks are the documented subclass extension point. `LexerTest` pins the behaviour. |
| G1b | Inline segments: the **outermost** element whose whole subtree is text + inline tags (plan list) with ≥ 1 translatable text and ≥ 1 inline tag. Comments, non-inline tags (including `img`) and excluded descendants break it. Attribute segments inside an inline segment are kept as children (`Segment::$parent`) and applied only when the inline segment itself is not translated. | Matches plan §6.2 and D8; avoids overlapping edits. |
| G2 | **WP minimum 6.7** confirmed. | `next_token()` 6.5, `WP_HTML_Decoder` 6.6, `change_parsing_namespace()` 6.7. The whole integration suite was run against WP 6.7.9, 6.8.10 and 6.9.9: everything passes except 3 html5lib inputs that end in a truncated comment opener (`<!---`), where core's lexer itself emits a PHP warning (fixed in 7.x). Real pages never end like that, and output is unaffected. |
| G3 | **Coding standard**: WPCS `WordPress-Extra` + `WordPress-Docs`, with PSR-4 file names, camelCase methods/properties/variables (the plan's interfaces are camelCase), short arrays, and the `WST` namespace exempt from WPCS's 4-character prefix rule (hooks/globals use `wst_` / `WST_`). | Plan fixes PSR-4 and the namespace; mixing snake_case variables with camelCase methods would be inconsistent. |
| G4 | **Build tooling** (plan §3 gate): `@wordpress/scripts` 36 for all JS/CSS builds, `@wordpress/env` 11 for the Docker dev site. Built files go to `build/` and are committed. | Official WordPress toolchain, the same React/`@wordpress/components` versions core uses, zero custom webpack config, RTL CSS generation built in. Needs Node ≥ 22.22.2. |
| G5 | **Tests**: unit suite without WordPress (pure helpers); integration suite on wp-phpunit with the DB from `WST_TEST_DB_NAME/USER/PASSWORD/HOST` (defaults `wst_tests`/`root`/empty/`127.0.0.1`). `WST_TEST_ABSPATH` + `WST_TEST_WP_PHPUNIT_DIR` run the suite against another WP version (used for G2). PHPUnit 9.6 because PHP 8.0 is supported. | One config file works in wp-env, CI and local MySQL. |
| G6 | PHPStan **level 8** with `szepeviktor/phpstan-wordpress`, `phpVersion: 80000`. | Plan D8. |

## Spike results (acceptance: round-trip green)

`RoundTripTest` checks for every input that:
1. an empty translation map returns the input byte for byte;
2. a map that wraps every string in markers keeps the full tag sequence;
3. re-extracting that output yields exactly the wrapped strings (this covers decode/encode, whitespace edges, attributes, `<title>`, meta and inline segments).

| Corpus | Inputs | Result |
|---|---|---|
| Saved pages (Elementor, WooCommerce shop/product/category/cart, Gutenberg block test data, classic theme unit tests, search, 404, password-protected, Bengali, Greek) | 30 | all green |
| html5lib tokenizer tests, raw and wrapped in `<p>` | 6,813 cases | all green on WP 7.1.2; 3 core warnings on WP 6.7–6.9 (G2) |
| Focused extractor/lexer/text tests | 28 | green |

**Performance** (CLI, no opcache; best of 5; `twentytwenty--home` is 311 KB, 10.2k tokens):

| Page | Size | Segments | Extract | Replace all |
|---|---|---|---|---|
| twentytwenty home | 311 KB | 1,121 | ~45 ms | ~8.5 ms |
| twentytwentyfive home | 277 KB | 908 | ~35–43 ms | ~7 ms |
| block-category-common | 110 KB | 139 | ~6 ms | ~0.7 ms |

The lexer alone takes ~13 ms on 311 KB (~17 ms with span reads); the rest is our walk. A 200 KB page is estimated at ~30 ms. Re-measure with opcache in Phase 1 as plan §15 asks.

## Provider facts (checked 2026-10-06 against official sources)

**Microsoft Translator** — confirmed (learn.microsoft.com `service-limits`, ms.date 2026-08-11; `status-response-codes`; `v3/translate`; Azure pricing page):
- `POST https://api.cognitive.microsofttranslator.com/translate?api-version=3.0&from=…&to=…[&textType=html]`; headers `Ocp-Apim-Subscription-Key`, `Ocp-Apim-Subscription-Region` (regional resources), `Content-Type: application/json; charset=UTF-8`; body `[{"Text": …}]`.
- Per request ≤ 1,000 elements and ≤ 50,000 characters in total (D12).
- F0: 2M characters free per month; throttle 2M characters/hour consumed evenly, about 33,300/min sliding. S1 40M/hour. No concurrency limit. Latency up to 15 s.
- Error codes and their mapping are in plan §7.1 (D11: `403001` → `QuotaExceeded`).
- `textType=html` needs well-formed complete elements; supports `class="notranslate"` and `<mstrans:dictionary>` (D13, decide in Phase 2).

**TranslateX** — confirmed (translatex.com/api-documentation and pricing):
- `POST https://api.translatex.com/translate?sl=…&tl=…` (`sl=auto` allowed), form-encoded repeated `text=`; or `html=<string>` for HTML (response `translation` is then a string). Key via `key` query parameter **or `X-API-Key` header** (we use the header).
- `GET /supported-languages` → `{"languages":[{"language","name"}]}`; `POST /detect` exists (not used).
- Response headers `X-TX-RateLimit` (e.g. `50/min`) and `X-TX-RateLimit-Remaining`.
- Plans: Free (35 languages, small model, 50 calls/min, no commercial use, no privacy mode, no detection, no HTML); Startup $19.99 (50 languages, large model, 50/min, commercial, privacy mode); Business $29.99 (+ detection, 75/min); Enterprise $39.99 (+ HTML, 100/min). Free-plan content may be stored and used for training. English-centric routing.
- **Still unconfirmed** (not documented): error body and HTTP status codes, per-request string/character limits, whether Bengali is in the free plan's 35 languages, client-id whitelisting. Resolve with the Phase 2 fixture capture.

**Gemini** — ai.google.dev rate-limits, pricing, structured-output and troubleshooting pages:
- Confirmed: key header `x-goog-api-key`; limits are RPM, input TPM and RPD **per project, not per key**; RPD resets at midnight Pacific; any exceeded limit → `429 RESOURCE_EXHAUSTED`; retry 429/408/5xx with exponential backoff, never 400/402/403. Free tier ("free of charge") exists for current Flash and Flash-Lite text models, including `gemini-3.8-flash`, `gemini-3.7-flash`, `gemini-3.6-flash`, `gemini-3.5-flash`, `gemini-3.5-flash-lite`, `gemini-3.1-flash-lite`, `gemini-2.5-flash`, `gemini-2.5-flash-lite`, and also `gemini-2.5-pro`; `gemini-3.1-pro-preview` is paid-only. Free-tier content **is used to improve Google's products**; paid-tier content is not.
- **Unconfirmed:** per-model free-tier RPM/TPM/RPD numbers. Google no longer publishes them ("view your active rate limits in AI Studio"). The earlier third-party figures (10 RPM / 250 RPD Flash, 30 RPM / 1,000 RPD Flash-Lite) remain unconfirmed, and their claim that Pro is paid-only is **contradicted** for `gemini-2.5-pro`. Defaults must be conservative and editable; the UI points to AI Studio.

## Plan adjustments

D10–D14 approved by the owner and applied to `docs/WST-V1-PLAN.md` (§0, §7, §7.1, §7.2, §7.3, §8, §20).

## Remaining

- Phases 2–7 per plan §16.
- Deferred from Phase 1 by design (built together with their phases, no dead settings now): enqueueing discovered strings and cache purging (Phase 2, needs providers); page and path modes and `off` behaviour (Phase 3); "prefix default language" option, user exclude selectors, floating switcher and switcher styles (Phase 4); admin notice for pipeline failures (Phase 4 Overview); TranslatePress coexistence guard (6b); AJAX/REST fragment translation (6c).

## Files changed (Phase 1)

`wp-site-translator.php`, `src/Plugin.php`, `src/Settings.php`, `src/Database/Schema.php`, `src/Languages/*`, `src/Routing/*`, `src/Storage/StringStore.php`, `src/Log/Logger.php`, `src/Render/*`, `src/Switcher/Switcher.php`, `src/Cli/StringCommand.php`, `src/Html/{Extractor,Frame,Replacer,InlineMarkup}.php`, `phpstan.neon.dist`, `tests/phpstan/wp-cli-stubs.php`, tests listed above, `CLAUDE.md`, `HANDOVER.md`, `docs/WST-V1-PLAN.md` (D10–D14).

## Files changed (Phase 0)

`wp-site-translator.php`, `src/Autoloader.php`, `src/Config.php`, `src/Html/*.php`, `tests/**` (bootstraps, `wp-tests-config.php`, Unit, Integration, `fixtures/pages/*.html`), `bin/capture-fixtures.sh`, `bin/fetch-html5lib-tests.sh`, `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.wp-env.json`, `.distignore`, `.gitignore`, `.editorconfig`, `CLAUDE.md`, `HANDOVER.md`, `docs/WST-V1-PLAN.md` (copy of the approved plan).

## Validation status

- `vendor/bin/phpunit` (unit): 60 tests green.
- `vendor/bin/phpunit -c phpunit-integration.xml.dist`: 7,014 tests green on WP 7.1.2 (MariaDB 10.11, PHP 8.3).
- Phase 0 suite on WP 6.7.9 / 6.8.10 / 6.9.9: green except the 3 core-warning inputs (G2). The Phase 1 suite has not been re-run on those versions (CI matrix in Phase 7).
- `vendor/bin/phpcs`: clean.
- PHPStan level 8: **no errors** (container: `php <scratchpad>/phpstan/phpstan.phar analyse --memory-limit=1G`) (PHPStan 2.3.0 official release phar + `szepeviktor/phpstan-wordpress` 2.0.4 / `php-stubs/wordpress-stubs` 7.1.2).
- `npx wp-scripts`: installed; no entry points yet (the Phase 1 switcher needs no JavaScript; the first build comes with Phase 4).

## Known issues

1. **PHPStan in the cloud container.** `composer install` cannot fetch `phpstan/phpstan` here: the package is only distributed as a GitHub API zipball (`api.github.com/repos/phpstan/phpstan/zipball/…`), which returns 403 for this session even after the network change, because GitHub API access is limited to repositories attached to the session. Workaround used: the official release asset `github.com/phpstan/phpstan/releases/download/2.3.0/phpstan.phar` (SHA-256 `64a1e773…ec83f`, identical to the phar in the official `2.3.0` git tag; the GPG signature could not be checked because the keyserver returned no key), plus a gitignored `composer.local.json` that declares `provide: phpstan/phpstan 2.3.0` so the WordPress stubs install. On a normal machine `composer install && composer analyse` works with the committed files.
2. **Network policy**: the WebFetch tool is still blocked for the docs hosts; `curl` works and was used.
3. WP 6.7–6.9 core lexer warning on input ending in `<!---` (G2). Harmless for real pages. A CI matrix on those versions must expect it.
4. Node in the container is 22.22.0; `@wordpress/scripts` 36 asks for ≥ 22.22.2 (npm warns only).
5. Open extractor items: user exclude selectors (§13, Phase 4); entity canonicalisation of inline originals (`&#8217;` vs `’` hash differently); a runtime self-check for the bookmark-span dependency (G1a) that fails loudly (Phase 7 hardening).
6. The dev site's WooCommerce (built from GitHub without its JS build) shows an empty shop loop in both languages; product pages render. Not a plugin issue.
7. On the first target visit discovery stops at the per-page cap (100/hour by default); the rest of the page is discovered on later visits or by editor scans (Phase 5).

## Cloud container dev setup (when Docker is unavailable)

```
apt-get install -y mariadb-server && service mariadb start
mysql -e "CREATE DATABASE wst_tests; CREATE USER 'wst'@'localhost' IDENTIFIED BY 'wst'; GRANT ALL ON wst_tests.* TO 'wst'@'localhost';"
# composer: phpstan cannot be downloaded here -> composer.local.json without the phpstan packages
COMPOSER=composer.local.json COMPOSER_ALLOW_SUPERUSER=1 composer install --prefer-source
bin/fetch-html5lib-tests.sh
WST_TEST_DB_USER=wst WST_TEST_DB_PASSWORD=wst vendor/bin/phpunit -c phpunit-integration.xml.dist
```
The fixture site (Elementor and WooCommerce built from GitHub source, wp-cli via Composer, `php -S` with a router) is only needed to re-capture fixtures; the steps are in this session's history, and `bin/capture-fixtures.sh` lists the URLs.

## Exact next step

Phase 2, first task, as soon as `WST_TRANSLATEX_KEY` is available in the session: `php bin/capture-translatex-fixtures.php all`, then `php bin/capture-translatex-fixtures.php rate-limit`. Review the fixtures (no key inside), and record in this file: error format and status codes per case, per-request item/character limits (batch-100/101/500, chars-5000/20000/60000-total), whether `bn` and `ar` are in `/supported-languages` for this key's plan, and whether `html=` works on this plan. Then the provider interface, shared placeholder protection and the TranslateX adapter against those fixtures.
