# WP Site Translator — working notes for coding agents

Read `HANDOVER.md` first (status, decisions, exact next step). The full spec is `docs/WST-V1-PLAN.md`; read it once, then rely on HANDOVER.

## Product (plan §1)
WordPress plugin that translates a whole site between **two languages** (default ⇄ one target, any direction). Machine translation from pluggable providers (TranslateX, Microsoft, Gemini) through a **rate-limited queue**; rendering never calls a provider. Site/page/path modes (auto / manual / off), a conflict-proof editor, language switcher. Out of V1: more than two languages, translated slugs, media replacement, gettext, emails, multisite. No UI for out-of-scope features.

## Technical rules (plan §3, §15)
- Names live in `src/Config.php` (slug `wp-site-translator`, namespace `WST\`, prefix `wst_`, REST `wst/v1`). The plugin header must be edited by hand.
- PHP ≥ 8.0 (no readonly, enums, `never`, new-in-initialisers), WP ≥ 6.7. Strict types. No Composer runtime dependency (own PSR-4 autoloader in `src/Autoloader.php`).
- HTML is never re-serialised: `WST\Html\Extractor` finds segments with byte offsets using the core HTML API lexer; `WST\Html\Replacer` splices translations into the original bytes.
- Custom tables via `dbDelta`, `$wpdb->prepare` everywhere. Secrets in a non-autoloaded option, never sent to the browser or logs.
- Security: capability + nonce on every write, `wp_kses` on stored HTML, provider responses are untrusted.
- Failure policy: the front-end pipeline returns the original HTML on any throwable, logs it, and rethrows under `WP_DEBUG`. No empty catch blocks, no silent fallbacks.
- Clean room: TranslatePress and the TranslateX bridge are behavioural references only; never copy their code, markup or text.

## Coding standard
WPCS (`phpcs.xml.dist`) with project exceptions: PSR-4 file names, camelCase methods/properties/variables, short array syntax allowed. PHPStan level 8 with WordPress stubs (`phpstan.neon.dist`).

## Working rules (owner)
- Build only what is asked; no speculative features, placeholder settings or dead controls. Reuse existing code before adding new abstractions; explain new dependencies, schema changes and big refactors first.
- Surgical edits; never rewrite a large file for a small change. Preserve behaviour unless the task changes it.
- Errors fail loudly. Never weaken security, RTL or layout guardrails to get green tests; never edit a test just to make code pass.
- **Commits (D14):** commit and push at the end of each phase to the working branch only; otherwise only when the owner asks. No force-pushes, no merges, no destructive git commands without confirmation.
- Focused tests while working; the full suite once per batch/phase. Never run two test/build processes at once, never edit while the suite runs.
- At `[GATE]`s, record the decision and reason in `HANDOVER.md`. Ask the owner only for architecture, data or security changes. `VERIFY` items must be checked against official docs, never guessed.
- Update `HANDOVER.md` at the end of every phase and before context runs out.

## Commands
```
bin/setup-env.sh                     # database + dev tools; idempotent (see HANDOVER "Dev environment setup")
composer install                     # dev tools (PHPUnit, PHPCS, PHPStan, WP core for tests)
composer lint                        # PHPCS
composer analyse                     # PHPStan level 8 (cloud container: php .tools/phpstan.phar analyse --memory-limit=1G)
composer test:unit                   # no WordPress, no DB
composer test:integration            # WordPress test suite; DB from WST_TEST_DB_* env vars
bin/fetch-html5lib-tests.sh          # html5lib inputs for RoundTripTest (gitignored)
npm install && npm run build         # assets-src/ -> build/ (commit build/); npm run lint:js; Node >= 22.22.2
npm run test:js                      # JS unit tests (Node's built-in runner)
npx wp-env start                     # Docker dev site with WooCommerce + Elementor
bin/build-zip.sh                     # release zip from HEAD (.distignore, checks contents and secrets) -> dist/
```
