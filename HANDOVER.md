# HANDOVER

Spec: `docs/WST-V1-PLAN.md` (owner-approved, includes decision log D1–D9). Working rules: `CLAUDE.md`.

## Status

**Phase 0 — Setup & spikes: done.** PHPStan level 8 is clean and provider facts are recorded from official sources (Gemini per-model free-tier limits are unpublished and stay unconfirmed). `wp-env` is configured but not run here (no Docker daemon); the container uses MariaDB + `php -S`.

**Phase 1 — Core without providers: done and approved by the owner.**

**Phase 2 — Providers, queue, limiter, usage: code complete; TranslateX verified live; Microsoft and Gemini live checks pending (keys).**
- Done and tested (fake provider + recorded/stubbed HTTP): provider contract and typed errors, placeholder protection (never-translate terms) and tag placeholders, segmentation for plain-text providers, rate limiter (rpm / rpd / chars-per-minute, `0` = unlimited, `GET_LOCK`, 4-process concurrency test), monthly usage and budget (80 % warning, 100 % stop), queue and worker (priorities, backoff, `max_attempts`, fallback switch and return, 429 always honoured, manual translations never overwritten), visitor auto-queue (priority 5) with the §6A cache rules, WP-Cron one-minute event that exists only while work is queued, `POST /wst/v1/queue/run` (one batch, `manage_options` + REST nonce), WP-CLI `wp wst queue run|status|retry-failed|clear` and `wp wst provider test <id>` (lifts a pause on success), page-cache purge (LiteSpeed `litespeed_purge_url`, WP Rocket `rocket_clean_files()`, generic `wst_purge_url`), adapters for TranslateX, Microsoft and Gemini.
- Recorded keyless fixtures (`bin/capture-provider-fixtures.php`, `bin/capture-translatex-fixtures.php`): TranslateX missing/invalid key → HTTP 400 `{"err":"invalid api key"}`; Microsoft missing/invalid key → HTTP 401 code **401001** (plan listed 401000; both are mapped by the 401xxx class), language list (138 languages incl. `bn` and `ar`); Gemini invalid key → HTTP 400 `INVALID_ARGUMENT` with `details[].reason = API_KEY_INVALID`, missing key → HTTP 403 `PERMISSION_DENIED`.
- **TranslateX verified with the owner's free key** (2026-10-06; the key was given in chat, kept in a mode-600 file outside the repo, passed only via the environment; no fixture, log or commit contains it; the owner was advised to rotate it). Fixtures in `tests/fixtures/providers/translatex/` (`all`, `tokens`, `limits`, `rate-limit` modes). Facts now in plan §7.2:
  - Errors are JSON `{"err": "…"}`: 400 `invalid api key` (missing/invalid key), 400 `invalid source or target language code`, 400 `no texts found`, 400 `long text in request`, 403 `HTML translation feature is not available for your api key`, 429 `too many requests` (no `Retry-After`; `X-TX-RateLimit: 50/min`). Success responses carry `X-TX-RateLimit-Remaining`.
  - Limits: each text ≤ **2,000 UTF-8 bytes** (≈ 700 Bengali characters); 500 texts and 60,000 characters per request accepted (58 s for 60,000). 429 reached on the 51st request of a burst.
  - Free plan: 35 languages, **`bn` and `ar` included**. `x-api-client: WP-Site-Translator/0.1.0` accepted.
  - Placeholders: `[[1]]` came back as `[ [ 1]]` and once as `[ [ 3]]]]`; `{1}` survived in `bn` and `ar` (P16).
  - Live end to end (real `Worker` → `TranslateX` → `wst_translations`, scratch test not committed): `testConnection` OK; 7/7 strings translated and stored as machine translations for `bn_BD` and `ar` (printf placeholder, URL and the never-translate term "WooCommerce" preserved; inline links translated as whole sentences, P20; a 2,699-byte text split and joined, P19). Our limiter held a request for its 1.2 s spacing as configured.
- Blocked on keys: `WST_AZURE_KEY` (+ `WST_AZURE_REGION` for a regional resource) and `WST_GEMINI_KEY` are not set.

**Phase 3 — Modes: done; awaiting owner approval.** Acceptance lines below ("Phase 3 acceptance").
- `WST\Modes\Resolver`: page setting (`_wst_mode` post meta: inherit/auto/manual/off) > path rules (new setting `path_rules`, ordered, first match, `/x/*`, `{{home}}`) > site mode. Gates discovery (only `auto` discovers and auto-queues), the pipeline, hreflang and the switcher.
- "Off" pages (new setting `off_behavior`): `redirect` (default, 302 to the original URL) or `original` (original text at the target URL, `lang`/`dir` of the default language, canonical to the original URL). No hreflang and no switcher on off pages in either language.
- Explicit machine translation works on manual pages: `WST\Queue\Requests` (editor ids at priority 1, "translate this page now" at priority 2; manual translations never sent; refused on off pages with a reason).
- REST: `GET /wst/v1/pages` (posts of every public type: own mode, applied mode and source, coverage, last seen/scan; filters `search`, `post_type`, `mode`, `include`, pagination headers), `POST /wst/v1/pages/mode` (bulk; per-post `edit_post` check; purges changed pages), `POST /wst/v1/strings/translate` (`ids[]` or `post_id`, `mode=queue|now`). All need the new `wst_translate` capability (administrators and editors; installed on activation and once after updates).
- Post editors: block editor panel `build/post-panel.js` (source `assets-src/post-panel/index.js`) and a classic meta box (no-JS "Translate this page now" via `admin-post.php`). Verified live in the block editor (see Phase 3 acceptance).

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
- Resolved in Phase 2 with real fixtures (see Status and plan §7.2): error format and status codes, 2,000-byte per-text limit, free plan includes `bn` and `ar`, our client id is accepted.

**Gemini** — ai.google.dev rate-limits, pricing, structured-output and troubleshooting pages:
- Confirmed: key header `x-goog-api-key`; limits are RPM, input TPM and RPD **per project, not per key**; RPD resets at midnight Pacific; any exceeded limit → `429 RESOURCE_EXHAUSTED`; retry 429/408/5xx with exponential backoff, never 400/402/403. Free tier ("free of charge") exists for current Flash and Flash-Lite text models, including `gemini-3.8-flash`, `gemini-3.7-flash`, `gemini-3.6-flash`, `gemini-3.5-flash`, `gemini-3.5-flash-lite`, `gemini-3.1-flash-lite`, `gemini-2.5-flash`, `gemini-2.5-flash-lite`, and also `gemini-2.5-pro`; `gemini-3.1-pro-preview` is paid-only. Free-tier content **is used to improve Google's products**; paid-tier content is not.
- **Unconfirmed:** per-model free-tier RPM/TPM/RPD numbers. Google no longer publishes them ("view your active rate limits in AI Studio"). The earlier third-party figures (10 RPM / 250 RPD Flash, 30 RPM / 1,000 RPD Flash-Lite) remain unconfirmed, and their claim that Pro is paid-only is **contradicted** for `gemini-2.5-pro`. Defaults must be conservative and editable; the UI points to AI Studio.

## Decision log (Phase 2)

| # | Decision | Reason |
|---|---|---|
| P8 | Inline translations may reorder tags: validation compares the tag multiset and nesting, not the order. | Word order differs between languages (Bengali puts the verb last); tag ids and attributes still have to match exactly. |
| P9 | `supportsPair()` reads a stored language list (`wst_{provider}_languages`) and never calls the provider. The connection test fills it; translation runs refresh it after 24 h. Without a list the provider is unavailable with the message "run the connection test". Gemini accepts any valid code pair. | It runs during page rendering, and rendering never calls a provider (§6). |
| P10 | TranslateX HTML mode (`html=`, Enterprise) is not used; inline strings go out as segments (flag 1). | Its behaviour is undocumented in detail and no Enterprise key is available; plan §7.2 says to confirm before enabling. |
| P11 | D13 settled: never-translate terms use the shared opaque tokens for every provider, not Microsoft's `notranslate`/`mstrans:dictionary`. | One mechanism, already tested; the result is the same when rows move to the fallback provider; plain-text strings would otherwise need HTML mode. |
| P12 | A result-count mismatch or a "request too large" answer (Microsoft 400050/400072/400077, Gemini `MAX_TOKENS`) fails the batch with a fixed prefix; the retry sends those rows one per request. | Plan §7.2/§7.3 "retry in smaller batches", without a second queue mechanism. |
| P13 | Gemini: default model `gemini-3.5-flash-lite` (free tier, "optimized for … translation", no shutdown date); defaults 5 RPM, 100 RPD, 50 items / 5,000 characters per request, day in `America/Los_Angeles`; structured output via `generationConfig.responseFormat.text` (the API reference marks `responseSchema` deprecated); 402 → `QuotaExceeded`. | Per-model free limits are unpublished; values are conservative and editable. |
| P14 | Visitor queueing: on a discoverable page (auto mode, §6A gate) every untranslated string of the page is queued at priority 5 for the active provider, including strings recorded earlier. With no usable provider nothing is queued and existing rows do not make the page uncacheable. | Strings recorded before a provider was set up would otherwise never be translated; §6A: paused/over-budget providers must not block caching. |
| P15 | Providers are registered only when their key is set, and built lazily. | A missing key shows "not configured" instead of failing per request; ordinary requests never read the secrets option. |
| P16 | TranslateX uses the placeholder format `{%d}`; `ProtectedText::restore()` also rejects a translation that leaves token punctuation outside a token. | Probe on the free key: `[[1]]`, `[1]`, `__1__`, `#1#`, `⟦1⟧` were altered or dropped in `bn` or `ar`; `{1}` and `XQ1QX` survived. Stray `]]` would otherwise end up in stored text. |
| P17 | `long text in request` maps to the one-per-request retry; 403 "not available for your api key" is a `PermanentError`, not an auth pause. (Texts over 2,000 bytes are split, P19.) | A plan restriction must not pause the provider. |
| P18 | **The rate limiter spaces requests evenly** (strict spacing / GCRA): at `requests_per_minute = 50` a request may start every 1.2 s, never a burst of 50; `chars_per_minute` the same way (a request "costs" chars × 60 / cpm seconds; one oversized request goes alone when nothing else is scheduled); `requests_per_day` is a counter in the provider's day time zone; a provider 429 blocks until its `Retry-After` (TranslateX: 60 s, it sends none). | Providers do not document whether their minute is fixed or rolling; even spacing can never exceed the limit in any 60-second window (`RateLimiterTest::test_no_sixty_second_window_exceeds_the_rpm`, `test_concurrent_workers_never_exceed_the_rate`). Cost: no burst, a batch of N requests takes (N − 1) × 60/rpm s; with 100 texts per request that is still 5,000 texts a minute on TranslateX free. Live: the free key's 429 came on the 51st request of a back-to-back burst, which spacing never produces. |
| P19 | TranslateX texts over 2,000 UTF-8 bytes are split by `Providers\TextSplitter` at sentence ends (`. ! ? … । ॥ ؟ 。`, closing quotes stay with their sentence) or line breaks, then spaces, then on a character boundary; pieces go in the same request and are joined with the original separators. An empty piece fails the whole text. | Replaces "fail texts over the limit" (owner request). Placeholder tokens contain no spaces, so they are never cut. Live: a 2,699-byte English text came back joined in `bn` and `ar`. |
| P20 | Inline strings for plain-text providers go as **one sentence with tag tokens** (`Read {1}our story{2} today`, `Protector::protectInline()`): tags and protected text share one token numbering, text between tags is decoded, provider output is HTML-encoded before the tokens are restored, then `InlineMarkup` checks the tag structure. On any mismatch the row fails with a `Tags …` error and the queue retries it as segments (flag 1) after the normal backoff. | Owner request: word order around links. Live on TranslateX: `bn` "আজ <a…>আমাদের গল্প</a> পড়ুন", `ar` "اقرأ <a…>قصتنا</a> اليوم" (segments had given broken fragments). Attributes still never leave the site. |
| P21 | **Run timing.** One worker run lasts at most its budget (20 s; `--budget` in WP-CLI) plus one request timeout (default 30 s, setting 5–120): no request starts after the budget, except the first of a run; unsent rows are released without counting an attempt. Before each request the worker makes sure `timeout + 5 s` fits PHP `max_execution_time`, raising it with `set_time_limit()` when needed; if that is impossible it stops, logs once and reports the provider as blocked. Claimed rows are locked for `2 × timeout + 60 s`, longer than any run. | Before, the budget was only checked between batches, so a batch retried one item per request could chain up to 100 × 30 s. TranslateX measured about 1 s per 1,000 characters, so a 10,000-character batch takes about 10 s, well inside the 30 s timeout. A run's worst case (20 + 30 s) also stays under the common 60 s web-server and PHP-FPM wall limits, which PHP cannot see. |

## Decision log (Phase 3)

| # | Decision | Reason |
|---|---|---|
| P22 | Path rules live in settings as an ordered list `{path, mode}` (mode auto/manual/off, at most 200), matched with the Phase 1 `PathRules` syntax against the site path without language prefix (home path included, like `never_discover_paths`). | One syntax for every path setting; the Phase 4 Translation screen edits the list. |
| P23 | `off` redirects with **302**, not 301, and sets `X-Redirect-By: WP Site Translator`. Before redirecting, `Current` switches to the default language so the Router's `wp_redirect` filter does not add the prefix back (that loop was caught by `OffPagesTest`). | The mode can change; browsers cache 301s. |
| P24 | With `off_behavior = original` the target URL shows the original text with the default language's `lang`/`dir`, a canonical to the original URL, internal links still in the target language, nothing discovered or queued. Theme strings may still be in the target locale (the locale is chosen before the query runs). | Plan §9 "show original text" without a duplicate-content page; changing the locale after the query would need a second request. |
| P25 | On off pages the switcher prints nothing and no hreflang is printed, in both languages. | The other-language link would only redirect back (dead control) or show the same text. |
| P26 | Coverage of a post = its recorded strings plus all global strings, counted only once the post was seen; "translate this page now" sends the untranslated ones of that set. | Plan §4: global strings count toward every page's coverage. |
| P27 | Explicit requests (`Requests`) work in auto and manual mode, are refused on off pages, never send strings with a manual translation, and queue for the active provider (fallback included); without one they are refused with the provider's problem as the message. | Plan §9 table: editor MT works on manual pages; "off" is n/a. |
| P28 | New capability `wst_translate` (plan §11) for the pages/strings endpoints, the coverage and "translate now" controls; changing a page's mode additionally needs `edit_post` for that post; `POST /queue/run` stays `manage_options`. The role change is stored in `wp_user_roles` on activation and once per grant version (`wst_caps_version`). | Editors translate; only administrators touch settings and the queue runner. |
| P29 | The post panels show no "Open in translation editor" link until the editor exists (Phase 5). | No dead controls. |
| P30 | JS build: `npm run build` builds `assets-src/post-panel/index.js` to `build/` (committed, shipped); `eslint.config.cjs` extends the `@wordpress/scripts` config and lists the `@wordpress/*` runtime externals as core modules instead of installing them. | Externals are provided by WordPress; installing them only for lint would add unused dependencies. |

## Phase 3 acceptance (plan §16) — one line per criterion

| Criterion | Result | Proof |
|---|---|---|
| Matrix tests cover every mode × discover × enqueue combination in §9 | **PASS** | `ModeMatrixTest::test_mode_source_and_discover_setting` (16 cases: auto/manual/off × site/path/page source × discover on/off, through the Router and `Pipeline::start()`), plus `test_page_setting_inherit_falls_back_to_path_rules_then_site`, `test_auto_page_without_a_usable_provider_discovers_but_queues_nothing`; `ResolverTest`; `DiscoveryGateTest::test_settings_switch_discovery_off` |
| A manual page never reaches the queue (from visits) | **PASS** | `ModeMatrixTest::test_manual_page_never_reaches_the_queue_even_with_known_untranslated_strings`, manual rows of the matrix |
| Editor-initiated MT still works on manual pages | **PASS** | `RequestsTest::test_translate_page_now_works_on_a_manual_page_and_skips_translated_strings`, `test_editor_strings_go_first_and_never_resend_manual_translations`; `PagesAndStringsControllerTest::test_translate_page_now_on_a_manual_page_queues_and_runs_one_batch`; `PostPanelTest::test_classic_meta_box_shows_mode_effective_mode_coverage_and_action` |
| `off` behaviour | **PASS** | `OffPagesTest` (302 redirect, original + canonical, no hreflang/switcher), off rows of `ModeMatrixTest`; live on the dev site: `/bn/hello-world/?ref=1` → `302` to `/hello-world/?ref=1`; original mode → `lang="en-US"`, canonical to the original URL, no hreflang |
| Post-meta panel (block + classic), pages endpoints | **PASS** | `PostPanelTest`, `PagesAndStringsControllerTest`, `ResolverTest::test_meta_is_editable_over_rest_only_by_users_who_can_edit_the_post`; live in the block editor (WordPress 7.1.2, headless Chromium): panel "Translation (বাংলা)" showed "Applies now: Automatic (site mode)" and 4/100 translated; "Translate this page now" queued 96 strings, WP-Cron translated them through live TranslateX (97/100 after the save); switching to Manual showed "Save the post to apply…", after saving "Applies now: Manual only (this page)"; no console error from our script |

## Phase 2 acceptance (plan §16) — one line per criterion

| Criterion | Result | Proof |
|---|---|---|
| First task: real TranslateX responses captured as fixtures, `VERIFY` items resolved | **PASS** | `tests/fixtures/providers/translatex/*.json` (free key, 2026-10-06); replayed by `TranslateXTest::test_recorded_*`; facts in plan §7.2 |
| With a fake provider the limiter never exceeds the configured RPM under concurrent workers | **PASS** | `RateLimiterTest::test_concurrent_workers_never_exceed_the_rate` (4 forked processes), `test_no_sixty_second_window_exceeds_the_rpm` |
| `requests_per_minute = 0` sends batches without throttling yet still honours a 429 | **PASS** | `WorkerTest::test_zero_rpm_sends_batches_back_to_back`, `test_a_429_is_honoured_even_with_unlimited_rpm` (also asserts a 429 never switches to the fallback), `RateLimiterTest::test_a_429_block_is_honoured_even_when_unlimited` |
| The fallback switches and returns exactly as §8 | **PASS** | `WorkerTest::test_auth_error_pauses_the_primary_and_the_fallback_takes_over` (returns after `resume`), `test_quota_exceeded_switches_and_returns_next_period`, `test_exhausted_attempts_move_to_the_fallback_or_fail`, `test_auth_error_without_fallback_keeps_rows_waiting`; `QueueTest::test_fail_backs_off_then_hands_over_to_the_fallback` |
| TranslateX never stores an empty or length-mismatched result and never logs the API key | **PASS** | `TranslateXTest::test_empty_entry_is_a_per_item_failure_never_a_translation`, `test_recorded_empty_entry_is_not_a_translation`, `test_result_count_mismatch_fails_the_batch`, `test_an_empty_piece_fails_the_whole_long_text`, `test_server_and_network_errors_are_transient_and_redacted`, `test_recorded_invalid_key_response_is_an_auth_error`; `WorkerTest::test_result_count_mismatch_is_retried_one_item_per_request`; repo and git history scanned for the key: none |
| 429 / 5xx / auth / quota paths behave as §8 | **PASS** | `WorkerTest::test_a_429_is_honoured_even_with_unlimited_rpm`, `test_transient_errors_back_off_and_count_an_attempt`, `test_auth_error_*`, `test_quota_exceeded_switches_and_returns_next_period`, `test_monthly_budget_stops_the_provider`; adapter mapping in `TranslateXTest`, `MicrosoftTest`, `GeminiTest` |
| Manual translations are never overwritten | **PASS** | `WorkerTest::test_manual_translations_are_never_overwritten`, `QueueTest::test_manual_save_removes_queued_rows` |
| TranslateX passes `testConnection` and a live batch with a real key | **PASS** | Live run 2026-10-07 (scratch test, not committed): `testConnection` OK; 7/7 strings for `bn_BD` and for `ar` stored as machine translations, including an inline link sentence and a 2,699-byte text |
| Microsoft passes `testConnection` and a live batch | **PENDING (key)** | Needs `WST_AZURE_KEY` (+ `WST_AZURE_REGION` for a regional resource); keyless fixtures and mapping tests pass (`MicrosoftTest`) |
| Gemini passes `testConnection` and a live batch | **PENDING (key)** | Needs `WST_GEMINI_KEY`; keyless fixtures and mapping tests pass (`GeminiTest`) |

Scope items of §16 Phase 2 beyond the criteria: WP-CLI (`QueueCommand`, `ProviderCommand`; not unit-tested, no WP-CLI in the test runtime), cron (`SchedulerTest`), admin runner (`QueueControllerTest`), page-cache purge (`PurgerTest`), usage and monthly budget (`WorkerTest::test_monthly_budget_stops_the_provider`, `test_translates_queued_strings_and_counts_usage`), placeholder protection (`ProtectorTest`, `InlineTokensTest`, `RequestPlanTest`), visitor queueing (`PipelineTest::test_discoverable_page_queues_untranslated_strings_for_the_active_provider`), run timing (`WorkerTest::test_no_request_starts_after_the_budget_even_inside_a_batch`, `test_request_timeout_must_fit_max_execution_time`).

## Plan adjustments

D10–D14 approved by the owner and applied to `docs/WST-V1-PLAN.md` (§0, §7, §7.1, §7.2, §7.3, §8, §20).

## Remaining

- Phases 4–7 per plan §16.
- Phase 2 acceptance items that need keys: Microsoft and Gemini `testConnection` + live batch and their `all` fixture capture.
- Deferred by design (built together with their phases, no dead settings now): settings UI for `path_rules`, `off_behavior` and the Pages screen (Phase 4, the REST routes exist); "Open in translation editor" and "Allow MT suggestions in the editor on manual pages" (Phase 5); "prefix default language" option, user exclude selectors, floating switcher and switcher styles (Phase 4); admin notice for pipeline failures (Phase 4 Overview); TranslatePress coexistence guard (6b); AJAX/REST fragment translation (6c).

## Files changed (Phase 3)

`src/Modes/{Resolver,OffPages}.php`, `src/Access.php`, `src/Admin/PostPanel.php`, `src/Queue/{Requests,RequestRefused}.php`, `src/Rest/{PagesController,StringsController}.php`, `src/Settings.php` (`path_rules`, `off_behavior`), `src/Render/{Pipeline,PageContext,DiscoveryGate,HeadTags}.php`, `src/Switcher/Switcher.php`, `src/Storage/StringStore.php` (coverage, untranslated, manual/existing ids), `src/Cache/Purger.php` (`purgePosts`), `src/Plugin.php`, `assets-src/post-panel/index.js`, `build/post-panel.{js,asset.php}`, `package.json`, `eslint.config.cjs`, `.distignore`, tests under `tests/Integration/{Modes,Admin,Rest,Queue,Render}`.

## Files changed (Phase 2)

`src/Providers/*` (contract, errors, protection, `TextSplitter`, `RequestPlan`, `Limits`, `Secrets`, `ProviderState`, `ProviderRegistry`, `Selector`, `Http`, `LanguageList`, `TranslateX`, `Microsoft`, `Gemini`), `src/Queue/*` (`Queue`, `RateLimiter`, `Usage`, `Worker`, `WorkerReport`, `AutoQueue`, `Scheduler`), `src/Cache/Purger.php`, `src/Rest/QueueController.php`, `src/Cli/{QueueCommand,ProviderCommand,StringCommand}.php`, `src/Render/Pipeline.php`, `src/Html/InlineMarkup.php`, `src/Storage/StringStore.php`, `src/Settings.php`, `src/Plugin.php`, `bin/{setup-env.sh,capture-translatex-fixtures.php,capture-provider-fixtures.php}`, `tests/fixtures/providers/**`, `tests/Support/{FakeProvider,HttpStub}.php`, `tests/phpstan/cache-plugin-stubs.php`, `phpstan.neon.dist`, tests under `tests/{Unit,Integration}/{Providers,Queue,Cache,Rest}`.

## Files changed (Phase 1)

`wp-site-translator.php`, `src/Plugin.php`, `src/Settings.php`, `src/Database/Schema.php`, `src/Languages/*`, `src/Routing/*`, `src/Storage/StringStore.php`, `src/Log/Logger.php`, `src/Render/*`, `src/Switcher/Switcher.php`, `src/Cli/StringCommand.php`, `src/Html/{Extractor,Frame,Replacer,InlineMarkup}.php`, `phpstan.neon.dist`, `tests/phpstan/wp-cli-stubs.php`, tests listed above, `CLAUDE.md`, `HANDOVER.md`, `docs/WST-V1-PLAN.md` (D10–D14).

## Files changed (Phase 0)

`wp-site-translator.php`, `src/Autoloader.php`, `src/Config.php`, `src/Html/*.php`, `tests/**` (bootstraps, `wp-tests-config.php`, Unit, Integration, `fixtures/pages/*.html`), `bin/capture-fixtures.sh`, `bin/fetch-html5lib-tests.sh`, `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.wp-env.json`, `.distignore`, `.gitignore`, `.editorconfig`, `CLAUDE.md`, `HANDOVER.md`, `docs/WST-V1-PLAN.md` (copy of the approved plan).

## Validation status

- `vendor/bin/phpunit` (unit): 94 tests green.
- `vendor/bin/phpunit -c phpunit-integration.xml.dist`: 7,166 tests green on WP 7.1.2 (MariaDB 10.11, PHP 8.3), Phase 2 included.
- Phase 0 suite on WP 6.7.9 / 6.8.10 / 6.9.9: green except the 3 core-warning inputs (G2). The Phase 1 suite has not been re-run on those versions (CI matrix in Phase 7).
- `vendor/bin/phpcs`: clean.
- PHPStan level 8: **no errors** (container: `php .tools/phpstan.phar analyse --memory-limit=1G`) (PHPStan 2.3.0 official release phar + `szepeviktor/phpstan-wordpress` 2.0.4 / `php-stubs/wordpress-stubs` 7.1.2).
- `npx wp-scripts`: installed; no entry points yet (the Phase 1 switcher needs no JavaScript; the first build comes with Phase 4).

## Known issues

1. **PHPStan in the cloud container.** `composer install` cannot fetch `phpstan/phpstan` here: the package is only distributed as a GitHub API zipball (`api.github.com/repos/phpstan/phpstan/zipball/…`), which returns 403 for this session even after the network change, because GitHub API access is limited to repositories attached to the session. Workaround used: the official release asset `github.com/phpstan/phpstan/releases/download/2.3.0/phpstan.phar` (SHA-256 `64a1e773…ec83f`, identical to the phar in the official `2.3.0` git tag; the GPG signature could not be checked because the keyserver returned no key), plus a gitignored `composer.local.json` that declares `provide: phpstan/phpstan 2.3.0` so the WordPress stubs install. On a normal machine `composer install && composer analyse` works with the committed files.
2. **Network policy**: the WebFetch tool is still blocked for the docs hosts; `curl` works and was used.
3. WP 6.7–6.9 core lexer warning on input ending in `<!---` (G2). Harmless for real pages. A CI matrix on those versions must expect it.
4. Node in the container is 22.22.0; `@wordpress/scripts` 36 asks for ≥ 22.22.2 (npm warns only).
5. Open extractor items: user exclude selectors (§13, Phase 4); entity canonicalisation of inline originals (`&#8217;` vs `’` hash differently); a runtime self-check for the bookmark-span dependency (G1a) that fails loudly (Phase 7 hardening).
6. The dev site's WooCommerce (built from GitHub without its JS build) shows an empty shop loop in both languages; product pages render. Not a plugin issue.
7. Inline strings that fall back to segments (P20) translate each piece alone, so word order and short pieces suffer. Flag 1 marks them for the editor (Phase 5). The fallback waits one backoff (30 s) after the failed sentence attempt.
8. On the first target visit discovery stops at the per-page cap (100/hour by default); the rest of the page is discovered on later visits or by editor scans (Phase 5).
9. Dev site: WooCommerce from source fatals in wp-admin (`Could not find asset registry for wp-admin-scripts`, no JS build) and Elementor's unbuilt JS returns HTML (console "Unexpected token '<'"). Deactivate WooCommerce for admin checks. Not plugin issues. Start the server with `php -S 127.0.0.1:8899 -t site router.php` (the `-t` matters for static files).
10. Yoast SEO / Rank Math print their own canonical; the `off_behavior = original` canonical override uses core's `get_canonical_url` only. Check with those plugins in the Phase 7 compatibility pass.

## Dev environment setup (scripted)

`bin/setup-env.sh` rebuilds everything the tests need; it is idempotent.
- Installs and starts MariaDB if missing (root only; `WST_SKIP_DB=1` to use an existing server), creates `wst_tests` with user `wst`/`wst` (override with `WST_TEST_DB_*`).
- Runs `composer install`. Where `phpstan/phpstan` cannot be downloaded (cloud container: GitHub API zipballs are blocked), it writes a gitignored `composer.local.json` that `provide`s PHPStan and downloads the official release phar to `.tools/phpstan.phar`, checked against a pinned SHA-256.
- Fetches the html5lib tests; `--node` also runs `npm install`.
- Prints the test, PHPCS and PHPStan commands for the machine it ran on.

Environment **Setup script** field (Claude Code cloud environment settings; runs when a new session starts):
```bash
#!/bin/bash
set -euo pipefail
# WP Site Translator: database server and dev tools for tests.
if [ -x bin/setup-env.sh ]; then
  bin/setup-env.sh
else
  apt-get update -q
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q mariadb-server
fi
```
The repo script also starts MariaDB, which does not survive a container restart; run `bin/setup-env.sh` again at the start of a resumed session.

The fixture site (WordPress with theme unit test data, Elementor and WooCommerce from GitHub source, wp-cli via Composer, `php -S` with a router) is only needed to re-capture page fixtures; `bin/capture-fixtures.sh` lists the URLs.

## Exact next step

1. Owner: approve Phase 3 (acceptance lines above). Phase 2 still waits on the Microsoft and Gemini keys for its last two lines.
2. Phase 2 live checks when `WST_AZURE_KEY` (+ `WST_AZURE_REGION`) / `WST_GEMINI_KEY` reach a session: `php bin/capture-provider-fixtures.php microsoft all` / `gemini all`, live `testConnection` and worker batch (same scratch-test pattern as TranslateX), fixture-backed tests for any changed error class.
3. Phase 4 (Admin UI, plan §10, §16): one React app under `assets-src/admin/` built by `npm run build` next to `post-panel`; start with the Overview (setup checklist, queue panel via `POST /queue/run` polling, provider cards) and the `GET/POST /settings`, `GET /providers`, `POST /providers/{id}/test`, `GET /queue` routes it needs; then Languages, Translation (incl. `path_rules`, `off_behavior`, provider limits), Switcher, Pages (uses `GET /pages`, `POST /pages/mode`), Advanced, Health. Secrets never reach the browser.
