# HANDOVER

Spec: `docs/WST-V1-PLAN.md` (owner-approved, includes decision log D1–D9). Working rules: `CLAUDE.md`.

## Status

**Phase 0 — Setup & spikes: done.** PHPStan level 8 is clean and provider facts are recorded from official sources (Gemini per-model free-tier limits are unpublished and stay unconfirmed). `wp-env` is configured but not run here (no Docker daemon); the container uses MariaDB + `php -S`.

**Phase 1 — Core without providers: done and approved by the owner.**

**Phase 2 — Providers, queue, limiter, usage: code complete; live provider checks pending (keys).**
- Done and tested (fake provider + recorded/stubbed HTTP): provider contract and typed errors, placeholder protection (never-translate terms) and tag placeholders, segmentation for plain-text providers, rate limiter (rpm / rpd / chars-per-minute, `0` = unlimited, `GET_LOCK`, 4-process concurrency test), monthly usage and budget (80 % warning, 100 % stop), queue and worker (priorities, backoff, `max_attempts`, fallback switch and return, 429 always honoured, manual translations never overwritten), visitor auto-queue (priority 5) with the §6A cache rules, WP-Cron one-minute event that exists only while work is queued, `POST /wst/v1/queue/run` (one batch, `manage_options` + REST nonce), WP-CLI `wp wst queue run|status|retry-failed|clear` and `wp wst provider test <id>` (lifts a pause on success), page-cache purge (LiteSpeed `litespeed_purge_url`, WP Rocket `rocket_clean_files()`, generic `wst_purge_url`), adapters for TranslateX, Microsoft and Gemini.
- Recorded keyless fixtures (`bin/capture-provider-fixtures.php`, `bin/capture-translatex-fixtures.php`): TranslateX missing/invalid key → HTTP 400 `{"err":"invalid api key"}`; Microsoft missing/invalid key → HTTP 401 code **401001** (plan listed 401000; both are mapped by the 401xxx class), language list (138 languages incl. `bn` and `ar`); Gemini invalid key → HTTP 400 `INVALID_ARGUMENT` with `details[].reason = API_KEY_INVALID`, missing key → HTTP 403 `PERMISSION_DENIED`.
- Blocked on keys: `WST_TRANSLATEX_KEY`, `WST_AZURE_KEY` (+ `WST_AZURE_REGION` for a regional resource) and `WST_GEMINI_KEY` are **not set in this session** (environment variables reach new sessions only). Still open for TranslateX: error format of the remaining cases, per-request item/character limits, rate-limit behaviour, whether `bn`/`ar` are in this key's plan.

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

## Plan adjustments

D10–D14 approved by the owner and applied to `docs/WST-V1-PLAN.md` (§0, §7, §7.1, §7.2, §7.3, §8, §20).

## Remaining

- Phases 2–7 per plan §16.
- Phase 2 acceptance items that need keys: each real provider passes `wp wst provider test <id>` and a live batch; TranslateX `all` + `rate-limit` fixture capture; Microsoft and Gemini `all` capture.
- Deferred by design (built together with their phases, no dead settings now): page and path modes and `off` behaviour (Phase 3); "prefix default language" option, user exclude selectors, floating switcher and switcher styles (Phase 4); admin notice for pipeline failures (Phase 4 Overview); TranslatePress coexistence guard (6b); AJAX/REST fragment translation (6c).

## Files changed (Phase 2)

`src/Providers/*` (contract, errors, protection, `RequestPlan`, `Limits`, `Secrets`, `ProviderState`, `ProviderRegistry`, `Selector`, `Http`, `LanguageList`, `TranslateX`, `Microsoft`, `Gemini`), `src/Queue/*` (`Queue`, `RateLimiter`, `Usage`, `Worker`, `WorkerReport`, `AutoQueue`, `Scheduler`), `src/Cache/Purger.php`, `src/Rest/QueueController.php`, `src/Cli/{QueueCommand,ProviderCommand,StringCommand}.php`, `src/Render/Pipeline.php`, `src/Html/InlineMarkup.php`, `src/Storage/StringStore.php`, `src/Settings.php`, `src/Plugin.php`, `bin/{setup-env.sh,capture-translatex-fixtures.php,capture-provider-fixtures.php}`, `tests/fixtures/providers/**`, `tests/Support/{FakeProvider,HttpStub}.php`, `tests/phpstan/cache-plugin-stubs.php`, `phpstan.neon.dist`, tests under `tests/{Unit,Integration}/{Providers,Queue,Cache,Rest}`.

## Files changed (Phase 1)

`wp-site-translator.php`, `src/Plugin.php`, `src/Settings.php`, `src/Database/Schema.php`, `src/Languages/*`, `src/Routing/*`, `src/Storage/StringStore.php`, `src/Log/Logger.php`, `src/Render/*`, `src/Switcher/Switcher.php`, `src/Cli/StringCommand.php`, `src/Html/{Extractor,Frame,Replacer,InlineMarkup}.php`, `phpstan.neon.dist`, `tests/phpstan/wp-cli-stubs.php`, tests listed above, `CLAUDE.md`, `HANDOVER.md`, `docs/WST-V1-PLAN.md` (D10–D14).

## Files changed (Phase 0)

`wp-site-translator.php`, `src/Autoloader.php`, `src/Config.php`, `src/Html/*.php`, `tests/**` (bootstraps, `wp-tests-config.php`, Unit, Integration, `fixtures/pages/*.html`), `bin/capture-fixtures.sh`, `bin/fetch-html5lib-tests.sh`, `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.wp-env.json`, `.distignore`, `.gitignore`, `.editorconfig`, `CLAUDE.md`, `HANDOVER.md`, `docs/WST-V1-PLAN.md` (copy of the approved plan).

## Validation status

- `vendor/bin/phpunit` (unit): 80 tests green.
- `vendor/bin/phpunit -c phpunit-integration.xml.dist`: 7,107 tests green on WP 7.1.2 (MariaDB 10.11, PHP 8.3), Phase 2 included.
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
7. On the first target visit discovery stops at the per-page cap (100/hour by default); the rest of the page is discovered on later visits or by editor scans (Phase 5).

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

Phase 2 live checks, in a **new session** where the keys are present (environment variables are read at session start):
1. `bin/setup-env.sh`, then `php bin/capture-translatex-fixtures.php all` and `php bin/capture-translatex-fixtures.php rate-limit`. Check no fixture contains the key. Record in this file: status codes and `err` texts per case, item/character limits (batch-100/101/500, chars-5000/20000/60000-total), whether `bn` and `ar` are in `/supported-languages` for the key's plan. If a case changes the error class, add a fixture-backed test to `tests/Integration/Providers/TranslateXTest.php`; if the limits differ from 100 items / 10,000 characters, change `TranslateX::MAX_ITEMS`/`MAX_CHARS`.
2. With `WST_AZURE_KEY` (+ `WST_AZURE_REGION`): `php bin/capture-provider-fixtures.php microsoft all`; with `WST_GEMINI_KEY`: `php bin/capture-provider-fixtures.php gemini all` (confirms the `responseFormat` request shape).
3. On a WordPress install with the plugin active: `wp wst provider test translatex|microsoft|gemini`, then queue a page (visit `/bn/…` logged out) and `wp wst queue run`; record the results as Phase 2 acceptance lines (one per §16 criterion) and ask the owner to approve Phase 2.
