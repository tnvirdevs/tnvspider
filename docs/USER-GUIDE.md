# WP Site Translator — user guide

WP Site Translator shows your WordPress site in two languages: your default language and one target language (for example English and Bengali). The translated site lives under a prefix such as `example.com/bn/`. Texts are translated in the background by a translation service you choose; you can correct any translation by hand.

Everything is under **Translator** in the WordPress admin menu.

## 1. First-time setup

1. **Install** the plugin zip (Plugins → Add New → Upload Plugin) and activate it.
2. **Translator → Languages:** choose the default language (usually your site language), the target language and the URL prefix (`bn` gives `/bn/…`). Save.
3. **Translator → Translation:** choose a main provider, enter its key, press **Test connection**, and set limits (section 3). Until a provider is chosen and tested, nothing is sent anywhere and translated pages show the original text.
4. **Add the language switcher:** Translator → Language switcher. Use the floating button, the block, the shortcode `[wst_switcher]`, or a menu item (Appearance → Menus).
5. **Translate:** visit translated pages (new texts are queued on the first visit), or use **Translate entire site** on the Overview, which scans every page and shows the estimated characters against your monthly budget before anything is queued.

The **Overview** shows what is left to do, the queue and the coverage per page. If WP-Cron is slow on your host, **Process queue now** runs the queue at once.

## 2. Choosing a provider

| Provider | Good for | Watch out for |
|---|---|---|
| **TranslateX** | Getting started, small sites | The **free plan is for non-commercial use only** and TranslateX may store the texts you send and use them for training. Business, shop or client sites need a paid plan. The card shows this warning while the free plan is selected. |
| **Microsoft Translator** | Business sites, many languages | Needs an Azure Translator resource: the key and, for regional resources, the region. The free tier (F0) has 2 million characters per month. |
| **Google Gemini** | Natural wording | The free tier uses your texts to improve Google's products. Free-tier limits differ per Google project: look them up in Google AI Studio and enter them on the card. |

You can choose a **fallback provider**. It takes over when the main provider is paused, out of budget, rejects the key or cannot translate the language pair, and the main provider takes over again later. Keys are write-only: once saved they are never shown again, not even to administrators. A key can also come from `wp-config.php` or a server environment variable; the card then says where it comes from.

## 3. Rate limits (0 = unlimited)

Each provider card has a **Limits** section. Leave a field empty to use the default shown under it.

- **Requests per minute / per day, Characters per minute:** how fast the queue may send texts. **0 means no limit.** Set these to your plan's limits so the provider never refuses requests; the queue simply waits.
- **Strings per request / Characters per request:** how much goes into one request. **0 means the provider's own maximum.**
- **Parallel requests (1–5)** and **Attempts before a string is marked failed (1–20)**.
- **Monthly character budget:** **0 means no budget.** A warning is logged at 80 percent; at 100 percent translation stops until the next month (the fallback provider, if any, continues within its own budget).

Daily limits reset at midnight in the provider's time zone, shown on the card.

## 4. Automatic and manual pages

- **Translator → Translation → Site mode** decides the default: **Automatically** (new texts are queued for machine translation) or **Manually** (only translations you enter are shown; nothing is sent to a provider unless you ask in the editor).
- **Translator → Pages** sets a mode per page: **Default** (follow the path rules and site mode), **Automatic**, **Manual only** or **Off (not translated)**. The same setting is in the post editor's sidebar. **Path rules** (for example `/shop/*`) set the mode for whole sections.
- **Off** pages have no translated version: visitors to `/bn/that-page/` are sent to the original page, or shown it untranslated (Translation → "When a visitor opens the translated address of a page that is off"), and the page gets no hreflang link and is left out of the translated sitemap.
- Cart, checkout and account pages are never recorded, because they can show personal data.

## 5. The translation editor

Open it from **Translator → Editor**, from Pages, from the **Translate** item in the admin bar on any page, or from a post's sidebar.

1. Choose a page and press **Scan page**. The editor loads the translated page as a visitor would and lists every text on it.
2. Click a translation to edit it; it saves when you leave the field (**Saved** appears). Edited translations are **manual** and are never overwritten by machine translation.
3. Use the filters (untranslated, machine, manual) and the bulk actions, for example **Translate untranslated with provider** or **Keep as manual**.
4. **Show preview** opens a safe preview of the translated page; click a text there to jump to its row. If the preview cannot load, the list still works.

Users with the **Editor** role can translate; settings stay with administrators.

## 6. CSV import and export

**Translator → Import / Export.**
- **Export** all strings, only untranslated, only machine, only manual, or one page. The file opens in any spreadsheet program; cells that start with `=`, `+`, `-` or `@` are protected so the spreadsheet does not run them as formulas.
- **Import:** choose the file, then **Check file (dry run)**. It shows new rows, rows that would change and rows with problems, without changing anything. Then choose what happens when a string is already translated: **Overwrite machine translations, keep manual ones** (default), **Add only: keep every existing translation**, or **Overwrite all, including manual translations**. Choose whether imported rows count as manual or machine translations, and press **Import**.

## 7. Moving from TranslatePress

**Translator → Migration** appears when TranslatePress or its data is found. While TranslatePress is active, this plugin's front end stays off, so the two never translate the same page.

1. Compare the languages and URL slugs and copy TranslatePress's (so your translated URLs stay the same).
2. **Dry run**, then **Import**. TranslatePress's tables are only read, never changed. Running the import again adds nothing twice.
3. Run the **Match report** on a few important pages to see how many of their texts already have a translation.
4. **Go live** checklist: deactivate TranslatePress → **Flush now** → check slugs → scan key pages → compare translated URLs. Old `[language-switcher]` shortcodes show this plugin's switcher.

## 8. Uninstalling

Deactivating and deleting the plugin **keeps** all translations and settings, so reinstalling brings them back. To remove everything, first turn on **Translator → Advanced → Data → "Delete all translator data when the plugin is deleted"**, then deactivate and delete the plugin. Its tables, settings, keys and page modes are then removed for good.

## Getting help

**Translator → Health** checks the setup and shows the log. When asking for help, include what you did, the page address and the recent log entries. Never post your provider keys.
