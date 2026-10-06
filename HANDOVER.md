# HANDOVER

Spec: `docs/WST-V1-PLAN.md` (owner-approved, includes decision log D1–D9). Working rules: `CLAUDE.md`.

## Status

**Phase 0 — Setup & spikes: done, with three open items** (see *Known issues*):
- PHPStan is configured but could not be installed or run in the cloud container.
- Provider facts are recorded from official docs for Microsoft only; TranslateX and Gemini docs were unreachable.
- `wp-env` is configured but not run here (no Docker daemon); the container used MariaDB + `php -S`.

Phase 1 has not started.

## Completed (Phase 0)

- Plugin scaffold at repo root: `wp-site-translator.php`, `src/Autoloader.php` (PSR-4, no Composer at runtime), `src/Config.php` (all names), `.distignore`, `.gitignore`, `.editorconfig`.
- Dev tooling: `composer.json` (+ lock) with PHPUnit 9.6, wp-phpunit 7.1, WordPress core 7.1 (tests only), WPCS 3 + PHPCompatibilityWP, PHPStan 2 + phpstan-wordpress; `phpunit.xml.dist` (unit), `phpunit-integration.xml.dist` (integration), `phpcs.xml.dist`, `phpstan.neon.dist`; `package.json` with `@wordpress/scripts` 36 and `@wordpress/env` 11; `.wp-env.json` (dev env adds WooCommerce, Elementor, WordPress Importer).
- HTML spike, which is also the Phase 1 extraction core: `src/Html/{Lexer,Extractor,Frame,Segment,Replacer,Text}.php`.
- Fixtures: 30 saved pages in `tests/fixtures/pages/` produced by `bin/capture-fixtures.sh` from a local site with Theme Unit Test data, block test data, Elementor (2 pages incl. Bengali) and WooCommerce sample products, under Twenty Twenty-One / Twenty Twenty-Five / Twenty Twenty. html5lib tokenizer inputs via `bin/fetch-html5lib-tests.sh` (pinned commit, gitignored).
- Tests: `tests/Unit/Html/TextTest.php`, `tests/Integration/Html/{LexerTest,ExtractorTest,RoundTripTest}.php`.

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

## Provider facts

**Microsoft Translator** (official docs, MicrosoftDocs/azure-ai-docs on GitHub, `service-limits.md` dated 2026-08-11, `status-response-codes.md`, `v3/translate.md`):
- `POST https://api.cognitive.microsofttranslator.com/translate?api-version=3.0&from=…&to=…`. Headers `Ocp-Apim-Subscription-Key`, `Ocp-Apim-Subscription-Region` (regional resources), `Content-Type: application/json; charset=UTF-8`. Body `[{"Text": …}]`.
- Per request: **≤ 1,000 array elements, ≤ 50,000 characters in total** (per element ≤ 50,000).
- Throughput is limited by **characters per hour, spread evenly**: F0 (free) 2M/hour, which is about **33,300 characters/minute** (sliding window); S1 40M/hour. No limit on concurrent requests. Monthly free allowance: 2M characters (pricing page, unverified).
- `textType=html` requires well-formed, complete elements; supports `class="notranslate"` and `<mstrans:dictionary translation="…">`.
- Errors: 400xxx bad input (e.g. 400050 text too long, 400072 too many elements, 400077 request too large); 401000 bad credentials; **403001 = free quota exceeded** (403000 = operation not allowed); 408001 retry in minutes; 429000–429002 rate limited; 500000/503000 transient. Latency up to 15 s.

**TranslateX**: `translatex.com` is blocked by this environment's network policy. The plan §7.2 contract (from the bridge plugin) stands; every `VERIFY` there is still open.

**Gemini**: `ai.google.dev` is blocked. Third-party summaries (unverified, not to be hardcoded) say that since April 2026 the free tier covers Flash / Flash-Lite only (Pro is paid-only), at about 10 RPM / 250 RPD for Flash and 30 RPM / 1,000 RPD for Flash-Lite, with limits per Google Cloud project shown in AI Studio. Still `VERIFY`.

## Proposed plan adjustments (need owner OK; not applied to the plan)

1. **Characters-per-minute limit** (plan §8): add `chars_per_minute` (0 = unlimited) next to RPM/RPD. Microsoft throttles by characters (F0 ≈ 33,300/min), not requests, and Gemini by tokens per minute. Request counting alone would hit 429s on large batches.
2. **Error mapping per provider** (plan §7, §8): Microsoft returns **403001 for an exhausted free quota**. That must map to `QuotaExceeded` (stop until next period, try the fallback), not `AuthError`. Map by provider-specific code, not only by HTTP status.
3. Microsoft defaults: `max_items_per_request` ≤ 1,000 and `max_chars_per_request` ≤ 50,000 are hard caps.
4. Optional: for Microsoft, never-translate terms could use `<mstrans:dictionary>` / `class="notranslate"` instead of opaque tokens. Keep the shared token approach (plan §7) as the default; decide in Phase 2.

## Remaining

- Phase 0 leftovers: run `composer analyse` (PHPStan) where packages can be installed; TranslateX and Gemini facts from official docs.
- Phases 1–7 per plan §16.

## Files changed (Phase 0)

`wp-site-translator.php`, `src/Autoloader.php`, `src/Config.php`, `src/Html/*.php`, `tests/**` (bootstraps, `wp-tests-config.php`, Unit, Integration, `fixtures/pages/*.html`), `bin/capture-fixtures.sh`, `bin/fetch-html5lib-tests.sh`, `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.wp-env.json`, `.distignore`, `.gitignore`, `.editorconfig`, `CLAUDE.md`, `HANDOVER.md`, `docs/WST-V1-PLAN.md` (copy of the approved plan).

## Validation status

- `vendor/bin/phpunit` (unit): 15 tests green.
- `vendor/bin/phpunit -c phpunit-integration.xml.dist`: 6,856 tests green on WP 7.1.2 (MariaDB 10.11, PHP 8.3).
- Same suite on WP 6.7.9 / 6.8.10 / 6.9.9: green except the 3 core-warning inputs (G2).
- `vendor/bin/phpcs`: clean.
- PHPStan: **not run** (see Known issues).
- `npx wp-scripts`: installed; no entry points yet (first one comes with the Phase 1 switcher).

## Known issues

1. **PHPStan not run.** `phpstan/phpstan` is distributed only as a GitHub zipball, which this cloud environment cannot download (403). The committed `composer.json`/`composer.lock` are complete; run `composer install && composer analyse` on a normal machine. In the container, dev tools were installed from a gitignored `composer.local.json` without the three PHPStan packages.
2. **Network policy** blocks `wordpress.org`, `downloads.wordpress.org`, `translatex.com`, `learn.microsoft.com`, `ai.google.dev`, `docs.azure.cn`. The owner can allow them under the environment's Network access settings (Custom → Allowed domains), or supply the docs.
3. WP 6.7–6.9 core lexer warning on input ending in `<!---` (G2). Harmless for real pages. A CI matrix on those versions must expect it.
4. Node in the container is 22.22.0; `@wordpress/scripts` 36 asks for ≥ 22.22.2 (npm warns only).
5. Extractor limits to address in Phase 1: user exclude selectors (§13); entity canonicalisation of inline originals (`&#8217;` vs `’` currently hash differently); `<a>` text and `href` rewriting; a runtime self-check for the bookmark-span dependency (G1a) that fails loudly.

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

Phase 1, first unit: **schema + activation**. Create `src/Database/Schema.php` (dbDelta for `wst_strings`, `wst_translations`, `wst_occurrences`, `wst_pages`, `wst_queue`, `wst_usage`, `wst_log` exactly as plan §4, versioned by `wst_db_version`), a `src/Plugin.php` bootstrap that hooks activation and an upgrade check, and an integration test that installs the schema and checks the columns and unique keys. Then the language registry (§5), router, render pipeline (using `WST\Html\Extractor`/`Replacer`), the §6A discovery gate, and the WP-CLI `wp wst string set|get|list`.
