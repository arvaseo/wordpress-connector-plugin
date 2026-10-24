# Bundled minifier libraries

- `matthiasmullie/minify` **1.3.75**, MIT: https://github.com/matthiasmullie/minify/tree/1.3.75
- `matthiasmullie/path-converter` **1.1.3**, MIT, upstream commit `e7d13b2c7e2f2268e1424aaed02085518afa02d9`: https://github.com/matthiasmullie/path-converter/tree/1.1.3

Licenses are included in each folder. Copied from each upstream tag's `src/`; Minify also includes its required `data/`. Changes are restricted to prefixing namespaces/references under `ArvaSEO\Connector\Vendor` and adjusting JS's data directory to the bundled layout. These avoid conflicts with another WordPress plugin's library versions; no Composer installation is required on the customer's server.

Arva SEO's `Speed\InlineCssMinifier` and `Speed\InlineJsMinifier` adapters accept already validated CSS/JS text. File and @import loading are disabled. CSS URLs are rebased with the library's converter so fonts/images/imported sheets resolve from the cached file. Neither adapter requests remote resources, combines scripts, rewrites theme files, or changes script loading order.

WordPress integration uses documented `style_loader_tag` and `script_loader_tag` filters: https://developer.wordpress.org/reference/hooks/style_loader_tag/ and https://developer.wordpress.org/reference/hooks/script_loader_tag/.
