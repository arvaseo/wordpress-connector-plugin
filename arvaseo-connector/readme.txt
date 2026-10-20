=== Arva SEO – Speed Booster & SEO Connector ===
Contributors: arvaseo, avdajay
Tags: pagespeed, performance, core web vitals, seo, site health
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.7.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PageSpeed fixes you switch on one at a time, and a connection to Arva SEO for Website Health, content and SEO title updates.

== Description ==

Fix the speed problems Google's PageSpeed test finds, one switch at a time, and connect this site to a website in your Arva SEO workspace.

= PageSpeed fixes =

Arva SEO → PageSpeed fixes lists each fix with the PageSpeed (Lighthouse) problem it addresses, worded as the report shows it. Every fix is off until you turn it on. You can try it first: while you try a fix, only you see the change when you're signed in, and visitors see your site as before. Turning a fix off puts your pages back as they were. Your theme files and content are never changed. The few fixes that add files say so on their cards (server rules in .htaccess, WebP and font copies in your uploads folder) and take them away again. The fixes work without an Arva SEO account.

* **Show the main image first**: the featured image on posts and pages, and on the homepage the image your latest PageSpeed test in Arva SEO named (Largest Contentful Paint image was lazily loaded; LCP request discovery)
* **Lazy-load images, videos and maps** below the first few images (Defer offscreen images)
* **Load styles faster** (critical CSS) and **Remove unused CSS**, worked out on Arva SEO's servers once for each type of page: the first screen's styles go straight into the page and stylesheets stop holding it up, or each page gets only the styles it uses with the full files loading on the first interaction. They need the connection and use the page optimizations of your Arva SEO plan (Eliminate render-blocking resources; Reduce unused CSS)
* **Defer scripts** in the page's head, leaving jQuery, consent tools and any scripts you choose alone (Eliminate render-blocking resources). Because this one can affect how a site works, it can only be turned on after you've tried it and ticked off your important pages.
* **Add missing image sizes** to media-library images (Image elements do not have explicit width and height)
* **Add the mobile viewport tag**, when PageSpeed says it's missing
* **Click-to-play videos**: YouTube embeds show a preview picture, and the player loads when someone presses play (Lazy load third-party resources with facades)
* **Faster Google Fonts**: text shows straight away while fonts load, and the browser connects early; or serve copies of the fonts from your own site (Ensure text remains visible during webfont load)
* **Delay chat widgets and trackers**: chat boxes, heatmaps and social widgets load on the visitor's first scroll, tap or key press; analytics and consent tools are never delayed unless you list them (Reduce the impact of third-party code)
* **Browser caching and compression** on Apache and LiteSpeed: year-long browser caching for images, fonts, styles and scripts, and compressed pages, as rules in .htaccess that are checked straight away and taken out again if your site doesn't answer normally (Serve static assets with an efficient cache policy; Enable text compression)
* **Remove unused WordPress extras** you choose: the emoji script, embed discovery, Dashicons for visitors, jQuery Migrate, block styles on pages without blocks
* **New uploads as WebP**, and convert the images already in your media library in the background, keeping the originals (Serve images in next-gen formats)

If you allow it on the PageSpeed fixes page ("Let Arva SEO switch these fixes", off by default), the people who manage this website in Arva SEO can try, turn on and turn off the fixes from its PageSpeed results, by the same rules as the page: a fix that can be tried goes on for everyone only after it's been tried, Defer scripts goes on only in WordPress, and turning a fix off always works. Every change is in the Activity Log with who asked.

If a speed plugin such as WP Rocket, LiteSpeed Cache, Perfmatters or FlyingPress already does one of these jobs, that fix stays off and the page tells you to use that plugin's setting, so pages are never processed twice. When the site is connected, the page also shows which fixes your homepage's latest PageSpeed test asks for, and Arva SEO's PageSpeed results link straight to the right switch.

= Arva SEO Connector =

* **Secure connection.** Approve the connection on app.arvaseo.com. No Arva SEO password is ever entered in WordPress; the site gets its own revocable, encrypted credentials.
* **Plugin inventory.** Installed plugins (including must-use and drop-ins) and themes with their versions, active state and update state, from WordPress's own data. Missing or old update data is shown as unknown, never as up to date.
* **Website Health.** Around 40 checks across core, plugins and themes, runtime, security, reliability, performance and SEO, using WordPress's Site Health tests and APIs. Arva SEO turns them into findings that say what is wrong, why it matters, how to fix it, who should do it, and how to verify and undo the change. Nothing is ever changed on your site automatically.
* **Content from Arva SEO.** Drafts written in Arva SEO arrive as drafts, pending review, scheduled or published posts, but never beyond the publishing mode you choose here (Draft only by default).
* **SEO titles and descriptions.** If an administrator allows it (off by default), the SEO titles and meta descriptions your team approves in Arva SEO are written to Yoast SEO or Rank Math for published posts, pages and products and the homepage, in bulk. Only those two fields change, the page caches are cleared, and every change can be undone from Arva SEO.
* **IndexNow.** The site serves the IndexNow key Arva SEO gives it (as /your-key.txt), so Arva SEO can tell Bing, Yandex, Seznam, Naver and Yep when you publish a post or change a page's SEO title or description, and they recrawl it sooner. No file to upload.
* **Privacy.** Nothing about the site is shared until an administrator agrees to the exact list of fields. No visitor data, user accounts, emails, passwords or settings are ever sent. Existing content isn't sent either, except the previous SEO title and meta description of a page whose SEO title or description Arva SEO changes (when allowed), so that change can be undone.

== Installation ==

1. Upload the plugin through Plugins → Add New → Upload Plugin, and activate it.
2. Open Arva SEO → Connection, review what is shared, and click Connect to Arva SEO.
3. Sign in to Arva SEO, choose the website, and approve. You return to WordPress connected.
4. In Arva SEO → Settings, choose the default author and publishing mode.

For reliable scheduled publishing on low-traffic sites, run WP-Cron from a server scheduler (see the administrator guide).

== Frequently Asked Questions ==

= Does the plugin change my site? =

Only in the ways you switch on. PageSpeed fixes change how your pages load, only after you turn each one on, and turning it off undoes it. Website Health is read-only and every finding is a recommendation. Arva SEO can only create or update posts it sent, within your publishing mode, and, if you allow it in Settings, change the SEO title and meta description of published content in Yoast SEO or Rank Math.

= Can a PageSpeed fix break my site? =

Most only add or change attributes on images and links, and visitors see the same page. Defer scripts, Delay chat widgets and trackers, Click-to-play videos and Remove unused WordPress extras can affect how some sites work, so try them first and check your important pages. Browser caching and compression changes your server's settings, so it can't be tried first; it checks your site straight away instead (see below). If something stops working, leave that page, script or image alone (see the next question) or turn the fix off on the same page. In an emergency, add `define( 'ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES', true );` to wp-config.php to switch every fix off. If you use a page cache, clear it after changing a fix.

= Can I leave a page, script or image alone? =

Yes, in Arva SEO → PageSpeed fixes → Leave things alone:

* Pages: addresses such as `/checkout/` or `/shop/*`, a "Leave this page alone" tick box when you edit a post or page, or Arva SEO in the toolbar while you view any page. Defer scripts also leaves WooCommerce and Easy Digital Downloads cart, checkout and account pages alone unless you untick it.
* Scripts (Defer scripts): a script name (handle), part of its file address such as `booking.js`, or every script from a plugin you choose.
* Images, videos and maps: a CSS class (`.hero-slide`), an id (`#main-banner`) or part of the file address (`logo.svg`). A block's Additional CSS class counts for everything inside the block. Lazy-load also respects the `skip-lazy` and `no-lazy` classes other plugins use, and every fix skips an element with the `data-arvaseo-skip` attribute.
* How many images at the top of each page always load straight away (3 unless you change it).

= Are there filters for developers? =

`arvaseo_connector_speed_fix_applies` (bool $applies, string $fix_id) turns a fix off for any condition, `arvaseo_connector_defer_script` (bool $defer, string $handle, string $src) leaves a script alone, and `arvaseo_connector_optimize_element` (bool $optimize, string $fix_id, string $tag, array $attributes) leaves an image or frame alone.

= Can it make critical CSS or remove unused CSS? =

Yes, from 1.6.0, when the site is connected: Load styles faster and Remove unused CSS. Arva SEO loads one page of each type (homepage, page, post, product, category and so on) on its servers, as a phone and as a computer, and sends back each stylesheet's critical and used CSS. Each page type uses one page optimization of your plan (Free 50 a month, more on paid plans, and an add-on of 15,000). A stylesheet whose contents change makes a new page type; clearing your cache costs nothing. When the month's page optimizations run out, page types whose styles haven't changed keep their optimized CSS and the others load their normal stylesheets, never out-of-date CSS.

= Can my agency switch the fixes from Arva SEO? =

Yes, from 1.7.0, once an administrator ticks "Let Arva SEO switch these fixes" on the PageSpeed fixes page. Arva SEO's PageSpeed results then show Try it, Turn on and Turn off next to each fix for people who manage the website's integrations there. Switches are picked up within a minute or two; the rules are the same as in WordPress, and each change is logged with the name of the person who asked. Untick the box to stop it at any time.

= Is Browser caching and compression safe? =

The rules only use standard Apache settings, wrapped so a server without those modules ignores them. Still, a server that doesn't allow one of them in .htaccess would answer every page with an error, so the plugin checks your homepage and one of its own files from the server right after adding them, and takes them out again in the same moment if either doesn't answer normally, or if the check can't be made. Turning the fix off, deactivating or deleting the plugin, or the wp-config.php switch removes them.

= Do the PageSpeed fixes need an Arva SEO account? =

No. They work as soon as the plugin is active. Connecting adds which fixes your homepage's latest PageSpeed test asks for, and before-and-after results in Arva SEO.

= Which SEO plugins are supported? =

Yoast SEO and Rank Math. The connector detects which one is active; if both are, choose one in Arva SEO → Settings. Without either, SEO titles and descriptions aren't written.

= Can a title or description change be undone? =

Yes. The connector sends Arva SEO the previous values with every change, and Undo in Arva SEO writes them back (an empty previous value restores the SEO plugin's default).

= What if I disconnect? =

Credentials are removed from this site and revoked at Arva SEO, scheduled syncs stop, and earlier findings are shown as stale.

= Is it free? =

The connector works with every Arva SEO plan, including Free.

= What happens when I delete the plugin? =

Credentials are always revoked and removed. Settings, logs and delivery records are removed too if you ticked that option in Settings. Posts are never deleted.

== External services ==

This plugin connects your site to Arva SEO (https://arvaseo.com), an SEO service, and only after an administrator connects it. Nothing is sent before then.

* **When connecting:** you are sent to app.arvaseo.com to sign in and approve; the plugin then exchanges a one-time code with api.arvaseo.com for this site's own credentials, and sends the site address, name, WordPress and plugin versions, time zone, environment type, your publishing settings, which of the plugin's PageSpeed fixes are on, and your data-sharing choice.
* **Plugin inventory and Website Health (only if you agree on the Connection screen):** installed plugins and themes with versions and update state, and the results of about 40 health checks (booleans, counts and versions only). Sent after connecting, twice a day for the inventory and once or twice a day for health checks, after plugin changes, and when you or Arva SEO ask for a recheck.
* **Content:** every 5 minutes the plugin asks api.arvaseo.com for content you sent from Arva SEO, and reports back the post ID, status and link it created.
* **Switching fixes from Arva SEO (off by default):** when allowed, the plugin asks api.arvaseo.com every 5 minutes (and when Arva SEO signals it) for fixes switched there, and reports back what it did and each fix's state.
* **Signed requests from Arva SEO:** api.arvaseo.com may call this site's /wp-json/arvaseo/v1/events endpoint to ask it to check for content or run a recheck sooner.
* **Featured images (off by default):** only from image hosts you approve.
* **Load styles faster and Remove unused CSS (PageSpeed fixes, off by default, only when connected):** the plugin sends api.arvaseo.com the address of one public page of each type, and Arva SEO's renderer loads it like a visitor, as a phone and as a computer, to work out its styles; the critical and used CSS come back to this site. Only page addresses are sent. While Arva SEO loads a page, the address carries a short-lived signed parameter so the page is served with its original styles.
* **Faster Google Fonts, "Serve them from your site" (off by default):** the plugin downloads the Google Fonts stylesheets and font files your pages already use from fonts.googleapis.com and fonts.gstatic.com, once, into your uploads folder, so visitors' browsers no longer connect to Google for them. Google's privacy policy: https://policies.google.com/privacy
* **Click-to-play videos (a PageSpeed fix, off by default):** visitors' browsers load each video's preview picture from YouTube (i.ytimg.com), and the player from youtube-nocookie.com only when they press play. YouTube's terms: https://www.youtube.com/t/terms · Google's privacy policy: https://policies.google.com/privacy

No visitor data, user accounts, emails, passwords, settings or existing content is ever sent. Arva SEO terms: https://arvaseo.com/terms/ · Privacy policy: https://arvaseo.com/privacy/

== Privacy ==

See Arva SEO → Diagnostics & Privacy for the full list of shared fields and their purpose, and the suggested text added to Settings → Privacy.

== Screenshots ==

1. PageSpeed fixes: which fixes your latest PageSpeed test calls for, and whether each one is off, being tried or on.
2. Defer scripts: try it while only you see it, check your important pages, then turn it on for everyone.
3. Connection: sign in on Arva SEO to connect, and see exactly what is shared before you do.
4. Settings: choose how far Arva SEO can go when it sends posts, from drafts only to full publishing.
5. Leave things alone: pages, images, videos and maps the PageSpeed fixes skip.

== Upgrade Notice ==

= 1.7.1 =
Trying Load styles faster or Remove unused CSS now shows administrators the same results visitors will get, also with SEO plugins that style the toolbar.

= 1.7.0 =
Switch PageSpeed fixes from Arva SEO's PageSpeed results, once you allow it on the PageSpeed fixes page (off by default).

= 1.6.0 =
Load styles faster and Remove unused CSS: critical and used CSS worked out on Arva SEO's servers, off until you turn them on.

= 1.5.0 =
Four more PageSpeed fixes, each off until you turn it on: browser caching and compression, delay chat widgets and trackers, Google Fonts from your own site, and converting existing images to WebP.

= 1.4.0 =
Leave things alone: keep chosen pages, scripts, images and videos out of the PageSpeed fixes. Nothing changes on your site by updating.

= 1.3.1 =
The plugin is now called Arva SEO – Speed Booster & SEO Connector, and Defer scripts lists the right page while you try it.

= 1.3.0 =
New PageSpeed fixes, each off until you turn it on. Nothing changes on your site by updating.

== Changelog ==

= 1.7.1 =
* Fixed: with Load styles faster or Remove unused CSS being tried, an administrator's view counted toolbar styles from other plugins (such as Yoast SEO's) as part of the page, so it was optimized as a different page from what visitors see and used an extra page optimization. Styles that depend on the toolbar, or are named after it, are now left out, like the toolbar's own.

= 1.7.0 =
* New: "Let Arva SEO switch these fixes" (PageSpeed fixes page, off by default). People who manage the website in Arva SEO can then try, turn on and turn off fixes from its PageSpeed results, by the same rules as the page; Defer scripts still goes on only in WordPress, after its checklist. Each change is in the Activity Log with who asked.

= 1.6.0 =
* New: Load styles faster. The styles each page type needs for its first screen go straight into the page, and stylesheets load without holding it up.
* New: Remove unused CSS. Each page type gets only the styles it uses; the full stylesheets load on the first scroll, tap, click or key press.
* Both are worked out by Arva SEO for each type of page (homepage, page, post, product, category…) and grouped by the stylesheets' contents, so a theme or plugin update that changes styles gets new CSS and a cache clear costs nothing. Optimize now, Check for results and Regenerate on their cards; stylesheets you choose are left alone; Leave things alone and shop pages apply.
* The readme's External services list now includes the page addresses sent for these fixes and the Google Fonts downloads of "Serve them from your site".

= 1.5.0 =
* New: Browser caching and compression (Apache and LiteSpeed). Rules in .htaccess for year-long browser caching and compression, checked from your server straight away and taken out again if your site doesn't answer normally or can't be checked. Removed when turned off, when the plugin is deactivated or deleted, and with the wp-config.php switch. On nginx, the card shows the rules to give your host.
* New: Delay chat widgets and trackers. Known chat, heatmap and social widgets (Tawk.to, Crisp, Intercom, LiveChat, Tidio, Zendesk, Drift, HubSpot chat, Hotjar, Clarity, Facebook, X and Pinterest widgets, Elfsight) and scripts you list load on the first scroll, tap, click or key press. Analytics, tag managers, consent tools and scripts that other scripts need are never delayed.
* New: Faster Google Fonts can serve copies of the fonts from your site (uploads/arvaseo-fonts), made in the background; Google's fonts are used until a copy is ready.
* New: New uploads as WebP can convert the images already in your media library, a few at a time in the background, with progress, Stop and Undo. Copies sit beside the originals and are kept only when smaller.
* Show the main image first: on the homepage, the image your latest PageSpeed test in Arva SEO found as the largest thing on the screen loads first too.
* The cards say which fixes change your server's settings, and a fix that can't be tried first says so.

= 1.4.0 =
* New: Leave things alone (Arva SEO → PageSpeed fixes). Keep pages out of every fix by address (with * wildcards), with a tick box in the editor, or from the toolbar while viewing a page.
* Defer scripts leaves alone scripts by name or part of their file address, every script of a plugin you choose, and WooCommerce and Easy Digital Downloads cart, checkout and account pages (on by default; untick to include them). Each script it deferred while you try it has its own Leave alone button.
* Lazy-load, Add missing image sizes and Click-to-play videos leave alone images and frames by CSS class, id or file address, including everything inside a block with a listed class. Lazy-load respects the skip-lazy and no-lazy markers other plugins use.
* Choose how many images at the top of each page always load straight away.
* The toolbar's Arva SEO item shows while any fix is on, not only while trying one.
* Filters for developers: arvaseo_connector_speed_fix_applies, arvaseo_connector_defer_script, arvaseo_connector_optimize_element.
* Fix: Faster Google Fonts kept only the last font when one Google Fonts link asked for several, so the others didn't load. Every font now stays.

= 1.3.1 =
* The plugin is now called Arva SEO – Speed Booster & SEO Connector. Its address on WordPress.org, its settings and everything it does stay the same.
* Fix: while you try Defer scripts, the list of scripts it deferred now shows the page you opened, not the browser's background request for the site icon.

= 1.3.0 =
* New: PageSpeed fixes (Arva SEO → PageSpeed fixes). Nine fixes for the problems Google's PageSpeed test finds, each off until you turn it on, with a Try it mode only you see: show the main image first, lazy-load images, videos and maps, defer scripts, add missing image sizes, the mobile viewport tag, click-to-play YouTube videos, faster Google Fonts, removing unused WordPress extras, and WebP for new uploads.
* Defer scripts can only be turned on after you've tried it and ticked off your important pages, shows a reminder for a week after, and lets you leave chosen scripts alone.
* A fix another speed plugin already does (WP Rocket, LiteSpeed Cache, Perfmatters, FlyingPress and others) stays off, so pages aren't processed twice.
* When connected: which fixes your homepage's latest PageSpeed test asks for, and Arva SEO learns which fixes are on.

= 1.2.0 =
* New: IndexNow. The site serves its IndexNow key file by itself, so Arva SEO can ask Bing, Yandex, Seznam, Naver and Yep to recrawl pages after a post is published or an SEO title or description changes. The key is received when the plugin checks in with Arva SEO and stops being served when the site disconnects.

= 1.1.0 =
* New: bulk SEO title and meta description updates from Arva SEO into Yoast SEO or Rank Math, for published posts, pages, products and the homepage. Off until an administrator allows it in Settings; every change can be undone from Arva SEO.
* Yoast SEO's cache (indexables) is rebuilt for each changed post, and page caches are cleared (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache).
* Settings: the SEO plugin is detected automatically; adding SEO fields to new drafts is its own setting.

= 1.0.0 =
* First release: secure connection, plugin inventory, Website Health with assessed findings, content delivery with a local publishing ceiling, activity log, diagnostics.
