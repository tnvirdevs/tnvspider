# Sitemap fixtures

- `sitemap.xsd`, `siteindex.xsd`: the official Sitemap 0.9 schemas from
  https://www.sitemaps.org/schemas/sitemap/0.9/ (sitemaps.org, licensed under
  Creative Commons Attribution-ShareAlike 2.5). Used by
  `tests/Integration/Sitemap/SitemapTest.php` to validate generated XML.
- `rank-math-helper.php`: a stand-in for the public indexability checks of
  Rank Math's `RankMath\Helper`, so the noindex rules can be tested without
  the plugin.
