# Staging checklist (Phase 7)

For the owner's staging site with **released** WooCommerce and Elementor. These steps cover what the development environment could not: real WooCommerce JavaScript (cart fragments, prices), Elementor's built assets and a real TranslatePress dictionary. Follow the steps in the first table, then fill in **Results to paste back** at the end and paste that section into the chat (attach screenshots for anything that failed). When a step fails, the browser console (F12) and **Translator → Health → Recent log entries** help most.

Before you start: take a backup or use a disposable staging copy. Steps 12 and 15 change data on purpose.

| # | Do this | Expected |
|---|---|---|
| 1 | **Install.** Plugins → Add New → Upload Plugin → `wp-site-translator-0.1.0.zip` → Activate. If TranslatePress is active, keep it active for now. | Activates without errors; a **Translator** menu appears. With TranslatePress active, a notice says our front end stays off until TranslatePress is deactivated. |
| 2 | **Languages.** Translator → Languages: default language, target language and URL slugs (if you migrate, wait: step 11 fills these from TranslatePress). Save. | "Settings saved." Visiting `/<slug>/` (e.g. `/bn/`) after step 11 or with TranslatePress inactive shows the page with `lang`/`dir` of the target language. |
| 3 | **Key and test connection.** Translator → Translation → provider card (TranslateX, Microsoft or Gemini): paste the key, Save changes, then **Test connection**. | The card turns from "Not verified yet" to ready; the key shows as "Saved (hidden)" and is never shown again. Page source of the admin screen does not contain the key. |
| 4 | **Translate a page.** Translator → Pages → pick a normal page → open it in the **Editor** → **Scan page** → Translate untranslated with provider, or wait for the queue (Overview → **Process queue now** if WP-Cron is slow). | Strings appear in the list; after the queue runs, `/<slug>/that-page/` shows the translation. Editing a string and leaving the field shows "Saved"; the page updates (purge your page cache if you use one). |
| 5 | **Elementor page.** Repeat step 4 on a page built with Elementor (headings, buttons, tabs/accordions, a form). | Layout and widgets work the same in both languages (open/close tabs, submit the form). No missing styles, no console errors from the page. Note any text left in the default language. |
| 6 | **Elementor editor unaffected.** Edit that page with Elementor and save. | The Elementor editor loads and saves normally; the default-language page is unchanged. |
| 7 | **WooCommerce shop and product.** Open `/<slug>/shop/` and a product page. | Product titles, descriptions, buttons translated; **prices keep their format** (with digit conversion on, prices stay in Latin digits unless "Keep WooCommerce prices as they are" is off). |
| 8 | **Cart fragments.** On `/<slug>/` add a product to the cart from the shop list, open the mini-cart, change quantity on the cart page, go to checkout. | Add-to-cart notice, mini-cart, cart totals and checkout review update **in the target language** without a reload. Cart, checkout and My account are never recorded as page text (personal pages). Advanced → Dynamic content → "Translate AJAX and REST responses" is on. |
| 9 | **Language switcher on mobile.** Translator → Language switcher: turn on Floating switcher (or add the block / shortcode `[wst_switcher]` / menu item). Check on a phone (or 390 px wide). | The switcher is visible, does not cover the cart or menu button, opens and switches to the same page in the other language; keyboard focus is visible on desktop. |
| 10 | **SEO head and sitemap.** View source of a translated page; open `/wp-sitemap.xml` (or `/sitemap_index.xml` with Yoast SEO / Rank Math). | `hreflang` links for both languages (+ `x-default`); canonical and `og:locale` of the page language. The sitemap index lists `wp-sitemap-wst-…xml` files; they list `/<slug>/…` URLs and leave out pages set to Off or noindex. |
| 11 | **TranslatePress importer** (only if TranslatePress is installed). Translator → Migration: compare languages and slugs, copy them; **Dry run**, then **Import**; then **3. Match report** on 3–5 important pages. | Dry run changes nothing; import finishes with row counts per status; re-running Import reports 0 new. Match report shows a percentage per page; note it and the top unmatched strings. |
| 12 | **Go live** (Migration screen checklist). Deactivate TranslatePress → **Flush now** → check slugs → scan key pages → compare translated URLs. | Each checklist row turns to Done; translated URLs are "Same" as under TranslatePress; `[language-switcher]` shortcodes now show our switcher; translated pages show the imported translations. |
| 13 | **Page cache.** If you use LiteSpeed Cache, WP Rocket or Cloudflare: load a translated page twice (second load cached), edit one of its strings in the Editor, reload. | Cached copy is served; after the edit the page shows the new text (purge happened, or after the cache's own expiry if not LiteSpeed / WP Rocket). |
| 14 | **Off page.** Set one page to "Off (not translated)" in Pages. | `/<slug>/that-page/` redirects to the default-language page (or shows it, per Advanced setting); it has no hreflang to the target language and is not in the translated sitemap. |
| 15 | **Uninstall.** First without, then with Advanced → Data → "Delete all translator data when the plugin is deleted": deactivate and delete the plugin; reinstall between the two runs. | Without the setting: reinstalling shows all translations again. With it: the `wp_wst_*` tables, `wst_*` options and page settings are gone (check with phpMyAdmin or ask the host). |

Also note anything that looks wrong even if it is not in the list (layout shifts, slow pages, strings that never translate).

## Results to paste back

Copy from here to the end, fill it in, and paste it into the chat. Result: `pass`, `fail` or `skip` (with the reason). Notes: what you saw, the page URL, and the screenshot file name for a fail.

```
Site facts
- Date:
- WordPress version:
- PHP version:
- Theme (name + version):
- WooCommerce version:
- Elementor version (+ Pro?):
- TranslatePress version (or "not installed"):
- SEO plugin (Yoast / Rank Math / none) + version:
- Page cache (LiteSpeed / WP Rocket / Cloudflare / none):
- Languages (default -> target, URL slug):
- Provider used + plan:
```

| # | Step | Result | Notes |
|---|---|---|---|
| 1 | Install | | |
| 2 | Languages | | |
| 3 | Key and test connection | | |
| 4 | Translate a page | | |
| 5 | Elementor page | | |
| 6 | Elementor editor unaffected | | |
| 7 | WooCommerce shop and product | | |
| 8 | Cart fragments | | |
| 9 | Language switcher on mobile | | |
| 10 | SEO head and sitemap | | |
| 11 | TranslatePress importer | | |
| 12 | Go live | | |
| 13 | Page cache | | |
| 14 | Off page | | |
| 15 | Uninstall | | |

```
Other observations (anything not covered above):
- 
```
