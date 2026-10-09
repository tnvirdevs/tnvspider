# WP Site Translator — V1 Build Plan

> Working name: **WP Site Translator** · slug `wp-site-translator` · PHP namespace `WST\` · prefix `wst_` · REST `wst/v1`.
> The name is a placeholder. Keep every name in one constants file so a rename is a single edit.

Audience: an AI coding agent working inside the plugin repository. Read this whole file first, then follow **§0**.

---

## 0. How to use this plan

1. Read this file once. Do not re-read it every turn; keep decisions in `HANDOVER.md`.
2. Create two repo-root files before writing code:
   - `CLAUDE.md`: short distillation of §1, §3, §15 plus the owner's working rules (surgical edits, no speculative features or dead UI controls, no silent catches, never commit unless asked, focused tests during work and one full run per batch).
   - `HANDOVER.md`: completed / remaining / files changed / validation status / known issues / exact next step. Update at the end of every phase and before context runs out.
   - Commits (D14): commit and push at the end of each phase to the working branch only. No force-pushes, no merges, no commits mid-phase unless the owner asks.
3. Work phase by phase (§16). Each phase has acceptance criteria. Do not start the next phase until they pass.
4. **Decision gates** are marked `[GATE]`. At a gate, state the decision and the reason in one short paragraph in `HANDOVER.md`, then continue. Ask the owner only when the choice changes architecture, data, or security.
5. Anything marked `VERIFY` is a fact I could not confirm (API limits, model names). Check the official docs before hardcoding. Never guess an API shape.
6. **Clean-room rule.** The TranslatePress 3.3.7 source (uploaded by the owner) is a *behavioural reference only*. Do not copy its code, markup, CSS, JS, icons or copy text. We build our own architecture and our own UI.
7. **Reference material.** The TranslatePress source and the TranslateX bridge plugin may not be present in the repo. §2 and §7.2 record everything Phases 0–5 need. The TranslatePress source must be supplied again before Phase 6b (importer).

---

## 1. Product summary

A WordPress plugin that translates a whole site between **two languages** (default language ⇄ one target language, any direction: en→bn, bn→en, en→ar, ar→en, …), with machine translation from pluggable providers, a rate-limited queue, a conflict-proof editor, and fine control over what is translated automatically vs manually.

### V1 scope (confirmed by owner)

| # | Capability |
|---|---|
| 1 | Two-language support (default + one target). Direction is free: the default language is whatever the site is written in. |
| 2 | Providers: **TranslateX**, **Microsoft Translator**, **Gemini** (free tier). Pluggable architecture; user chooses provider and brings own API key. |
| 3 | Request throttling: user-set **requests per minute per provider (`0` = unlimited)**, plus batch/char limits, all enforced by a **queue**. |
| 4 | Admin settings with the same *capability groups* as TranslatePress (General/Languages, Language Switcher, Automatic Translation, Translate Site, Advanced) but a **better, original UI**. |
| 5 | **Site-wide mode**: automatic or manual translation for the entire site. |
| 6 | **Per-page mode** in the page/post settings: inherit / automatic / manual / off. |
| 7 | Settings option to pick pages that must **not** be auto-translated (so they are translated manually). |
| 8 | Translation editor (our own design, see §11). |
| 9 | Language switcher: menu item, floating switcher, shortcode, block. |
| 10 | **"Never translate" terms list** (brand names such as Purfello). §13A.1 |
| 11 | **Monthly character budget** per provider with warnings and hard stop. §8 |
| 12 | **Fallback provider** used on quota/auth failure. §8 |
| 13 | **CSV import / export** of translations. §13A.3 |
| 14 | **TranslatePress importer** + coexistence guard. §13A.4 |
| 15 | **Dynamic content**: AJAX/REST HTML fragments, WooCommerce fragments, client-side lookup for JS-inserted text, "dynamic scan" discovery. §13A.5 |
| 16 | **Digit conversion** to Bengali / Arabic-Indic / Persian numerals. §13A.6 |
| 17 | **Browser-language suggestion bar** (optional redirect) with cookie. §13A.7 |
| 18 | **Sitemap language alternates**. §13A.8 |

### Explicitly out of V1
More than two languages · translated slugs · image/media replacement · gettext/.po handling · translated emails · search-in-target-language · multisite. (See §18.)

Do **not** add UI controls for out-of-scope features (no teasers, no disabled "coming soon" toggles).

---

## 2. What was learned from TranslatePress 3.3.7 (study notes)

File references are under `translatepress-multilingual/`.

**Architecture**
- Front-end pages are captured with an output buffer (`includes/class-translation-render.php::translate_page`), parsed into a DOM with a bundled `simple_html_dom`, then text nodes and a fixed list of attribute "accessors" (`get_node_accessors()`: text, block, image src, submit value, placeholder, title, href, aria-label, video/audio/picture sources) are looked up and replaced. Strings can be merged into "translation blocks" (innerHTML) for mixed inline content.
- Storage: one dictionary table **per language pair** (`{prefix}trp_dictionary_{default}_{target}`: `id, original longtext, translated longtext, status, block_type, original_id`), plus `trp_original_strings`, gettext tables, and a machine-translation log/lock table (`includes/queries/class-query.php`). Status: `0` not translated, `1` machine, `2` human-reviewed.
- Machine translation: abstract `TRP_Machine_Translator` with engines extending it (Google v2, an internal "mtapi"). It has chunking, per-string locks to stop concurrent requests translating the same string, crawler blocking, a character limit, and a log.
- Editor: parent admin screen + **iframe of the real front-end page** loaded as `?trp-edit-translation=preview` with `trp-iframe-preview-script.js` injected. The iframe therefore runs the site's full front-end (theme + every plugin's JS/CSS).
- Exclusions: `data-no-translation` attribute, CSS-selector lists, "include/exclude paths" with wildcard and `{{home}}`, word lists excluded from auto-translation, `manual_translation_only` (stops front-end string saving and MT outside the editor).
- Advanced tab: ~30 options registered through a filter (`includes/advanced-settings/*.php`).
- Switcher: shortcode, menu item, floater; display styles (full names, short names, flags, flags+names, only flags); floater position + light/dark.
- Free build: one extra language only (matches our two-language V1).

**Pain points we design against**
1. Editor loads the full front-end in an iframe → plugin/theme JS conflicts → editor fails to load. *Our fix: §11.*
2. MT is called synchronously while rendering a page → slow first view, no real rate control. *Our fix: queue, §8.*
3. DOM parse + re-serialise can alter markup (they ship a `fix_broken_html` option). *Our fix: offset-based replacement, §6.*
4. A table per language pair multiplies tables and migrations. *Our fix: one translations table with a `lang` column, §4.*
5. 26k lines across many global-filter hooks. *Our fix: small PSR-4 classes, explicit interfaces.*

**Behaviours worth matching**: `data-no-translation` honoured (migration-friendly), `{{home}}` path token, wildcard paths, crawler blocking, hreflang + `x-default`, language switcher styles.

---

## 3. Technical decisions

| Topic | Decision |
|---|---|
| PHP / WP | PHP ≥ 8.0, **WP ≥ 6.7** (needed for the HTML API text-node methods, §6; if the Phase 0 spike falls back to our own tokenizer the minimum may drop back to 6.4). No Composer runtime dependency: ship a tiny PSR-4 autoloader. |
| Repo layout / release | Plugin lives at the **repo root**; the release zip is built with a `.distignore` file (excludes tests, `assets-src`, dev config, docs). |
| Admin UI | React via `@wordpress/scripts` + `@wordpress/components`, talking to REST `wst/v1`. Built assets committed in `build/`. `[GATE]` explain the build tooling choice once in `HANDOVER.md`. |
| Front-end JS | Vanilla, < 3 KB for the switcher. No jQuery. |
| HTML handling | Offset-based replacement (§6). `[GATE]` Phase 0 spike decides WordPress HTML API vs own tokenizer using the round-trip test; never a DOM re-serialisation. |
| Storage | Custom tables (§4) + one options array + post meta. |
| Queue | Own table + WP-Cron + browser-driven runner + WP-CLI. **No Action Scheduler dependency.** |
| Secrets | API keys in a separate non-autoloaded option; optional constants in `wp-config.php` override (`WST_AZURE_KEY`, …). Never returned by REST (only `has_key: true` and last 4 characters), never printed into JS. |
| i18n of our own UI | Text domain `wp-site-translator`, English source. The admin UI must work in RTL. |
| Compatibility | Pretty permalinks required (admin notice otherwise). |

---

## 4. Data model

Create with `dbDelta`, versioned (`wst_db_version`). Use `$wpdb->prefix`. All queries via `$wpdb->prepare`.

```sql
-- unique original strings (language-agnostic)
wst_strings (
  id          BIGINT UNSIGNED PK AUTO_INCREMENT,
  hash        CHAR(32) NOT NULL,           -- md5(normalized original); kind is NOT part of the hash,
                                           -- so "Search" as text and as a placeholder share one translation
  kind        VARCHAR(24) NOT NULL,        -- first-seen kind: text | inline | attr | title | meta | dynamic
  original    LONGTEXT NOT NULL,
  char_count  INT UNSIGNED NOT NULL,
  is_global   TINYINT(1) NOT NULL DEFAULT 0,  -- seen on >= 20 pages (header/footer/menu strings)
  created_at  DATETIME NOT NULL,
  UNIQUE KEY hash (hash)
);

-- one row per (string, language)
wst_translations (
  id          BIGINT UNSIGNED PK AUTO_INCREMENT,
  string_id   BIGINT UNSIGNED NOT NULL,
  lang        VARCHAR(12) NOT NULL,        -- WP locale, e.g. bn_BD
  translated  LONGTEXT NOT NULL,
  status      TINYINT NOT NULL,            -- 1 machine, 2 manual (protected)
  provider    VARCHAR(24) NULL,            -- who produced status 1
  flags       SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- bitmask: 1 segmented (word order may be off),
                                                     -- 2 protected term altered, 4 tags repaired
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL,
  UNIQUE KEY string_lang (string_id, lang)
);

-- where a string was seen (powers per-page editor lists and coverage)
wst_occurrences (
  string_id   BIGINT UNSIGNED NOT NULL,
  page_key    CHAR(32) NOT NULL,           -- md5 of the page PATH WITHOUT the language prefix,
                                           -- normalised (leading slash, no trailing slash, no query string)
  post_id     BIGINT UNSIGNED NULL,
  last_seen   DATETIME NOT NULL,
  PRIMARY KEY (string_id, page_key),
  KEY page_key (page_key), KEY post_id (post_id)
);

-- one row per known page (lets us rebuild URLs for cache purging and the Pages screen)
wst_pages (
  page_key  CHAR(32) PRIMARY KEY,
  path      VARCHAR(255) NOT NULL,         -- same normalisation as page_key
  post_id   BIGINT UNSIGNED NULL,
  last_seen DATETIME NOT NULL,
  last_scan DATETIME NULL,
  KEY post_id (post_id)
);

wst_queue (
  id              BIGINT UNSIGNED PK AUTO_INCREMENT,
  string_id       BIGINT UNSIGNED NOT NULL,
  lang            VARCHAR(12) NOT NULL,
  provider        VARCHAR(24) NOT NULL,
  priority        TINYINT NOT NULL DEFAULT 5,   -- 1 editor-requested … 9 background
  state           VARCHAR(12) NOT NULL,         -- pending | processing | failed
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL,
  locked_until    DATETIME NULL,
  last_error      TEXT NULL,
  created_at      DATETIME NOT NULL,
  UNIQUE KEY string_lang (string_id, lang),
  KEY pick (state, next_attempt_at, priority)
);

wst_usage (          -- per provider per month
  provider VARCHAR(24) NOT NULL, period CHAR(7) NOT NULL,   -- YYYY-MM
  chars BIGINT UNSIGNED NOT NULL DEFAULT 0, requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (provider, period)
);

wst_log (            -- ring buffer, keep latest 1000 rows
  id BIGINT UNSIGNED PK AUTO_INCREMENT, created_at DATETIME NOT NULL,
  level VARCHAR(8) NOT NULL, source VARCHAR(24) NOT NULL, message TEXT NOT NULL, context LONGTEXT NULL
);
```

Notes
- Schema supports N languages (`lang` column) even though V1 UI exposes two.
- Same original text ⇒ one row, one translation, site-wide. Per-page different translations of the same string are a known V1 limitation (backlog).
- `wst_occurrences` can grow (header/footer strings × every page). Once a string has been seen on ≥ 20 pages set `wst_strings.is_global = 1` and stop recording further occurrences for it; global strings count toward every page's coverage.
- **Orphan cleanup** (Advanced → Data): remove strings that have no occurrence, are not global, and have no manual translation, older than N days. Always show a dry-run count first. Manual translations (status 2) are never deleted by cleanup.
- Post meta: `_wst_mode` = `inherit|auto|manual|off` (default `inherit`).
- Options: `wst_settings` (array, schema-versioned), `wst_secrets` (autoload = no).
- `uninstall.php` deletes data **only** if the "Delete all data on uninstall" setting is on.

---

## 5. Languages and URL routing

- Language registry keyed by WP locale (`bn_BD`, `en_US`, `ar`, …) with: native name, English name, slug default (`bn`), `dir` (ltr/rtl), flag, and a **provider code map** (e.g. `zh_CN → zh-Hans` for Microsoft). Ship a curated list; the plugin must work for any locale the owner picks.
- URL scheme: default language has **no prefix** (option to add one). Target language uses `/{slug}/…`.
- Resolve the language **before WordPress parses the request**: strip the prefix from `REQUEST_URI`, store the active language in one global accessor `WST\Languages\Current`, let WP route as normal. Do not rely on rewrite rules alone.
- Always exempt: `wp-admin`, `wp-login.php`, `wp-json`, `admin-ajax.php`, cron, XML-RPC, `robots.txt`, sitemaps.
- AJAX/REST requests inherit language from an explicit param, else the referer's prefix.
- Guard `redirect_canonical` and `wp_redirect` so prefixes are kept; filter `home_url` for target-language requests where safe; and rewrite internal `href`/form `action` in the output (§6) so hard-coded links stay in the language.
- `<html lang="…" dir="…">`: set from the target language. Also switch the WP **locale** early for target-language requests so `is_rtl()` is true for ar/he/fa/ur and themes load their RTL stylesheets.
- SEO tags in `<head>` (V1): `hreflang` alternates for both languages + `x-default`, canonical pointing to the language-specific URL, `og:locale`, translated `<title>` and meta description (they come from the normal extraction). Pages whose mode is `off` get **no** hreflang link to the other language (and no alternate entry in sitemaps). Options: remove region from `hreflang`/`lang` (`bn` vs `bn-BD`).
- Default-language requests must cost almost nothing: language check, no buffering, no DB.
- **TranslatePress coexistence**: while TranslatePress is active, our router and render pipeline stay **off** (admin notice explains why); admin screens, importer and queue still work. See §13A.4.

---

## 6. Render pipeline (front-end)

Runs only for **target-language HTML responses** and for authorised **scan requests** (§11).

1. `template_redirect` priority 1: start an output buffer only if the response is front-end HTML (skip feeds, REST, JSON, XML, admin, login, cron, robots, sitemaps).
2. **Extract** translatable strings using the approach chosen at the Phase 0 gate: WordPress's HTML API if the spike proves it leaves untouched bytes identical, otherwise our own streaming tokenizer that records byte offsets. Either way, **do not re-serialise the document through a DOM**.
   - Skip content of `script, style, noscript, template, svg, code, pre, textarea, iframe`, HTML comments, and anything inside `translate="no"`, `.notranslate`, `[data-wst-no-translate]`, `[data-no-translation]` (TranslatePress-compatible), or matching the user's exclude selectors (§13).
   - **Text nodes** with at least one letter. Skip strings that are only digits/punctuation, URLs, emails, or look like code/JSON (port the *idea* of a `should_translate_string` filter, not the code).
   - **Inline blocks**: an element whose **entire subtree at any depth** consists only of text plus inline tags (`a abbr b bdi br cite em i mark q s small span strong sub sup u wbr`, so `<strong><a>…</a></strong>` nesting counts) and that has ≥ 1 text node and ≥ 1 inline tag becomes **one** `inline` string (inner HTML), so word order can change. Everything else is per text node.
   - **Attributes**: `placeholder, title, alt, aria-label, aria-placeholder`, `value` of submit/button/reset inputs, `<title>`, and `content` of `meta[name=description]`, `og:title`, `og:description`, `twitter:title`, `twitter:description`.
   - Store originals as decoded UTF-8 with collapsed internal whitespace; remember and restore leading/trailing whitespace; re-encode `& < >` on replacement.
3. **Lookup**: hash every string, fetch all translations in **one** batched query (+ object cache if available). Request-level static cache.
4. **Misses**: only if the discovery gate in §6A allows it, `INSERT IGNORE` into `wst_strings`, record occurrence, and—depending on the resolved mode (§9)—enqueue into `wst_queue`. **Rendering never calls a provider.** Untranslated strings render in the original language.
5. **Replace** by offsets. For `inline` strings, validate that the translated HTML has the *same tag sequence and attributes* as the original; if not, discard it and fall back to per-segment translation. Run translated HTML through `wp_kses` with an inline-tag allowlist (also applied to manual edits).
5a. If digit conversion is enabled (§13A.6), convert ASCII digits in the replaced text.
6. Rewrite internal links/form actions to the active language; set `lang`/`dir`; inject hreflang/canonical; inject the floating switcher if enabled.
7. **Failure policy**: wrap the pipeline in a top-level guard that, on any throwable, returns the **untouched original HTML** so the front-end never breaks, writes to `wst_log`, shows an admin notice on the Overview, and rethrows when `WP_DEBUG` is on. No empty catch blocks anywhere.
8. Bots: when "block crawlers" is on (default), crawler requests may *read* translations but never create or enqueue strings (part of the §6A gate).

Acceptance test (Phase 0 spike): for ≥ 20 saved real pages (Elementor, Gutenberg, WooCommerce, classic), running the pipeline with an **empty** translation map yields output byte-identical to the input except for our intentional additions (`lang/dir`, hreflang, link prefixes, switcher).

---

## 6A. Discovery rules and cache behaviour

**Who may create strings from a visit.** Every condition must hold, otherwise the page is rendered with existing translations only:
- Target-language `GET` request, HTML response, status 200; visitor **logged out**; not a bot; "discover on visits" is on and the resolved mode is `auto` (§9).
- URL has **no query string** (default allowance for pagination `paged` only; configurable).
- Not a search-results page, 404, feed, password-protected page, preview, or customizer view.
- Not WooCommerce **cart, checkout, my-account (all endpoints) or order-received** (detect by page ids and endpoint conditionals; filterable), and not matching the user's "never discover" path list.
- **Caps**: new strings per page per hour (default 100) and site-wide per hour (default 1,000); when exceeded, skip discovery and log once. Skip strings longer than a limit (default 2,000 characters) or failing the translatable-string filter.
- Why: stops cost abuse (`/bn/?s=<random>` would otherwise create a billable string per request) and keeps personal data (names, orders, addresses) from being sent to outside providers, including free-plan providers that retain submitted text.
- Reading is never restricted: existing translations are applied to everyone on every page.

**Admin scans** (§11) are exempt from the caps. Scanning a page on the excluded list (cart/checkout/account/search) first shows a confirmation that the page may contain personal data, and strings found there are recorded **but not auto-queued**; the admin queues them explicitly.

**Page-cache behaviour**
- A page that still contains strings **queued for automatic translation** is served as not cacheable (`DONOTCACHEPAGE` constant plus `Cache-Control: no-cache, must-revalidate, max-age=0`, and an `X-WST-Pending: n` debug header). Strings that will never be auto-translated (manual mode, failed, over budget, provider paused) must **not** make the page uncacheable. After 24 h of still-pending strings treat the page as cacheable again.
- When a page's queued strings finish (or a manual save changes visible text), purge that page's cached URL (rebuilt from `wst_pages.path` + language prefix) via the LiteSpeed Cache and WP Rocket purge hooks, plus a generic `wst_purge_url` action other cache plugins can hook. `VERIFY` current hook names. Purge nothing if nothing changed.
- Scan and preview responses are `no-store` and carry `X-WST-Scan-Id: <token>`. The editor compares it with the token it sent; a mismatch means a cached copy was served, so it warns and retries with a cache-busting parameter.

---

## 7. Provider layer

```php
interface ProviderInterface {
    public function id(): string;                     // 'translatex' | 'microsoft' | 'gemini'
    public function label(): string;
    public function capabilities(): Capabilities;     // supports_html, max_items, max_chars, default_rpm, language_pairs()
    public function settingsSchema(): array;          // fields rendered by the admin UI (secret, text, select…)
    public function supportsPair(string $src, string $tgt): bool;
    public function translate(array $items, string $src, string $tgt): BatchResult;
    public function testConnection(): TestResult;
}
```

- `$items` = `[string_id => text]`. `BatchResult` returns per-item `text|error`, characters billed, and any rate-limit headers.
- Typed failures the queue understands: `AuthError` (pause provider, notice), `RateLimited(retry_after)`, `QuotaExceeded`, `TransientError`, `PermanentError`.
- **Error mapping is provider-specific (D11).** Each adapter maps its own error codes first and falls back to HTTP status only when the provider gives no specific code. Example: Microsoft `403001` (free quota exceeded) → `QuotaExceeded`, while Microsoft `401000` → `AuthError`.
- **Placeholder protection** (shared helper): shortcode remnants, `%s`/`%1$s`, `{{…}}`, URLs, emails, user "never translate" terms (§13) are swapped for opaque tokens before sending and restored after; reject a result if any token is missing or duplicated.
- **Tag placeholders for `inline` strings** (HTML-capable providers): never send real attributes. Send `Buy <a id="1">now</a>` instead of `Buy <a href="…" class="…">now</a>`, keeping a map `id → original opening tag`; restore afterwards. Providers cannot alter links or classes, fewer characters are billed, and the check "same tag sequence and ids" is trivial. Reject and retry/segment on mismatch; set flag `4` when a repair was applied.
- **Providers without HTML support**: do not send tags. Send an `inline` string as one sentence with each tag replaced by a placeholder token (`Read {1}our story{2} today`) so word order can change; restore the original tags and validate the structure. Only if that fails, split the string at its tags, translate segments separately and rejoin, setting `flags` bit `1` so the editor can warn about possible word-order issues (HANDOVER P20).
- Language code mapping lives in the registry, not in providers.
- Provider selection is a setting. An optional **fallback provider** is part of V1 (rules in §8).

### 7.1 Microsoft Translator
- REST v3 text translation (`api.cognitive.microsofttranslator.com`). Headers: `Ocp-Apim-Subscription-Key`, and `Ocp-Apim-Subscription-Region` when the resource is regional. Body: JSON array of `{ "Text": … }`; query: `from`, `to`, `textType=plain|html`.
- Supports HTML mode → send `inline` strings with `textType=html`.
- Free tier (F0): 2M characters per month (Azure pricing page), throttled at 2M characters per hour consumed evenly, about **33,300 characters per minute** (sliding window). S1: 40M characters per hour. No limit on concurrent requests. (Official docs, `service-limits`, 2026-08-11.)
- **Hard caps per request (D12): 1,000 array elements and 50,000 characters in total**; `max_items_per_request` / `max_chars_per_request` can never exceed them. Default `chars_per_minute` for F0: 33,000.
- Error codes (official `status-response-codes`): `401000` → `AuthError` (a missing or invalid key was observed as HTTP 401 code `401001`, fixtures `microsoft/{missing-key,invalid-key}.json`; the whole `401xxx`/`403xxx` class maps to `AuthError` except `403001`); `403001` → `QuotaExceeded` (D11); `403000` → `AuthError`; `429000`–`429002` → `RateLimited`; `408001`, `500000`, `503000` → `TransientError`; `400050` (text too long), `400072` (too many elements), `400077` (request too large) → split the batch and retry; other `400xxx` → `PermanentError`.
- `textType=html` requires well-formed, complete elements. Microsoft also honours `class="notranslate"` and `<mstrans:dictionary translation="…">`; decided in Phase 2 (D13, HANDOVER P11): we keep the shared opaque tokens for every provider.
- Settings: key, region, endpoint override (default global), limits (§8).

### 7.2 TranslateX

Source of truth: the owner's uploaded **"TranslateX for TranslatePress" 1.0.0** (GPLv2, by GTranslate Inc.), a ~260-line bridge plugin. It is used **only to learn the HTTP contract**; implement our own adapter (clean-room rule, §0 item 6). Official docs: `https://translatex.com/api-documentation` (could not be fetched while writing this plan; read them in Phase 0).

**Contract observed in the reference plugin**

| Item | Value |
|---|---|
| Translate | `POST https://api.translatex.com/translate?sl={src}&tl={tgt}&key={API_KEY}` |
| Body | `application/x-www-form-urlencoded`, **one `text=` pair per string, repeated** (not JSON): `text=<rawurlencode(string)>&text=<…>`. Input strings are `html_entity_decode`d first (treated as plain text). Set the `Content-Type` header explicitly. |
| Headers | `accept: *`, `x-api-client: <client id>`. The reference plugin sends `TX-For-TranslatePress`; we send `WP-Site-Translator/{version}`. Verified 2026-10-06: `WP-Site-Translator/0.1.0` is accepted (no client-id whitelist). **The key goes in the `X-API-Key` header** (official docs allow the `key` query parameter or this header), so it never appears in a URL. |
| HTML mode | Official docs: send `html=<string>` instead of repeated `text=`; the response `translation` is then a single string, not an array. One HTML document per request. Plan-gated (Enterprise only). |
| Rate-limit headers | Observed: successful responses carry `X-TX-RateLimit-Remaining`; the 429 response carries `X-TX-RateLimit: 50/min`. 429 body `{"err":"too many requests"}`, **no `Retry-After`** (fixture `rate-limited.json`; reached on the 51st request of a burst on a free key). |
| Success | HTTP 200, JSON `{"translation": ["…", "…"]}`; results are **positional** (index *i* = input *i*). |
| Failure | JSON `{"err": "message"}`. Reference code treats HTTP ≠ 200 and any `err` as failure, and reads `err` for the message. **Observed (fixtures, free key, 2026-10-06):** missing or invalid key → **400** `invalid api key`; unknown or missing language code → 400 `invalid source or target language code`; no `text` → 400 `no texts found`; text over the size limit → 400 `long text in request`; `html=` on a non-Enterprise key → **403** `HTML translation feature is not available for your api key`; rate limit → 429 `too many requests`. An empty input text returns 200 with `""` for it. |
| Languages | `GET https://api.translatex.com/supported-languages?key={API_KEY}` → `{"languages":[{"language":"en", …}, …]}`. Cache 24 h (transient). Use it for `supportsPair()` and for the *Test connection* result. |
| Test call | `en → it`, one string `"Hello World!"`. |
| Timeout | Reference uses 45 s for translate, 10 s for languages. Ours: configurable, default 30 s; the worker time budget must exceed it. |
| Batch size | Measured: **each text ≤ 2,000 UTF-8 bytes** (2,000 accepted, 2,001 rejected, also for Bengali, so about 700 Bengali characters); 500 texts and 60,000 characters per request accepted, but a 60,000-character request took 58 s. Defaults: 100 texts, 10,000 characters per request; texts over 2,000 bytes are split at sentence boundaries and joined back (HANDOVER P19). |
| Language codes | Plain ISO codes. The free key's `/supported-languages` returns 35 codes, **including `bn` and `ar`** (also `iw` for Hebrew, `no`, `zh-CN` / `zh-TW`). Resolve the final map from `/supported-languages` at runtime; keep a small override table in the language registry. |
| Placeholders | Measured: TranslateX rewrites `[[1]]` as `[ [ 1]]` and sometimes adds brackets (`[ [ 3]]]]`), changes `%1$d` to `%1$D` and `{{shop}}` to `{{Shop}}`; `{1}` survives unchanged in `bn` and `ar`. The adapter uses the token format `{%d}`; restore rejects stray token punctuation. |

**Behaviours our adapter must add (the reference plugin does not)**
- **Empty translation entry ⇒ failure for that item**, never "use the original as the translation" (the reference plugin does this and would cache untranslated text as machine-translated). Retry or mark failed.
- **Length check**: result array length must equal the input length, else treat the batch as failed and retry in smaller batches.
- **Secret hygiene**: the key is sent in the `X-API-Key` header, never in the query string. Still **redact `key=` and the key value in every log line, exception message and admin notice**, and never include full request URLs or headers in `wst_log`.
- **Error mapping** (provider-specific per D11; confirmed cases cite their fixture): **HTTP 400 with `err` = "invalid api key" → `AuthError`** (TranslateX reports auth failure as 400, so the adapter must read `err` for this case); 400 `long text in request` → split and retry one text per request; 403 `… not available for your api key` → `PermanentError` (a plan restriction, not an auth failure); other 401/403 → `AuthError`; 429 → `RateLimited` (use `Retry-After` if present, otherwise back off ≥ 60 s since limits are per minute); other 4xx → `PermanentError` for that batch; 5xx / network / timeout → `TransientError`. Apart from the documented auth case, classify by HTTP status and use `err` only for the message shown to the user. Each further `err` text that changes the class must be backed by a recorded fixture.
- **Fixture capture at the start of Phase 2** (needs an owner-supplied key; Phase 0 does not depend on keys): record real responses for success, invalid key, unsupported pair, empty input, malformed input, and (if testable) over-limit. Store them as test fixtures and resolve the `VERIFY` items above.

**Plan facts (from the vendor's pricing page)**
- Free plan: small neural model, **35 languages**, **50 calls/min**, unlimited translations, **no commercial use**, no privacy mode (submitted content and translations may be stored and used to improve the service and train models), **no HTML translation**, no language detection. Startup ($19.99/mo): large model, 50 languages, 50 calls/min, commercial use, privacy mode (processed in memory, never stored). Business ($29.99/mo): as Startup plus language detection, 75 calls/min. Enterprise ($39.99/mo): as Business plus **HTML translation**, 100 calls/min. More throughput = more API keys (vendor FAQ). (translatex.com pricing, checked 2026-10-06.)
- **Bengali and Arabic are in the free plan's 35 languages** (verified with `/supported-languages` on a free key, fixture `supported-languages.json`).
- **English-centric**: non-English pairs are routed through English internally.
- Capabilities: `supports_html = false` unless the user selects the Enterprise plan (the `html` parameter is documented; confirm its behaviour on a real Enterprise key before enabling it by default). Provider settings: API key, plan (free / startup / business / enterprise) which drives defaults (`requests_per_minute`: 50 / 50 / 75 / 100).
- UI warnings: (a) free plan on a site marked commercial, (b) neither language is English, (c) free-plan data retention.

### 7.3 Gemini (free tier via Google AI Studio key)
- REST `generateContent` with the key in a header; ask for **structured JSON output** (response MIME type + schema).
- Confirmed (ai.google.dev, 2026-10-06): key header `x-goog-api-key`; limits are RPM, input TPM and RPD, applied **per Google Cloud project, not per key**; RPD resets at midnight Pacific; exceeding any limit returns `429 RESOURCE_EXHAUSTED`; retry 429/408/5xx with exponential backoff, never 400/402/403. Free tier is "free of charge" for current Flash and Flash-Lite text models (e.g. `gemini-3.8-flash`, `gemini-3.5-flash-lite`, `gemini-2.5-flash`, `gemini-2.5-flash-lite`); **free-tier content is used to improve Google's products, paid-tier content is not**.
- **Not published:** per-model free-tier RPM/TPM/RPD numbers ("view your active rate limits in AI Studio"). Defaults must therefore be conservative and user-editable, and the UI points to AI Studio. Model name is a user-editable field with a sensible default. Show the privacy note in the UI.
- Prompting rules: system instruction = professional website/UI translation from X to Y; keep tokens/tags unchanged; keep brevity of UI labels; use the glossary terms; temperature ≈ 0; input = JSON array `[{id, text}]`; output = JSON array `[{id, text}]`.
- Validate: same ids, same count, tokens preserved, no extra commentary. On invalid output retry once with a smaller batch, then per item; otherwise mark failed.
- Batch by **characters** (token budget), not just item count.

---

## 8. Queue, rate limiting, usage budget

**Per-provider user settings** (defaults come from the provider and are recorded after the `VERIFY` items are resolved; every value is user-editable):

| Setting | Meaning |
|---|---|
| `requests_per_minute` | Integer ≥ 0. **`0` = unlimited**: the limiter is bypassed and batches are sent back-to-back (still sequential per `concurrency`). Any provider-side `429` / `Retry-After` is **always** honoured, even at `0`. Defaults: TranslateX by plan (50 / 50 / 75 / 100); Microsoft none (it throttles by characters, see `chars_per_minute`); Gemini conservative user-editable values (limits are per project and not published). UI helper text: "0 = no limit". |
| `requests_per_day` | Integer ≥ 0, `0` = unlimited. Gemini's free tier has a per-project RPD limit (confirmed; value shown in AI Studio, not published). |
| `chars_per_minute` | Integer ≥ 0, **`0` = unlimited** (D10). Characters sent per rolling minute. Microsoft throttles by characters (F0 ≈ 33,300/min); for Gemini it approximates input TPM. A batch larger than the remaining budget waits; a single item larger than the whole budget is sent alone when the window is empty. |
| `max_items_per_request`, `max_chars_per_request` | Effective value = `min(user value, provider hard cap)`; `0` = use the provider cap. |
| `concurrency` | Default 1. |
| `max_attempts` | Default 5. |
| `monthly_char_cap` | `0` = unlimited. Warn at 80 % (admin notice + Overview, optional email), **hard stop at 100 %**, resets with the calendar month. |

Validation: reject negative or non-integer values, clamp absurd values (e.g. > 10,000 RPM, > provider caps), show the *effective* limits and the estimated time for the current queue next to the inputs.

**Worker cycle**
1. Take a worker lock (DB-based, with expiry) so only `concurrency` workers run.
2. Loop until time budget (~20 s or `max_execution_time` minus margin) is used:
   - `RateLimiter::acquire(provider)` → proceed, or return seconds to wait (then reschedule and exit). It checks requests per minute, requests per day and characters per minute for the packed batch; each dimension set to `0` is skipped, and with all three at `0` it always proceeds.
   - Check monthly budget; stop when the cap is hit.
   - Select due `pending` rows ordered by `priority, id`, pack a batch within item/char limits, mark `processing` with `locked_until`.
   - Call provider. On success: upsert `wst_translations` as status 1 (**never overwrite status 2**), delete queue rows, add to `wst_usage`.
   - Errors: `RateLimited` → honour `Retry-After`; `TransientError` → exponential backoff (e.g. 30 s × 2^n, capped); `AuthError` → pause provider and raise a persistent admin notice; `QuotaExceeded` → stop until next period; per-item permanent failures → `failed` with `last_error` after `max_attempts`.
3. If work remains, schedule the next run at `now + wait`.

**Fallback provider** (optional setting; must differ from the primary, support the language pair, have credentials, and not be paused):
- Triggers: primary returns `QuotaExceeded` or `AuthError`, the primary is paused, the primary does not support the pair, or an item has exhausted `max_attempts` on the primary (it then gets one more set of attempts on the fallback).
- Do **not** switch on a plain `429`; wait and retry instead.
- Queue rows are **re-pointed** (their `provider` column is updated), not duplicated. `wst_translations.provider` records the provider that actually produced the text. The fallback has its own limiter, limits and budget.
- Return to the primary automatically at the next budget period or when the key is fixed. The Overview shows "Using fallback: X (reason)".
- The UI warns that site text may be sent to both vendors.

**Rate limiter**: token bucket per provider (implemented as strict even spacing, HANDOVER P18), correct across concurrent PHP processes (MySQL `GET_LOCK` or atomic compare-and-swap on one row), supporting request-per-minute, request-per-day and character-per-minute windows, each with the `0 = unlimited` bypass. Interface + one implementation + a concurrency unit test.

**Triggers**
- WP-Cron event (custom 1-minute interval) active only while the queue is non-empty.
- **Admin runner**: the Overview/Editor page polls `POST /queue/run`; each call processes one rate-limit-compliant batch. Works when cron is broken.
- WP-CLI: `wp wst queue run|status|retry-failed|clear`.
- If `DISABLE_WP_CRON` is set, show the server-cron command in Health.

**Priorities**: editor-requested (1) → "translate this page now" (2) → visitor-discovered (5) → "translate entire site" bulk (8).

**UX numbers the UI must show**: pending / processing / failed counts, characters pending, estimated time at the current RPM and batch size, characters used vs monthly cap, last error per provider.

**"Translate entire site"**: build the URL list (home, public post types, public taxonomies, optionally from the core sitemap); run scans (§11) client-side with progress; before enqueueing, show estimated characters vs remaining budget and require confirmation.

---

## 9. Translation modes (site, page, paths)

Three sources, resolved in this order — **first match wins**:

1. **Page setting** (post meta `_wst_mode`) for singular views.
2. **Path rules** (settings) for any URL, including archives and the home page. Wildcard at the end (`/shop/*`), `{{home}}` token, rules list ordered, each rule → mode.
3. **Site default**: `auto` or `manual`.

| Mode | Discover strings on visit | Auto-queue to provider | Manual editing | Editor "translate with MT" button | Target-language URL |
|---|---|---|---|---|---|
| auto | yes | yes | yes | yes | translated |
| manual | no (strings come from editor scans only) | **no** | yes | yes (explicit user action) | translated |
| off | no | no | n/a | n/a | per setting: redirect to original URL (default) or show original text |

- Site default `manual` = the whole site is manual, per the owner's request.
- A **page picker** in settings ("Pages not auto-translated") is just a bulk UI over `_wst_mode = manual`; one source of truth, no second list. Searchable table: title, type, current mode, coverage %, bulk actions.
- Setting "Allow MT suggestions in the editor on manual pages" (default on).
- A string already translated (by anyone) applies site-wide. Mode only controls *discovery and auto-queueing* for strings first seen on that page. Document this clearly in the UI help text.
- Manual edits (`status = 2`) are never overwritten by machine runs, including "re-translate all".
- **Page UI**: a panel in the block editor sidebar (`PluginDocumentSettingPanel`) and a meta box for the classic editor: mode selector, coverage %, "Open in translation editor", "Translate this page now" (priority 2). Register for all public post types.

---

## 10. Admin UI (original design, not a TranslatePress clone)

Top-level menu **Translator**. One React app with route-based screens, a persistent header showing queue status and provider health, and a global "unsaved changes" bar. Must support RTL, dark admin colour schemes, and keyboard use. Inline help text instead of tooltips.

1. **Overview** — setup checklist (language chosen → provider connected → first page translated), coverage %, queue panel with live progress and ETA, provider cards with quota meters and last error, recent log entries, quick actions (*Open editor*, *Translate entire site*, *Process queue now*).
2. **Languages** — default language, target language, URL slug, "prefix default language" toggle, language name style (native / English), RTL auto-detected and shown. Exactly two languages; no "add language" button in V1.
3. **Translation** — site mode (auto/manual), provider selector with a card per provider (credentials, *Test connection*, **requests/minute field with `0 = unlimited`** and the other limits from §8, quota meters), **fallback provider** selector, queue settings, "Pages not auto-translated" picker + path rules, discover-on-visit, block crawlers.
4. **Language Switcher** — placements (menu item, floating, shortcode/block), style (full names, short names, flags+names, flags only), position, light/dark/custom colours via CSS variables, **live preview**, copy-to-clipboard shortcode.
5. **Pages** — table of all pages/posts/products: mode, coverage %, last scanned, *Edit translations*, bulk mode change.
6. **Advanced** — §13 (including never-translate terms, digit conversion, dynamic content, language suggestion), plus *Health* (requirements check, cron status, loopback/scan test, recent errors) and *Data* (clear queue, clear machine translations only, delete-on-uninstall).
7. **Import / Export** — CSV export with filters, CSV import with dry-run preview and conflict policy (§13A.3).
8. **Migration** — TranslatePress detection and importer wizard, shown only while TranslatePress data or the plugin is present (§13A.4). This is a real, working screen, not a teaser.

Screens 7 and 8 are built in Phase 6a/6b. The settings for each §13A feature are added to the UI in Phase 6 **together with their backend**; do not stub them earlier.

Flags: `[GATE]` propose a licence-clean SVG set (e.g. an MIT flag-icon set) or text-only styles; explain before adding any dependency.

---

## 11. Editor (conflict-proof design)

Goal: translating must still work when other plugins break the front-end.

**Screen**: our own admin page *Translator → Editor*, never the front-end.
- On this screen a late `admin_enqueue_scripts` / `admin_print_*` hook **dequeues all third-party scripts and styles** and hides third-party admin notices. Only our bundle loads.
- Layout: left = string list; right = preview (optional). Mobile: list only.

**String list (primary mode — works without any iframe)**
- Source: the page's strings from `wst_occurrences`, produced by a **scan**.
- Columns: original (with kind icon), translation (inline editable), status chip (empty / machine / manual), provider.
- Tools: search, filter (untranslated / machine / manual / has warning), keyboard navigation, per-row *Suggest with provider*, *Mark manual*, *Revert to machine*; bulk *Translate untranslated with provider* (queued, priority 1) and a live queue indicator. Autosave on blur with optimistic UI and clear error states.
- Shows warnings: placeholder lost, tag mismatch, plain-text provider used on an inline string.
- RTL-aware input (`dir="auto"`).

**Scan** (how strings reach the editor)
- The admin's **browser** does a same-origin `fetch()` of the target-language URL with a short-lived signed scan token (capability + nonce). The server renders normally, records strings and occurrences, and returns a small JSON summary. This avoids server loopback problems (Cloudflare, basic auth, blocked loopback).
- Fallback: server-side `wp_remote_get` loopback if browser fetch is impossible.
- Scan only same-origin URLs (SSRF guard). Scan responses are `no-store` and `noindex`.

**Visual preview (optional, never required)**
- `<iframe sandbox>` of the translated page in **safe preview mode**: the preview response strips every `<script>` that is not ours (toggle *Run page scripts* for JS-dependent layouts), so other plugins' JS cannot interfere.
- Our tiny preview script marks translatable nodes; clicking one scrolls/highlights the matching row, and vice versa.
- If the iframe does not report ready within ~8 s or the page errors, show a clear message and keep the list fully usable. Never block the editor on the preview.

**Entry points**: Pages table, page editor panel, admin bar "Translate this page" on the front end.
**Capability**: new `wst_translate` (granted to administrators and editors); settings need `manage_options`.

---

## 12. Language switcher

- Four outputs: nav-menu item (a special menu item type that works in classic menus and block-theme Navigation where feasible), floating switcher, `[wst_switcher]` shortcode, `wst/switcher` block.
- Links go to the **equivalent page in the other language**; if unavailable (mode `off`) go to the language home.
- Markup is excluded from translation (`data-wst-no-translate`), accessible (real links, `hreflang`, `lang` on each label, focus styles), styled with CSS variables, < 3 KB JS, no layout shift.
- Remember the visitor's choice in the `wst_lang_choice` cookie (functional, first-party) when the language suggestion feature is enabled; behaviour of the suggestion bar and the optional redirect is in §13A.7. Plain switcher clicks never depend on the cookie.

---

## 13. Advanced settings (each must work; no dead options)

- Exclude CSS selectors (supported subset: tag, `.class`, `#id`, `[attr]`, `[attr=value]`, comma lists, descendant). Document the subset.
- Terms that must never be translated (brand names such as product/company names) — applied through placeholder protection for every provider.
- Block crawlers from enqueueing (default on).
- Discover strings on visits (off = scans only).
- Force language on hard-coded internal links (default on).
- Add language prefix to default language (default off).
- `hreflang` `x-default` on/off; drop region from `hreflang` / `<html lang>`.
- Safe preview: strip third-party scripts (default on).
- Never-translate terms (§13A.1).
- Digit conversion: off / Bengali / Arabic-Indic / Persian; defaults to Arabic-Indic when the target language is Arabic; skip WooCommerce prices toggle (§13A.6).
- Dynamic content: server-side fragments on/off, client-side lookup on/off, JSON key allowlist (§13A.5).
- Language suggestion: off / bar / redirect (owner picks what visitors get), bar text per language, position (§13A.7).
- Sitemap alternates on/off (§13A.8).
- Log level + log viewer; *Health* tools; *Data* tools; delete-on-uninstall.

---

## 13A. Extended V1 features (confirmed by owner)

### 13A.1 Never-translate terms
- Textarea in Advanced, one term per line; options: case-insensitive (default on), whole-word only (default on). Cap at 500 terms.
- Applied through the shared placeholder protection (§7) for **every** provider and for the fallback. A string that equals a term exactly is never sent anywhere.
- Changing the list is not retroactive. Provide a report "translations that alter a listed term" (list only, no automatic edits).
- Editor shows a chip on strings containing a listed term; set `wst_translations.flags` bit `2` when a protected term was altered.

### 13A.2 Monthly budget
Specified in §8 (`monthly_char_cap`, 80 % warning, 100 % hard stop). UI: usage meter per provider on Overview and Translation; optional admin email at 80 % and 100 % (default off); budget resets on the first day of the month (site timezone).

### 13A.3 CSV import / export
- **Format**: UTF-8 (BOM tolerated on import), header `original,translated,status,kind,lang`; export may add `pages` (paths joined by `|`). `status` ∈ `machine|manual`.
- **Export filters**: all / untranslated / machine / manual / one page. Stream the file in chunks (no memory blow-up). **CSV-injection guard**: prefix cells starting with `= + - @` with `'` on export, strip that single leading `'` on import.
- **Import**: upload → **dry-run** summary (rows, new, would-update, conflicts, invalid with reasons) → choose conflict policy: *add only* · *overwrite machine, keep manual* (default) · *overwrite all* → apply in chunks of ~500 rows via REST with a progress bar.
- Imported rows default to status **manual**; option to import as machine. Validate each row with the same placeholder/tag-sequence checks as §6.5 and sanitise with `wp_kses`. Row and file size limits are enforced and reported.
- Requires `manage_options`.

### 13A.4 TranslatePress importer and coexistence
- **Detect**: option `trp_settings` (`default-language`, `translation-languages`, `url-slugs`) and tables `{prefix}trp_dictionary_{default}_{target}` (locale codes lower-cased, e.g. `wp_trp_dictionary_bn_bd_en_us`). Columns: `id, original, translated, status (0 none, 1 machine, 2 human), block_type (0 regular, 1 translation block, 2 deprecated), original_id`.
- **Wizard**: (1) detect and show the pair and row counts per status; (2) **prefill our Languages settings including the same URL slugs** so existing URLs and SEO are preserved; (3) dry run; (4) import in chunks of ~1,000 rows, **read-only on TranslatePress tables**.
- **Import rules**: skip empty `translated` and `block_type = 2`; status 1 → machine, status 2 → manual (protected); normalise `original` exactly like our extractor (decode entities, collapse whitespace, trim); originals containing tags get kind `inline`, others `text`. Default conflict policy *keep existing*; idempotent via upsert; re-runnable.
- **Match report**: after import, scan N chosen pages and report the percentage of extracted strings that already have a translation, plus the top unmatched strings (TranslatePress and our extractor segment HTML differently, so some blocks will not match).
- **Coexistence guard**: while TranslatePress is active (`is_plugin_active` on its main file and/or class check; `VERIFY` the main-file path), our router and render pipeline stay disabled. Show a clear notice and a *Go live* checklist: deactivate TranslatePress → flush permalinks → confirm slugs → scan key pages → compare a few URLs.
- **Content references**: scan posts, widgets and menus for `[language-switcher]` and `[trp_language …]` shortcodes and TranslatePress switcher menu items; report them with edit links. Optionally register a `[language-switcher]` alias for `[wst_switcher]` (toggle, default on during migration).
- Never modify or delete TranslatePress data. `data-no-translation` is already honoured.

### 13A.5 Dynamic content
**(a) Server-side fragments**
- Add a *fragment mode* to the pipeline: same extraction and replacement, no `<head>`/`lang`/hreflang/switcher work.
- WooCommerce: translate values in `woocommerce_add_to_cart_fragments`, and handle `wc-ajax` responses (`get_refreshed_fragments`, `add_to_cart`, `update_order_review`, `checkout`).
- Generic `admin-ajax.php` / REST JSON: translate only string values that **contain HTML tags** or sit under allowlisted keys (default `message, messages, notice, notices, error, errors, html, content, label, title`; editable in Advanced), recursion depth ≤ 5. Never touch keys, numbers, IDs, URLs or nonces.
- Language for AJAX requests comes from an explicit `wst_lang` parameter added by our small script, else from the referer's prefix.
- Fail-open: on any error return the original body unchanged and log it.

**(b) Client-side lookup** (toggle *Translate dynamic content*, off by default)
- ≤ 6 KB script: `MutationObserver` on `body`, debounced (~100 ms), collects added text nodes and the attribute set from §6.2, skipping excluded areas, `contenteditable`, form values and nodes it already handled (`data-wst-done`).
- It calls `POST /wst/v1/lookup` with ≤ 100 strings (≤ 2,000 characters each) and applies the **existing** translations returned. The endpoint is **public, read-only**, per-IP rate-limited, and **never creates strings**. The client IP comes from a **trusted-proxy setting** (Advanced: none / `CF-Connecting-IP` / `X-Forwarded-For` / custom header); without it, sites behind Cloudflare or another proxy would share one IP and rate-limit everyone together. Unknown strings stay in the original language.

**(c) Dynamic scan (discovery)**
- Editor button *Scan dynamic content* opens the page in a new tab with a signed `wst_dyn_scan` token. The observer additionally reports unseen strings to `POST /wst/v1/scan/dynamic` (capability + nonce), stored as kind `dynamic` with the page occurrence and queued according to the resolved mode.
- A small floating toolbar shows how many strings were found while the admin opens the cart/mini-cart, quick view, tabs, sliders and popups.

**(d) Known limit**: strings assembled by JavaScript concatenation (`'Items: ' + n`) cannot be matched; document this in the UI help.

### 13A.6 Digit conversion
- Setting `digits_mode`: `off` · `bengali` (০১২৩৪৫৬৭৮৯) · `arabic_indic` (٠١٢٣٤٥٦٧٨٩) · `persian` (۰۱۲۳۴۵۶۷۸۹, also used for Urdu).
- **Owner decision: when the target language is Arabic, the default is `arabic_indic` (enabled).** For every other target language the default is `off`; the UI may suggest `bengali` for Bengali / `persian` for Urdu and Persian but must not enable them silently. The user can always change the mode, and it is applied only to the target-language pages.
- Applies **only on target-language output**, to visible text nodes and inline strings after replacement (§6.5a). Never in attributes, URLs, emails, form values, `script/style`, `[data-wst-no-translate]`, or inside protected tokens.
- Toggle *Skip WooCommerce prices* (default on, matches `.woocommerce-Price-amount`, `.price`) plus the normal exclude selectors.
- Converts ASCII digits only (already-converted digits are left alone). Round-trip test: digits restored to ASCII equals the input.

### 13A.7 Browser-language suggestion
- Setting `lang_suggestion`: `off` (default) · `bar` · `redirect`. **Both `bar` and `redirect` are fully supported and the site owner chooses which one visitors get** (radio buttons with a one-line explanation of each: bar = asks the visitor, redirect = sends them automatically).
- **Bar**: a ~1 KB deferred script reads `navigator.languages`, matches the primary subtag against our two languages, and, if the best match differs from the current page language and no choice cookie exists, shows a dismissible bar linking to the equivalent URL (embedded in the page as a data attribute, so cached pages stay identical for everyone). Bar text is configured once per language in settings and shown in the *suggested* language.
- **Cookie**: `wst_lang_choice` (first-party, 180 days) set on switcher use and on dismiss. It is read client-side only; the server never varies output by cookie, so page caches stay valid. Mention it for cookie-policy purposes in the UI help.
- **Redirect mode** (opt-in, explain the SEO and consent trade-off): JS `location.replace` on the **first** visit only when no cookie exists; never for bots (UA check, `navigator.webdriver`), never to a page whose mode is `off`, never when `?wst_no_redirect=1` is present, and a switcher click always wins.
- Styling via CSS variables; position top/bottom; keyboard and screen-reader accessible (`role="region"`, focus management, `lang` on the text).

### 13A.8 Sitemap language alternates
- Core WP sitemaps have no hook for `xhtml:link`; register an additional provider that lists the target-language URL of every translatable public URL (post types, taxonomies, home) honouring mode `off`, `noindex`, and published status, with `lastmod` from the post. It appears in the sitemap index.
- Yoast SEO and Rank Math: use their documented sitemap filters to add the target-language URLs and `xhtml:link` alternates. `VERIFY` the current stable APIs in Phase 6; if none exists, document the limitation. In all cases the `<head>` hreflang tags (§5) remain the baseline.
- Toggle in Advanced (default on when a target language is published).

---

## 14. REST API (`wst/v1`)

All routes check a capability and a REST nonce. Sanitise input, escape output, never return secrets.

`GET/POST /settings` · `GET /languages` · `GET /providers` (capabilities, usage, health) · `POST /providers/{id}/test`
`GET /strings` (filters: `page_key`, `post_id`, `status`, `search`, pagination) · `POST /strings/{id}/translation` (manual save) · `POST /strings/translate` (`ids[]`, `mode=queue|now`)
`GET /pages` · `POST /pages/mode` (bulk) · `GET /queue` · `POST /queue/run` · `POST /queue/retry-failed` · `POST /queue/clear`
`POST /scan/register` (tokens/summary for the browser scan) · `POST /scan/dynamic` · `POST /lookup` (public, read-only, rate-limited) · `GET /export` · `POST /import/dry-run` · `POST /import/apply` · `GET /migration/trp` · `POST /migration/trp/dry-run` · `POST /migration/trp/import` · `GET /health` · `GET /log`

---

## 15. Cross-cutting requirements

**Security**: capability + nonce on every write; prepared SQL; `wp_kses` on any stored HTML; provider responses are untrusted data; scan/preview endpoints are authenticated and same-origin only; secrets masked; no secrets in logs.
**Performance** (measure and report, do not assume): default-language request overhead ≈ 0; cached target-language page = one batched translation query and zero external calls; extraction budget on a 200 KB document to be measured in Phase 1 and recorded in `HANDOVER.md`.
**Caching**: language lives in the URL so page caches work; the server never varies output by cookie (language suggestion and redirect run client-side, §13A.7); scan/preview responses send no-cache headers; document exclusions for LiteSpeed/WP Rocket/Cloudflare if any are needed.
**Compatibility targets** (test, in this order): Elementor, Gutenberg, WooCommerce (server-rendered pages only), Yoast SEO / Rank Math (titles & meta), LiteSpeed Cache, WP Rocket. The owner's main test site uses Elementor, WooCommerce-style content and Bengali/English.
**Accessibility**: switcher and admin UI keyboard-operable, visible focus, labelled controls.
**Uninstall**: clean removal behind the explicit setting.
**Coding standards**: WordPress Coding Standards (PHPCS), PHPStan **level 8** with WordPress type stubs (set up in Phase 0 while the codebase is new), strict types, small classes with interfaces at module boundaries.

---

## 16. Phases and acceptance criteria

**Phase 0 — Setup & spikes**
- Scaffold plugin, autoloader, constants file, `.distignore`, `wp-env` (or equivalent) dev environment, PHPUnit, PHPCS, PHPStan level 8 + WordPress stubs, `@wordpress/scripts`.
- Spike, in this order: (1) WordPress's own HTML API (`WP_HTML_Tag_Processor` / `WP_HTML_Processor` text-token iteration and text replacement, WP ≥ 6.7); `VERIFY` that it exposes enough to find and replace text nodes and attributes while leaving all other bytes untouched; (2) if not, our own offset-based tokenizer. Record the round-trip result on ≥ 20 sample pages: owner-exported saved pages if supplied, otherwise pages generated in the local WordPress with Elementor, Gutenberg and WooCommerce content. `[GATE]` choose the approach and the final WP minimum.
- Provider facts from **official documentation only** (TranslateX docs, Microsoft Translator, Gemini): limits, free-tier terms, HTML parameter. **No API keys are needed in Phase 0.** Record in `HANDOVER.md`.
- Write `CLAUDE.md`, `HANDOVER.md`, decision log (§20).
- **Done when**: environment runs, round-trip test is green, provider facts recorded from official docs.

**Phase 1 — Core without providers**
- Schema, language registry, router, render pipeline, hreflang/`lang`/`dir`/locale switch, link rewriting, minimal switcher.
- Discovery gate and cache behaviour from §6A (recording strings, caps, uncacheable-while-pending headers).
- Manual translation only, seeded through WP-CLI (`wp wst string set|get|list`), kept as a **permanent** admin/developer tool. No temporary REST route.
- **Done when**: `/bn/` (or chosen slug) renders translated text from the DB; default language unaffected; menus/links stay in language; RTL target flips `dir` and loads theme RTL CSS; round-trip identity holds; unit + integration tests green.

**Phase 2 — Providers, queue, limiter, usage**
- **First task: capture real TranslateX responses** with the owner's key as fixtures (§7.2) and resolve its `VERIFY` items.
- Provider interface, shared placeholder protection (including never-translate terms) and tag placeholders (§7), TranslateX, Microsoft, Gemini, queue table/worker, rate limiter (per-minute and per-day, `0 = unlimited`), usage/monthly budget, **fallback provider**, WP-CLI, cron + admin runner endpoint, page-cache purge when a page's queued strings finish (§6A).
- **Done when**: with a fake provider, the limiter never exceeds the configured RPM under concurrent workers; `requests_per_minute = 0` sends batches without throttling yet still honours a `429`; the fallback switches and returns exactly as §8; the TranslateX adapter never stores an empty or length-mismatched result as a translation and never logs the API key; 429/5xx/auth/quota paths behave as §8; manual translations are never overwritten; TranslateX passes `testConnection` and a live batch with the owner's key. Microsoft and Gemini are implemented and tested against recorded responses and fake HTTP; their live checks moved to Phase 7 (owner decision, 2026-10-07).

**Phase 3 — Modes**
- Resolver (page > path rules > site), post-meta panel (block + classic), pages table endpoints, enqueue gating, `off` behaviour.
- **Done when**: matrix tests cover every mode × discover × enqueue combination in §9; a manual page never reaches the queue; editor-initiated MT still works on manual pages.

**Phase 4 — Admin UI**
- Overview, Languages, Translation, Switcher (with live preview), Pages, Advanced, Health.
- **Done when**: every control persists and has a real effect; secrets never reach the browser; RTL and dark scheme verified; empty/loading/error states exist.

**Phase 5 — Editor**
- Scan flow, string list, autosave, bulk queue actions, safe preview with fallback, entry points.
- **Done when**: with a deliberately hostile plugin (breaks jQuery / throws JS errors on the front-end) the editor still opens and all strings are editable; preview failure degrades to list mode with a clear message.

**Phase 6 — Extended features** (build in this order; each is independently shippable)
- 6a CSV import/export (§13A.3) · 6b TranslatePress importer + coexistence guard + Migration screen (§13A.4) · 6c dynamic content (§13A.5) · 6d digit conversion (§13A.6) · 6e language suggestion (§13A.7) · 6f sitemap alternates (§13A.8).
- **Done when**: 6a export→import round-trips losslessly (including CSV-injection guard), dry-run changes nothing; 6b imports a real TranslatePress dataset read-only, is idempotent, reports a match rate, and our front-end stays off while TranslatePress is active; 6c WooCommerce add-to-cart/mini-cart show translated fragments, `/lookup` cannot create strings and is rate-limited, dynamic scan stores strings; 6d digit conversion never touches URLs/attributes/prices-when-skipped and round-trips; 6e suggestion bar works on a fully cached page and redirect mode never fires for bots or twice; 6f the sitemap lists target URLs, omitting `off` and `noindex` pages, validated against the sitemap XML rules.

**Phase 7 — Hardening**
- Compatibility matrix (§15) including a run with TranslatePress active (guard), performance measurements, security review of every endpoint, uninstall, upgrade routine, i18n strings, readme.
- Live provider verification with the owner's keys (moved from Phase 2): `wp wst provider test microsoft|gemini` and a live batch each; the Gemini model name and request format (`generationConfig.responseFormat`); the real Gemini free-tier limits (RPM/RPD/TPM in AI Studio) against our defaults; Microsoft `403001` quota behaviour; the fallback switch and return with real keys; record fixtures with `bin/capture-provider-fixtures.php … all`.
- Compatibility checks on a real staging site with released WooCommerce and Elementor (this development environment only has source builds without their JavaScript builds).
- **Done when**: full suite + PHPStan + PHPCS pass; Microsoft and Gemini pass `testConnection` and a live batch with real keys, and the fallback switch works live; compatibility matrix (from the staging site) filled in `HANDOVER.md`; known issues listed.

Run the full test suite **once** at the end of each phase, not after every edit; never edit files while it runs; never run two test/build processes at once.

---

## 17. Test strategy

- **Unit**: tokenizer/extractor, placeholder protection, mode resolver, rate limiter (with simulated concurrency), queue state machine, each provider adapter (HTTP mocked from recorded real responses).
- **Integration** (WP test suite): routing, link rewriting, render with a seeded DB, REST permissions, uninstall.
- **Golden files**: saved pages → expected output with a seeded translation map.
- **Regression tests** only for genuinely new behaviour; never change a test just to make code pass.
- Live provider calls only through an opt-in env flag with owner-supplied keys.
- **Extended features**: CSV round-trip + injection guard; TranslatePress importer against a fixture dataset (all three statuses, deprecated blocks, entity/whitespace normalisation, idempotent re-run); rate-limiter matrix (`rpm` 0 / N, per-day, concurrent workers); fallback triggers; digit conversion round-trip and exclusions; fragment/JSON translation safety (keys, IDs and nonces untouched); `/lookup` abuse limits; language-suggestion decision table (browser languages × current language × cookie × bot × mode `off`).

---

## 18. Backlog after V1 (do NOT build unless the owner confirms)

Translated slugs · JSON-LD/schema translation · image/media replacement · per-page overrides of the same string · more than two languages · translated emails and WooCommerce notices · search in the target language · multisite · review/approval workflow · translation-memory suggestions · glossary with fixed translations · gettext/.po handling · `[trp_language]`-style conditional-content shortcode.

---

## 19. Open questions for the owner (confirm at Phase 0; do not block on them)

1. Final plugin name and whether it will be sold (affects licensing/GPL notes and a licence-key layer).
2. PHP/WP minimums (default: PHP 8.0, WP 6.7; final WP minimum decided at the Phase 0 gate).
3. TranslateX: confirm the free-plan key is for non-commercial use only; provide the key at the **start of Phase 2** for fixture capture; confirm the HTML-translation parameter if an Enterprise plan is used.
4. Microsoft and Gemini keys for live tests (environment variables, never committed).
5. Flag icons: bundle a licence-clean set, or text-only styles?

---

## 20. Decision log (pre-Phase-0 review, adopted by the owner)

| # | Decision | Where |
|---|---|---|
| D1 | Visitor discovery is gated: logged-out, non-bot, no query string, not search/404/cart/checkout/account/order-received, with per-page and site-wide hourly caps; admin scans exempt but excluded pages are not auto-queued. | §6A |
| D2 | Pages with queued strings are uncacheable until done; page cache purged on completion (LiteSpeed, WP Rocket, generic action); scan ID header detects cached scans. | §6A |
| D3 | Phase 0 spike tries WordPress's HTML API first; WP minimum raised to 6.7 unless the spike falls back to our tokenizer. | §3, §16 |
| D4 | HTML-capable providers receive numbered placeholder tags, not real attributes. | §7 |
| D5 | Schema additions: `wst_translations.flags`, `wst_strings.is_global`, `wst_pages`; `page_key` defined; orphan cleanup tool. | §4 |
| D6 | Phase 1 seeding uses permanent WP-CLI commands, not a temporary REST route. | §16 |
| D7 | Phase 0 needs no API keys; TranslateX fixture capture moves to the start of Phase 2. | §16 |
| D8 | Inline block = whole subtree inline; no hreflang for `off` pages; trusted-proxy setting for `/lookup` rate limits; PHPStan level 8 with WP stubs; no model name in the plan. | §5, §6, §13A.5, §15 |
| D9 | Repo layout: plugin at the repo root, release zip built with `.distignore`. | §3 |
| D10 | Rate limiter gains `chars_per_minute` (0 = unlimited) next to RPM and RPD. | §8 |
| D11 | Error mapping is provider-specific and wins over generic HTTP-status mapping; Microsoft `403001` = `QuotaExceeded`. | §7, §7.1 |
| D12 | Microsoft hard caps per request: 1,000 strings and 50,000 characters. | §7.1, §8 |
| D13 | Whether Microsoft's own term protection (`notranslate`, `mstrans:dictionary`) replaces opaque tokens is decided in Phase 2. | §7.1 |
| D14 | Commits: commit and push at the end of each phase to the working branch only; no force-pushes, no merges. An empty initial `main` is the base branch for the draft PR. | §0 |
