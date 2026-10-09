=== WP Site Translator ===
Tags: translation, multilingual, machine translation, bilingual, language switcher
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate the whole site between two languages with queued machine translation and a conflict-proof editor.

== Description ==

WP Site Translator serves your site in two languages: the default language and one target language, in either direction (for example English and Bengali, or Arabic and English). The translated site lives under a language prefix such as `/bn/`, so page caches keep working.

* **Machine translation through a queue.** Strings found on pages are queued and translated in the background by TranslateX, Microsoft Translator or Google Gemini, within the rate limits and monthly character budget you set, with an optional fallback provider. Pages never wait for a translation service; untranslated text shows in the original language until its translation arrives.
* **Translation editor.** Scan a page, then edit its strings in a list with autosave, filters and bulk actions, next to a safe preview. The editor runs outside your theme and other plugins' scripts, so their errors cannot break it.
* **Page modes.** Each page, path or the whole site can be automatic, manual (only your own translations) or off (not translated).
* **Language switcher** as a shortcode, block, menu item or floating button, with hreflang links, translated titles and descriptions and a translated XML sitemap (also listed in Yoast SEO and Rank Math sitemaps).
* **Extras:** CSV import and export, an importer for TranslatePress dictionaries, translation of AJAX and REST responses (WooCommerce cart fragments), digits in the target script, a browser-language suggestion bar and never-translate terms.

Not included: more than two languages, translated URL slugs, media replacement, `.po` / gettext translation, e-mails and multisite.

== External services ==

The plugin sends text to a translation service only when you configure one and enter its key (Translator → Translation). It sends the texts to translate (strings from your pages, protected terms and tags replaced by placeholders), the language pair and your key; nothing about your visitors. Requests run in the background queue or when you press a translate or test button; viewing a page never calls a service.

* **TranslateX** (api.translatex.com). Terms: https://translatex.com/terms — Privacy: https://translatex.com/privacy-policy — On the free plan TranslateX may store and use submitted text.
* **Microsoft Translator** (api.cognitive.microsofttranslator.com, Azure AI Translator). Terms: https://www.microsoft.com/servicesagreement — Privacy: https://privacy.microsoft.com/privacystatement — Data handling: https://learn.microsoft.com/azure/ai-foundry/responsible-ai/translator/data-privacy-security
* **Google Gemini API** (generativelanguage.googleapis.com). Terms: https://ai.google.dev/gemini-api/terms — Privacy: https://policies.google.com/privacy — On the free tier Google uses submitted content to improve its products.

The plugin sets one first-party cookie, `wst_lang_choice` (180 days), only when the language suggestion is turned on and a visitor dismisses the bar or picks a language. It stores no personal data on the server.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin and activate it.
2. Translator → Languages: choose the default and the target language and the URL prefix.
3. Translator → Translation: enter a provider key, press "Test connection" and set limits and a monthly budget.
4. Visit a translated page or use "Translate entire site" on the Overview; translations arrive through the queue (WP-Cron, or `wp wst queue run`).
5. Add the language switcher (block, shortcode `[wst_switcher]`, menu item or floating button).

Moving from TranslatePress: Translator → Migration imports its dictionary and settings while TranslatePress is still active (our front end stays off until you deactivate it) and walks you through going live.

== Frequently Asked Questions ==

= Does it work with page caches? =

Yes. The language is in the URL and the server never varies a page by cookie. Pages that still have strings in the queue are marked uncacheable for a short time, and LiteSpeed Cache and WP Rocket are purged for a page when its translations finish.

= What happens to my translations if I uninstall? =

They stay, unless you turn on Advanced → Data → "Delete all data on uninstall" first. With it on, uninstalling removes the plugin's tables, options, page settings and capability.

= Where do I see the provider's free-tier limits? =

Google publishes Gemini limits per project in AI Studio only; enter them on the Gemini card. Microsoft's free tier (F0) allows 2 million characters a month. TranslateX shows its limit in each response.

== Changelog ==

= 0.1.0 =
* First release.
