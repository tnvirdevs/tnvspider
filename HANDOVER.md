# HANDOVER

Spec: `docs/WST-V1-PLAN.md` (owner-approved, includes decision log D1–D9). Working rules: `CLAUDE.md`.

## Status

**Phase 0 — Setup & spikes: done.** PHPStan level 8 is clean and provider facts are recorded from official sources (Gemini per-model free-tier limits are unpublished and stay unconfirmed). `wp-env` is configured but not run here (no Docker daemon); the container uses MariaDB + `php -S`.

**Phase 1 — Core without providers: in progress.**
- Done: schema + activation. `src/Database/Schema.php` (all §4 tables via dbDelta, `wst_db_version` = 1, `install()` verifies every table afterwards and throws if one is missing, `maybeUpgrade()`), `src/Plugin.php` (activation hook + `plugins_loaded` upgrade check; the version option is autoloaded, so the check costs no query). Tests: `tests/Integration/Database/SchemaTest.php`, `tests/Integration/PluginTest.php`.
- Remaining in Phase 1: language registry, router, render pipeline, hreflang/`lang`/`dir`/locale switch, link rewriting, minimal switcher, §6A discovery gate and cache headers, WP-CLI `wp wst string set|get|list`.

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

- Phases 1–7 per plan §16.

## Files changed (Phase 0)

`wp-site-translator.php`, `src/Autoloader.php`, `src/Config.php`, `src/Html/*.php`, `tests/**` (bootstraps, `wp-tests-config.php`, Unit, Integration, `fixtures/pages/*.html`), `bin/capture-fixtures.sh`, `bin/fetch-html5lib-tests.sh`, `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.wp-env.json`, `.distignore`, `.gitignore`, `.editorconfig`, `CLAUDE.md`, `HANDOVER.md`, `docs/WST-V1-PLAN.md` (copy of the approved plan).

## Validation status

- `vendor/bin/phpunit` (unit): 15 tests green.
- `vendor/bin/phpunit -c phpunit-integration.xml.dist`: 6,877 tests green on WP 7.1.2 (MariaDB 10.11, PHP 8.3), including the Phase 1 schema/activation tests.
- Same suite on WP 6.7.9 / 6.8.10 / 6.9.9: green except the 3 core-warning inputs (G2).
- `vendor/bin/phpcs`: clean.
- PHPStan level 8: **no errors** (container: `php <scratchpad>/phpstan/phpstan.phar analyse --memory-limit=1G`) (PHPStan 2.3.0 official release phar + `szepeviktor/phpstan-wordpress` 2.0.4 / `php-stubs/wordpress-stubs` 7.1.2).
- `npx wp-scripts`: installed; no entry points yet (first one comes with the Phase 1 switcher).

## Known issues

1. **PHPStan in the cloud container.** `composer install` cannot fetch `phpstan/phpstan` here: the package is only distributed as a GitHub API zipball (`api.github.com/repos/phpstan/phpstan/zipball/…`), which returns 403 for this session even after the network change, because GitHub API access is limited to repositories attached to the session. Workaround used: the official release asset `github.com/phpstan/phpstan/releases/download/2.3.0/phpstan.phar` (SHA-256 `64a1e773…ec83f`, identical to the phar in the official `2.3.0` git tag; the GPG signature could not be checked because the keyserver returned no key), plus a gitignored `composer.local.json` that declares `provide: phpstan/phpstan 2.3.0` so the WordPress stubs install. On a normal machine `composer install && composer analyse` works with the committed files.
2. **Network policy**: the WebFetch tool is still blocked for the docs hosts; `curl` works and was used.
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

Phase 1, next unit: **language registry** (plan §5). `src/Languages/Registry.php` with a curated locale list (native name, English name, default slug, `dir`, provider code map incl. TranslateX `iw`/`tl`/`no`/`zh-CN`/`zh-TW` and Microsoft `zh-Hans`/`zh-Hant`), lookup by locale and by slug, and `src/Languages/Current.php` (the single active-language accessor). Unit tests for lookups, RTL detection and code mapping. Then the router (strip the prefix from `REQUEST_URI` before WordPress parses the request).
