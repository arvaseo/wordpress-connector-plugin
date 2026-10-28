=== Arva SEO – Speed Booster & Security Scanner ===
Contributors: arvaseo, avdajay
Tags: pagespeed, performance, security, malware scanner, core web vitals
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.11.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PageSpeed fixes you switch on one at a time, a free security scan of your files, and a connection to Arva SEO for health, content and SEO titles.

== Description ==

Improve how your WordPress site loads, scan for known security problems, and optionally connect it to your Arva SEO workspace. Local speed fixes and the security scan work without an Arva SEO account. Connected features use the service and allowances of your plan.

= PageSpeed fixes you control =

Arva SEO → Speed explains each fix and the PageSpeed (Lighthouse) problems it addresses. Every fix starts off. Try mode shows supported changes only to signed-in administrators before you enable them for visitors. Server-level caching and compression instead check the site when enabled.

* **Page caching:** serve saved HTML without running WordPress, through .htaccess or PHP. Signed-in users, personal pages, carts and checkouts are excluded. Cached pages are cleared when relevant content or settings change.
* **Images and layout:** prioritize featured images and the homepage image identified by a connected PageSpeed test; lazy-load images, videos and maps below the first few images; add missing media-library image dimensions and the mobile viewport tag when needed.
* **Critical and used CSS:** Load styles faster supplies first-screen styles; Remove unused CSS supplies the styles a page uses, with full stylesheets available on interaction. When Combine CSS is on, compatible styles are combined first; both results are generated from those same combined files. Arva SEO generates these on its servers using your plan's page optimizations. Choose generation by page type or URL, inline or cached-file used CSS, and an inline or external asynchronous loader. Original styles load until suitable results are ready.
* **CSS and JavaScript minification:** smaller cached copies of supported local files, plus separate controls for minifying inline style blocks and classic scripts in their existing tags. Unsupported files and code stay unchanged.
* **CSS and JavaScript combining:** bundle compatible neighboring files in order. Optional inline combining requires approval of an exact public static snippet; a changed snippet stops matching its approval. External combining requires exact approved public HTTPS URLs and background caching. Incompatible scripts, integrity-protected resources and managed critical/used CSS stay separate. Fewer requests do not always improve performance.
* **Script loading:** defer supported scripts until HTML is parsed, or delay supported independent classic scripts until interaction. Dependencies, consent tools, protected measurement scripts and your exclusions are retained. Changing a public loading mode returns it to preview; enabling it requires checking your important pages.
* **Chat and third-party widgets:** separately delay supported chat, heatmap and social widgets until interaction. Analytics and consent tools are not selected automatically; you can list additional scripts.
* **Fonts:** optimize Google Fonts delivery, optionally serve local copies, and apply font-display: swap to supported styles so fallback text can appear while fonts load.
* **YouTube facades:** show a preview image and load the player when someone presses play.
* **Browser caching and compression:** checked .htaccess rules for Apache and LiteSpeed, with backups and rollback if the site does not respond normally. Other servers can use the suggested rules.
* **WordPress extras:** choose whether to remove emoji scripts, embed discovery, visitor Dashicons, jQuery Migrate or unneeded block styles.
* **Image optimization:** make WebP copies of new or existing uploads while keeping originals. Compress existing Media Library JPEG, PNG and supported WebP images in background batches, or select images with bulk actions. See saved bytes and restore originals when backups were kept. WebP compression is local; optional consented cloud compression sends JPEG/PNG images to Arva SEO and uses your image allowance only when it makes an image smaller.

Use page, script, plugin and element exclusions to leave sensitive parts of your site alone. Defer/delay and CSS delivery leave shop pages alone by default. Turning delivery fixes off restores their normal behavior; image changes have their own restore controls. Theme source files and post content are not rewritten by delivery fixes. Keep backups and test menus, forms, booking flows and checkout before enabling changes for everyone.

When another supported speed plugin handles a job, Arva SEO leaves it to that plugin. Deactivation is offered only as an explicit administrator action. An emergency wp-config.php switch can disable all speed fixes; see the FAQ.

Connected PageSpeed results link to the matching controls. Remote switching is off by default and needs administrator approval. Authorized workspace managers can then request supported changes, subject to the same rules; Defer scripts still requires approval in WordPress. Changes are recorded in Activity.

= Cloudflare =

Optionally connect a Cloudflare API token on the Speed page, without an Arva SEO account. The token is stored encrypted. The plugin clears Cloudflare's cached pages when relevant content, delivery settings or optimized assets change, and checks for Rocket Loader conflicts. You can disable duplicate post purges if Cloudflare's own plugin already handles them.

= Security scan and chosen repairs =

Arva SEO → Health → Security scans locally in background batches, weekly or when you choose Scan now. No account is required. It checks:

* WordPress core and directory plugins against official WordPress.org checksums, including changed, missing and unexpected files.
* PHP in uploads, disguised code, suspicious patterns in nonofficial files, injected redirects and .htaccess rules.
* Exposed configuration, repository, debug, backup and database files; directory listings; and security-related settings.
* New or changed theme, premium-plugin, must-use-plugin and drop-in files, including selected added-line evidence.
* External scripts loaded by sample pages and where they were added, plus dashboard theme/plugin file edits.

Results explain the finding and suggested action. Nothing is repaired automatically. Choose to restore an official file, quarantine unexpected PHP or disable dashboard file editing; supported repairs can be undone for 30 days. Mark an expected result as trusted until its file changes.

Connected sites can receive updated security patterns, known-malware fingerprints, vulnerability findings from Wordfence Intelligence and optional alerts. Sharing scan evidence requires separate consent. A scan detects known signs of trouble; it cannot prove a site is clean and does not replace a firewall, backups, updates or professional cleanup.

Optional deep scans need separate administrator consent and are started individually. Code files not matched to official copies are sent to Arva SEO for malware checks and deleted when the scan finishes. Configuration secrets, logs, database exports, backups and media are excluded. Deep scans use the service's applicable allowance.

= Optional Arva SEO connection =

Approve the connection on app.arvaseo.com without entering an Arva SEO password in WordPress. Each site receives revocable encrypted credentials.

* **Inventory and Website Health:** share installed plugin/theme versions and approved health checks for findings, tasks and reports.
* **Content delivery:** receive drafts, pending-review, scheduled or published posts within your chosen publishing ceiling; Draft only is the default.
* **SEO updates:** separately allow approved SEO titles and meta descriptions for published posts, pages, products and the homepage in Yoast SEO or Rank Math. Only those fields change; caches are cleared and updates can be undone.
* **IndexNow:** serve the key supplied by Arva SEO for its supported search-engine notifications.

Review shared fields in Settings → Diagnostics and data sharing. External services, consent requirements and data handling are detailed below and in the FAQ.

== Installation ==

1. Upload the plugin through Plugins → Add New → Upload Plugin, and activate it.
2. Open Arva SEO → Get started, review what is shared, and click Connect to Arva SEO.
3. Sign in to Arva SEO, choose the website, and approve. You return to WordPress connected.
4. In Arva SEO → Settings, choose the default author and publishing mode.

For reliable scheduled publishing on low-traffic sites, run WP-Cron from a server scheduler (see the administrator guide).

== Frequently Asked Questions ==

= Can I combine CSS and JavaScript? =

Yes. Arva SEO → Speed has separate Combine CSS and Combine JavaScript controls. Each starts off and has a Try mode visible only to administrators. Compatible neighboring local files are bundled in their existing order. CSS image and font addresses keep working; inline code, asynchronous or module scripts, excluded files and Critical CSS/Remove unused CSS delivery stay separate. Minification remains a separate setting. Fewer requests do not always improve performance, so check your site before enabling a control for visitors.


= Does the plugin change my site? =

Only in the ways you switch on. PageSpeed fixes change how your pages load, only after you turn each one on, and turning it off undoes it. When a change alters what visitors see, the plugin also clears the page caches it can reach (cache plugins, your host's cache, Cloudflare) at most every 15 minutes, and once a day it asks your own site for its homepage, as a visitor would, to read its cache headers and find the caches in front of it. Website Health and the security scan are read-only: they report, and every finding is a recommendation. Arva SEO can only create or update posts it sent, within your publishing mode, and, if you allow it in Settings, change the SEO title and meta description of published content in Yoast SEO or Rank Math.

= What happens to the fixes I use when the plugin updates? =

Nothing changes silently. An update that only adds an option (off until you turn it on) or only makes a fix do less is applied, and Arva SEO → Speed lists it under "What changed". An update that could make a page work differently for visitors waits for you: your visitors keep the version you checked, you see the new one while you're signed in, and the fix shows "Update to check" with three choices: use it for visitors, keep the previous version, or turn the fix off. The earlier version stays at least 90 days. Before it is retired, a connected site is reminded in Arva SEO; when the day comes, the fix goes back to being tried so you can check the new version.

= Is the security scan a firewall or a malware cleaner? =

No. It finds problems and says what to do, and it doesn't block traffic or log-in attempts. It can put back official copies of WordPress and plugin files and move PHP out of the uploads folder into quarantine, when you choose to, and each can be undone for 30 days. A hacked theme, a database or passwords still need a clean-up, which your host or developer can help with.

= What does a deep scan send? =

Only when an administrator has allowed deep scans and starts one: the code files the security scan couldn't match to an official copy, with their paths inside the site, their SHA-256 fingerprints and sizes. Configuration files, logs, database exports, backups and media are never sent, and the files are deleted on Arva SEO's servers as soon as the scan finishes.

= Does the security scan slow my site down? =

It runs in the background in batches of about 20 seconds with pauses in between, reading files only up to 2 MB for patterns, and stops after 150,000 files. You can stop a scan at any time, and turn the weekly scan off in Settings.

= What does the security scan send to Arva SEO? =

Nothing, unless the site is connected and an administrator ticks "Also share the security scan's results" when connecting or in Settings → Diagnostics and data sharing. Then it sends each open problem's kind, severity, the file's path inside the site and its SHA-256 fingerprint (for suspicious code, the pattern's name, the line and the start of that line), and how many files the scan checked. The files themselves are never sent.

From 2.7.0, with that consent given again for the new fields (the Health page asks once), it also sends: up to 10 lines added to a file that's new or changed since the last scan; the host names and addresses of scripts your pages load from other websites, with where each is added (a file and line, a setting's name or a post's ID); the kind, path and fingerprint of problems marked as expected; and, when a file is saved with WordPress's file editor, its path, the time, the username and display name of who saved it, the IP address the request came from (and the one a proxy in front of the site reported) and up to 10 added lines. Until then the results are shared as before, without these.

= Can a PageSpeed fix break my site? =

Most only add or change attributes on images and links, and visitors see the same page. Defer scripts, Delay chat widgets and trackers, Click-to-play videos and Remove unused WordPress extras can affect how some sites work, so try them first and check your important pages. Page caching and Browser caching and compression change your server's settings, so they can't be tried first; they check your site straight away instead (see below). If something stops working, leave that page, script or image alone (see the next question) or turn the fix off on the same page. In an emergency, add `define( 'ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES', true );` to wp-config.php to switch every fix off. Arva SEO's own page cache clears itself when a fix changes, and so do the other caches it can reach (Arva SEO → Speed → Other caches on this site lists them); a cache it can't reach, such as a host's CDN, is listed there with how to clear it.

= Does it work with Cloudflare? =

Yes. In Arva SEO → Speed → Cloudflare, paste a Cloudflare API token with Zone → Zone → Read and Zone → Cache Purge → Purge for your site's domain (add Zone → Zone Settings → Read so Rocket Loader can be checked). The token is stored encrypted and only ever sent to Cloudflare. If Cloudflare's own plugin already clears a post's pages when it changes, switch that part off here; the plugin still clears Cloudflare's copy when a fix changes.

= Can I leave a page, script or image alone? =

Yes, in Arva SEO → Speed → Leave things alone:

* Pages: addresses such as `/checkout/` or `/shop/*`, a "Leave this page alone" tick box when you edit a post or page, or Arva SEO in the toolbar while you view any page. Defer scripts also leaves WooCommerce and Easy Digital Downloads cart, checkout and account pages alone unless you untick it.
* Scripts (Defer scripts): a script name (handle), part of its file address such as `booking.js`, or every script from a plugin you choose.
* Images, videos and maps: a CSS class (`.hero-slide`), an id (`#main-banner`) or part of the file address (`logo.svg`). A block's Additional CSS class counts for everything inside the block. Lazy-load also respects the `skip-lazy` and `no-lazy` classes other plugins use, and every fix skips an element with the `data-arvaseo-skip` attribute.
* How many images at the top of each page always load straight away (3 unless you change it).

= Are there filters for developers? =

`arvaseo_connector_speed_fix_applies` (bool $applies, string $fix_id) turns a fix off for any condition, `arvaseo_connector_defer_script` (bool $defer, string $handle, string $src) leaves a script alone, and `arvaseo_connector_optimize_element` (bool $optimize, string $fix_id, string $tag, array $attributes) leaves an image or frame alone.

= Can it make critical CSS or remove unused CSS? =

Yes, from 1.6.0, when the site is connected: Load styles faster and Remove unused CSS. Arva SEO loads one page of each type (homepage, page, post, product, category and so on) on its servers, as a phone and as a computer, and sends back each stylesheet's critical and used CSS. Each page type uses one page optimization of your plan (Free 50 a month, more on paid plans, and an add-on of 15,000). A stylesheet whose contents change makes a new page type; clearing your cache costs nothing. When the month's page optimizations run out, page types whose styles haven't changed keep their optimized CSS and the others load their normal stylesheets, never out-of-date CSS.

= Can my agency switch the fixes from Arva SEO? =

Yes, from 1.7.0, once an administrator ticks "Let Arva SEO switch these fixes" on the Speed page. Arva SEO's PageSpeed results then show Try it, Turn on and Turn off next to each fix for people who manage the website's integrations there. Switches are picked up within a minute or two; the rules are the same as in WordPress, and each change is logged with the name of the person who asked. Untick the box to stop it at any time.

= Does it cache pages? =

Yes, from 2.6.0, with Page caching (off until you turn it on). Each page is saved as ready-made HTML the first time a visitor opens it, and the next visitors get that copy without WordPress running. On Apache and LiteSpeed, rules at the top of .htaccess let the server send it without PHP; the plugin keeps a copy of your .htaccess from before it changed it, which you can download from the Speed page. Elsewhere (nginx, for example) a small advanced-cache.php file sends it before WordPress loads, and the plugin adds `define( 'WP_CACHE', true );` to wp-config.php, or tells you the line to add when it can't.

Turning it on checks your homepage from the server: made once, then served from the cache. If it isn't, everything is taken out again. Signed-in users, visitors with a comment, password or cart, carts, checkouts and account pages, searches, addresses with a query (tracking ones like utm_source are fine) and pages that set a cookie are never cached, nor pages you leave alone. Saved pages are made again after 10 hours; a post's pages are cleared when it changes, and every page when your theme, menus, widgets or a fix change, or when you press Clear the cache (also in the toolbar). Cloudflare's copy is cleared too when it's connected. After clearing, your main pages are refilled in the background, a few at a time. Turn on "A separate copy for phones" only if your theme sends phones different HTML.

If WP Rocket, W3 Total Cache or another cache plugin is active, Arva SEO leaves page caching to it. If your host caches pages itself (SiteGround, LiteSpeed Cache, WP Engine, Kinsta, Cloudways), that's kept by default, and you can choose Arva SEO's instead; the card says how to turn the host's cache off. Turning it off, deactivating or deleting the plugin removes the rules, the file, the line it added and the saved pages.

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

Credentials are always revoked and removed. Settings, logs, delivery records and the files the PageSpeed fixes made are removed too if you ticked that option in Settings; the plugin clears the caches it can reach first, and the files stay if a cache it can't clear may still show pages that use them. Posts are never deleted.

== External services ==

Connecting to Arva SEO (https://arvaseo.com) is optional and starts only when an administrator connects the site. Local fixes work without an account. The optional features below contact their listed services only when you enable or use them.

* **When connecting:** you are sent to app.arvaseo.com to sign in and approve; the plugin then exchanges a one-time code with api.arvaseo.com for this site's own credentials, and sends the site address, name, WordPress and plugin versions, time zone, environment type, your publishing settings, which of the plugin's PageSpeed fixes are on, which of the plugin's update notes applied to this site (their ids and dates) and, for an update to a fix you use, whether it waits for your check, you kept the previous version or visitors get it, which page caches and CDNs the plugin found in front of the site (their names, whether the plugin clears them or you do, how you clear the ones that are yours, when each was last cleared, and how many old generated files are kept because of them), and your data-sharing choice.
* **Cache check (once a day, no account needed; the request goes only to your own site):** the plugin requests your own homepage from your own server, as a visitor would, without cookies or a login, reads a fixed list of its response headers (for example cf-cache-status, x-litespeed-cache, age and cache-control) and the comments cache plugins leave in the page, and keeps only the names of the caches it found. When the site is connected, those names are sent with the site's details (see above). Also, when it clears a LiteSpeed server cache from a command line or scheduled task, it asks your site for a page that answers with LiteSpeed's purge header.
* **Plugin inventory and Website Health (only if you agree when connecting, or later in Settings → Diagnostics and data sharing):** installed plugins and themes with versions and update state, and the results of about 40 health checks (booleans, counts and versions only). Sent after connecting, twice a day for the inventory and once or twice a day for health checks, after plugin changes, and when you or Arva SEO ask for a recheck.
* **Security scan results (off by default, a separate choice):** after each scan, when an administrator agreed to share them, each open problem's kind, severity, a key, the file's path inside the site and its SHA-256 fingerprint, and for suspicious code the pattern's name, line and the start of that line, with how many files were checked. Never the files themselves.
* **Security feed (only when connected):** once a day the plugin downloads Arva SEO's security feed from api.arvaseo.com (fingerprints of known malware and of files found safe, PHP's latest versions, new patterns), sending only the version it has.
* **Deep scans (off by default, a separate choice, and each one started by an administrator):** the code files the security scan couldn't match to an official copy (the theme, premium and custom plugins, and anything unexpected), with their paths inside the site, SHA-256 fingerprints and sizes, 2 MB each and 50 MB in all at most, sent to api.arvaseo.com to be checked for known malware and deleted as soon as the scan finishes. Never wp-config.php, .env files, logs, database exports, backups or media. The plugin then fetches what was found.
* **Content:** every 5 minutes the plugin asks api.arvaseo.com for content you sent from Arva SEO, and reports back the post ID, status and link it created.
* **Switching fixes from Arva SEO (off by default):** when allowed, the plugin asks api.arvaseo.com every 5 minutes (and when Arva SEO signals it) for fixes switched there, and reports back what it did and each fix's state.
* **Signed requests from Arva SEO:** api.arvaseo.com may call this site's /wp-json/arvaseo/v1/events endpoint to ask it to check for content or run a recheck sooner.
* **Featured images (off by default):** only from image hosts you approve.
* **Combine external CSS/JavaScript (off by default):** only exact public HTTPS asset URLs the administrator approves. The site's server requests those public files in the background, without visitor cookies, account credentials or page content, and keeps bounded six-hour copies for combining. The selected asset host receives the requested address and the server's IP address. Personal, signed, redirected and unsupported responses are not cached. No new files are requested merely because a URL is approved; a page must already use that resource.
* **Load styles faster and Remove unused CSS (PageSpeed fixes, off by default, only when connected):** the plugin sends api.arvaseo.com the address of one public page of each type, and Arva SEO's renderer loads it like a visitor to work out its styles; the critical and used CSS come back to this site. Only page addresses are sent. While Arva SEO loads a page, the address carries a short-lived signed parameter so the page is served with its original styles.
* **Faster Google Fonts, "Serve them from your site" (off by default):** the plugin downloads the Google Fonts stylesheets and font files your pages already use from fonts.googleapis.com and fonts.gstatic.com, once, into your uploads folder, so visitors' browsers no longer connect to Google for them. Google's privacy policy: https://policies.google.com/privacy
* **Cloudflare (off until you connect it on the Speed page):** with the API token you enter, the plugin asks api.cloudflare.com which zone your site's domain is in, reads the Rocket Loader setting, and asks Cloudflare to clear its cached copy of your pages when they change. Only page addresses and the token are sent. Cloudflare's terms: https://www.cloudflare.com/website-terms/ · Privacy policy: https://www.cloudflare.com/privacypolicy/
* **Click-to-play videos (a PageSpeed fix, off by default):** visitors' browsers load each video's preview picture from YouTube (i.ytimg.com), and the player from youtube-nocookie.com only when they press play. YouTube's terms: https://www.youtube.com/t/terms · Google's privacy policy: https://policies.google.com/privacy

Restoring a file's official copy (only when you choose it) downloads that one file from core.svn.wordpress.org or plugins.svn.wordpress.org, for your WordPress or plugin version, and uses it only when it matches WordPress.org's checksum.

The security scan also asks WordPress.org for the official checksums of your WordPress version (api.wordpress.org/core/checksums) and of each installed plugin from its directory (downloads.wordpress.org/plugin-checksums), sending only the version number, language and plugin folder names, and it requests a few files from your own site to see whether they can be downloaded. WordPress.org's privacy policy: https://wordpress.org/about/privacy/

No visitor data, user accounts, emails, passwords, settings or existing content is ever sent. Arva SEO terms: https://arvaseo.com/terms/ · Privacy policy: https://arvaseo.com/privacy/

== Privacy ==

See Arva SEO → Settings → Diagnostics and data sharing for the full list of shared fields and their purpose, and the suggested text added to Settings → Privacy.

The plugin's admin pages use the Fraunces and Inter fonts, which come with the plugin (SIL Open Font License 1.1, see assets/fonts/OFL.txt); nothing is loaded from other sites to show them.

== Screenshots ==

1. Speed: your latest PageSpeed score, the fixes it asks for, and whether each one is off, being tried or on.
2. Defer scripts: try it while only you see it, check your important pages, then turn it on for everyone.
3. Get started: the speed fixes work without an account; connect to Arva SEO when you want the rest.
4. Overview: what needs you first, with speed, Website Health and content at a glance.
5. Settings: choose how far Arva SEO can go when it sends posts, from drafts only to full publishing.
6. Leave things alone: pages, images, videos and maps the speed fixes skip.
7. Health → Security: the latest security scan, what it found, why it matters and what to do.

== Upgrade Notice ==

= 2.11.0 =
Updates that change how a fix you use works now wait for your check: visitors keep the version you checked until you choose "Use it for visitors". Arva SEO also clears more caches and lists the ones you must clear yourself. See Speed.

= 2.10.8 =
Fixes phone layouts and icons that optimized CSS could break on some themes. Optimized styles are downloaded again automatically, at no cost.

= 2.10.7 =
Optimized CSS pages still use is no longer deleted on sites with many page types. Compressing many images no longer empties other cache plugins once per image. Saved pages are refilled sooner after a clear. "Move jQuery down the page" also waits for scripts a consent tool holds back.

= 2.10.6 =
With Load styles faster or Remove unused CSS, entrance animations (Elementor's and similar) no longer play a second time when the rest of the styles arrive at the first scroll or tap. Optimized CSS that is still being worked out can no longer be left waiting after an interrupted background run.

= 2.10.5 =
Moving jQuery down the page and loading icon fonts late are now choices you turn on (Defer scripts, Load styles faster). The clean-up of minified and combined files no longer deletes files another page cache may still use.

= 2.10.4 =
Icon fonts that a theme or widget prints into the page itself (Font Awesome and similar) load once the page has loaded instead of holding it up, while Load styles faster or Remove unused CSS is on. Faster Google Fonts served from your site now works with themes that add the time to font addresses.

= 2.10.3 =
Generated CSS now reaches stylesheets with capital letters in their names (Elementor's entrance animations, some themes' fonts) and the same file printed under several names, so they stop blocking the first paint. Defer scripts moves WordPress's jQuery from the head to just before the first script that may use it. Full minified/combined folders clean up unused files instead of stopping for good, and scripts versioned with the time no longer get a new copy on every page view.

= 2.10.2 =
Combines enabled CSS groups before generating critical and used CSS. Generation and delivery use the same stylesheet groups; changed combined files get fresh results, while full styles remain available until matching results are ready. Timestamp-only cache busters on verified static local CSS reuse the same minified files, bundles and generated results instead of using another page optimization on each visit.

= 2.10.1 =
Clears connected page and CDN caches after image compression changes files, so cached pages do not keep links to replaced WebP copies. Original-image backups and existing compression settings are preserved.

= 2.10.0 =
Adds advanced inline/external CSS and JS controls, CSS delivery choices, delayed loading mode, font-display swap, and Media Library compression actions. New switches start Off; existing delivery defaults are retained. Review approved public resources and preview changes before turning them on.

= 2.9.0 =
New optional Combine CSS and Combine JavaScript controls in Arva SEO → Speed. Both start off. Existing minification, Critical CSS and script-loading settings are preserved.


= 2.8.1 =
Completed optimized CSS downloads reliably on sites with large stylesheets. Existing results are fetched in bounded individual requests, without regenerating them. Includes the new opt-in minification and connection hints from 2.8.0.

= 2.8.0 =
New opt-in CSS/JavaScript minification and DNS prefetch/preconnect. Fixes changing Google Fonts timestamps invalidating generated CSS. Existing speed settings stay as chosen; cached pages are refreshed once after updating.

= 2.7.0 =
The security scan reports theme and premium plugin files changed since the last scan, and scripts from new or hiding domains; dashboard file edits are emailed at once. The main image loads first on every tested page, sliders included.

= 2.6.1 =
Optimized CSS shows its progress and any page types that failed, with why, next to its buttons. Fewer false alarms for Wordfence's firewall.

= 2.6.0 =
New: Page caching, off until you turn it on. Pages are saved as ready-made HTML and sent without running WordPress, cleared when they change and refilled in the background.

= 2.5.1 =
Calmer Speed and Health pages: details open when you need them. A connection problem now says why, such as your host's DNS.

= 2.5.0 =
The security scan learns from Arva SEO every day: malware found on other websites, files found safe, new patterns, and whether your server's PHP has its latest security fixes.

= 2.4.0 =
New: Compress images, on your own server or Arva SEO's. The security scan shows its progress, and other speed plugins are listed with a Deactivate button.

= 2.3.0 =
The Speed page names other active speed plugins that do the same jobs and offers to deactivate them, and a running deep scan shows its progress.

= 2.2.1 =
Other plugins' and themes' notices no longer crowd the Arva SEO pages, and the security scan no longer reports Wordfence's and other security plugins' firewalls, or plugin logs that can't run, as signs of a hack.

= 2.2.0 =
Repairs on Health → Security: put back official copies of changed WordPress and plugin files, and move PHP that shouldn't be there into quarantine. Each one is your choice and can be undone for 30 days.

= 2.1.0 =
Deep scans: check your theme, premium plugins and other files that aren't official copies for known malware on Arva SEO's servers. Off until an administrator allows them.

= 2.0.0 =
Now Arva SEO – Speed Booster & Security Scanner: a free security scan on Health, weekly and on Scan now. It only reports what it finds; nothing on your site changes.

= 1.9.0 =
Cloudflare support: connect it on the Speed page, and Cloudflare's copy of your pages is cleared whenever a fix changes what visitors get.

= 1.8.0 =
A new look, with an Overview of what needs you first. Every fix and setting stays exactly as it was.

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

= 2.11.0 =
* New: updates that change how a fix you use works no longer reach your visitors silently. Visitors keep the version you checked; you see the new one while signed in, and an "Update to check" card on Speed lets you "Use it for visitors", "Keep the previous version" or "Turn the fix off". A toolbar item and one notice count them. The earlier version is kept at least 90 days, with a reminder before it is retired.
* New: updating from 2.10.0 keeps what you approved: fixes that are on keep 2.10.0's Load styles faster, Remove unused CSS and Combine behaviour until you use the update, and choices you had not saved keep the defaults they had. Sites that already ran an earlier 2.10 build keep what it did.
* New: Arva SEO and the AI connector can press "Use it for visitors" where you allow switching fixes remotely (never for Defer scripts). The site's details sent to Arva SEO say which update notes applied (their ids and dates) and where each update stands (waiting, kept or used).
* Listed under What changed on Speed, for the fixes you use (an update waits for your check only while its fix is on):
* Update to check: With Combine CSS on, optimized styles are now worked out from the combined files your visitors get. Each page type is worked out once again, free.
* Update to check: Optimized styles now also reach stylesheets whose names use capital letters (Elementor’s entrance animations, some Google Fonts) and stylesheets a theme prints more than once, so fewer of them hold the page up.
* Update to check: Files next to one that can’t be combined are now combined around it, in the same order. This applies to stylesheets and scripts.
* Option: New option under Defer scripts: Move jQuery down the page. It’s off until you turn it on.
* Option: New option under Load styles faster: Load icon fonts after the page. It’s off until you turn it on.
* Notice: Arva SEO’s renderer now gets your pages without the WordPress extras you removed, as visitors do.
* Notice: Entrance animations no longer play a second time when the rest of the styles arrive.
* Notice: Old generated files (unused for a week, Google Fonts copies for 30 days) are cleaned up once enough have piled up or a folder is nearly full: the caches are cleared first and the files deleted a day later, never while another cache may still use them. While a cache Arva SEO can’t clear may keep pages, the folders may grow to twice their usual limit.
* Notice: Caches are cleared when compression changes an image.
* Notice: Visitors arriving from Google Ads, Facebook, Mailchimp and similar links get the saved page too.
* Notice: Arva SEO now clears more caches when your pages change, at most every 15 minutes: WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, Cache Enabler, Hummingbird, Comet Cache, Nginx Helper, WP-Optimize, NitroPack, Proxy Cache Purge, Cloudflare’s official plugin and LiteSpeed’s server cache.
* New: Other caches on this site (Speed). Once a day Arva SEO asks your own homepage for its cache headers, and with your plugins and host it finds the caches in front of your site. It clears those it can and lists those that are yours, with how. "Clear every cache now" is there and in the toolbar; "I've cleared it" tells Arva SEO you cleared one of yours. The site's details sent to Arva SEO name them and how to clear yours (never a page or a visitor).
* New: Hostinger's CDN, Kinsta, Cloudways' Varnish, Pantheon, SpinupWP and a few cache plugins are listed as yours to clear, with how.
* Changed: other caches are cleared only for changes visitors see that Arva SEO makes, at most every 15 minutes. Theme, menu and widget changes are left to the cache plugins; saving choices you didn't change clears nothing.
* Changed: under a cache Arva SEO can't clear, only files unused for 90 days go, until you press "I've cleared it". Optimized CSS copies are cleaned up too. Cloudflare holds this back only when it keeps your pages and isn't connected. Deleting the plugin with its data clears the caches it can reach first and keeps the files while one it can't may still show pages.
* Changed: the list of tracking parameters Page caching ignores is longer (gad_source, hsa_*, mtm_* and more; ref stays), and optimized CSS is noted under the same address. Developers add more with the arvaseo_connector_page_cache_ignored_params filter.
* Changed: with more than 300 page types, the ones seen longest ago lose their optimized CSS first. Results saved by a newer version are kept and not used.
* Fixed: deactivating or deleting the plugin leaves no scheduled job behind.

= 2.10.8 =
* Fixed: optimized CSS (Load styles faster and Remove unused CSS) lost rules on some themes, because it was saved with everything from a "<" to the next ">" taken out (since 1.6.0; 2.10.0.1 fixed it for 2.10.0). Media queries written as (width<=1023.98px), which many themes use for their phone menu and header, icons drawn with SVG in data: addresses (menu arrows, form icons) and content:"<" lost their rules, sometimes with the rest of the stylesheet. Optimized CSS is now kept exactly as Arva SEO worked it out; a stylesheet whose CSS contains "</style" or a NUL byte loads as it is.
* Fixed: optimized CSS saved by earlier versions is downloaded again after updating, at no cost and without using page optimizations, at most 10 page types each run, also while both CSS fixes are off. Until then, and when Arva SEO no longer has a page type's results, it keeps its current CSS. A page type no longer tracked (on sites with more than 300) is downloaded again when a page uses its CSS. A download that brings the same CSS no longer clears page caches.
* Fixed: after a Google Fonts copy failed, downloads already waiting for the same stylesheet tried again that day instead of waiting a day.

= 2.10.7 =
* Fixed: on a site with more than 300 page types (an Elementor site with many pages, each with its own stylesheet, or "Generate CSS per URL"), 2.10.3 to 2.10.6 deleted the optimized CSS of the page types noted longest ago, even while pages used them; those pages then lost their optimized CSS, were sent again and pushed out the next ones, over and over, each time using a page optimization. Page types now make room by their last use, and their CSS is kept until no page has used it for 30 days.
* Fixed: optimized CSS that finished while visitors' pages noted new page types could lose those page types, which then waited until the next uncached visit. A page type marked ready whose CSS file is gone (a host's clean-up, a migration) is asked for again instead of waiting for good, and CSS files are written in one step.
* Fixed: since 2.10.5, every background change (each compressed image, each Google Fonts copy, each batch of optimized CSS) emptied the whole page cache of WP Rocket, W3 Total Cache, WP Super Cache and the other cache plugins Arva SEO can clear, with their refills. Background changes now clear those caches and the host's at most once every five minutes, and once more at the end; a change you make clears them straight away.
* Fixed: Page caching refilled 5 pages per background run, and runs only happen when WordPress makes a page, so on a quiet site cleared pages (and their optimized CSS) could wait hours. A run now refills for up to 10 seconds, and pages queued or cleared again during a run are no longer lost.
* Fixed: "Move jQuery down the page" no longer moves it past scripts a consent tool holds back (type="text/plain", as Complianz, CookieYes, Cookiebot and others use) that may use jQuery once they're allowed, or past an external script before it that may add scripts (a consent tool or tag manager from another site, or a site file that adds scripts).
* Changed: when the full stylesheets arrive, only animations whose keyframes came with that stylesheet end at once, and the stylesheets in use are read once instead of at each arrival.

= 2.10.6 =
* Fixed: with Load styles faster or Remove unused CSS, an entrance animation that a page's scripts start as content comes into view (Elementor's fadeInUp, for one) had no keyframes in the used CSS, so the element appeared without it; then, when the full stylesheets arrived at the visitor's first scroll, tap or key press, every such animation already on screen played again over content the visitor was reading. Animations that start only because the full styles arrived now end at once; endless animations (spinners) and those already running are left alone. Arva SEO's servers will also keep these animations in the used CSS.
* Fixed: a background run that fetches optimized CSS and was cut short (a slow download past PHP's time limit) left the page types it was working on waiting until a page that wasn't in the page cache was visited, while every visitor got the cached pages without optimized CSS. The next run is now scheduled before the downloads start.

= 2.10.5 =
* Changed: moving jQuery out of the head (2.10.3) is now a choice of its own under Defer scripts, "Move jQuery down the page", off until an administrator turns it on. Updating no longer changes what Defer scripts does on sites where it was already checked and on, and turning the choice on returns Defer scripts to preview until the pages are checked again.
* Fixed: with jQuery moved down, an async script or an inline loader before it in the head (Google Tag Manager's snippet, whose Custom HTML tags often call jQuery) could run before jQuery and fail with "jQuery is not defined". jQuery now stays in the head when anything before it may run at a time of its own and use it: an async script, an inline script that adds scripts or mentions jQuery, or an event handler that mentions it. Google's gtag.js doesn't count; developers can name others with the arvaseo_connector_script_without_jquery filter.
* Changed: loading icon fonts after the page (2.10.4) is now a choice under Load styles faster and Remove unused CSS, "Load icon fonts after the page", off until an administrator turns it on, because those icons show a moment later and text next to them can move.
* Fixed: the clean-up of full minified and combined folders (2.10.3) deleted files that pages saved by another cache could still use: WP Super Cache, Cache Enabler, WP Fastest Cache, W3 Total Cache and similar kept serving pages pointing at deleted files until they expired. Those with a documented API (WP Super Cache, W3 Total Cache, WP Rocket, WP Fastest Cache, Cache Enabler, Hummingbird, Comet Cache, Nginx Helper) are now cleared with Arva SEO's own when every page changes; while any other page cache may keep pages (another cache plugin or advanced-cache.php, Kinsta's or Cloudways' server cache, SiteGround's without its plugin, Cloudflare without the connection) nothing is deleted. Files are kept for a week after their last use instead of a day.
* Fixed: Combine CSS left a whole run of adjacent stylesheets uncombined when one of them couldn't be combined (WordPress's block library starts with an @import), while Arva SEO's renderer got a bundle for the files around it, so the stored optimized CSS matched none of them and they stayed render-blocking on posts. The others are now combined around it, in order.
* Fixed: with Combine CSS on, Arva SEO's renderer got WordPress's emoji styles and the other extras that "Remove unused WordPress extras" takes out for visitors, which split its stylesheets differently from visitors' pages. The renderer now gets the same page.
* Fixed: Faster Google Fonts, "Serve them from your site", scheduled a download with the full address, cache busters included, so until the first copy was ready a theme adding v=time() scheduled another download on every uncached page view, and each one copied the fonts again and cleared the page caches. One download is scheduled, and a copy that exists is never made again. Copies no page used for 30 days, such as those earlier versions made for each page view, are deleted in the daily maintenance.

= 2.10.4 =
* Improved: Load styles faster (and Remove unused CSS) left icon font stylesheets that a theme or widget prints into the page itself, outside WordPress's styles, holding the page up: Solace Extra's menu, for example, prints Font Awesome from a CDN for every menu item with a sub-menu, so scripts after it waited for it and its 114 KB font loaded with the first paint. Known icon libraries (Font Awesome, Line Awesome, Bootstrap Icons, Remix Icon, Boxicons, Ionicons, Material Icons and Symbols, Themify, Simple Line Icons) now load once the page has loaded, or at the first interaction if that's sooner, with a noscript copy. Stylesheets from WordPress's queue keep the used-CSS delivery; developers can decide per stylesheet with the arvaseo_connector_late_icon_style filter.
* Fixed: Faster Google Fonts, "Serve them from your site", named each copy after the full address, so a theme that adds v=time() to its Google Fonts addresses (Solace does) got a new copy on every page view: the copy was never used, a new download was scheduled each time, and each finished copy cleared the page caches again. Cache busters (v, ver) no longer name copies; existing copies keep their names.
* Fixed: css2 Google Fonts addresses naming several families shared a copy with an address naming only their last family, because repeated family= parameters were collapsed. They now get their own copy.

= 2.10.3 =
* Fixed: optimized (used/critical) CSS never applied to stylesheets whose handles contain capital letters, such as Elementor's e-animation-fadeInUp or a theme's google-fonts-Inter: their ids were stored in lower case and rejected when combining. They stayed render-blocking. Results saved by earlier versions are matched too.
* Fixed: a stylesheet printed under several handles (a theme's heading fonts, h1 to h6) only had optimized CSS for the first one, because the renderer keeps one result per address; the others stayed render-blocking. Each copy now uses that result in its own place.
* Fixed: minified copies and combined bundles were named after a script's full address, so a theme that versions scripts with the time made a new copy on every page view, filling the 250-file folders within hours. Full folders then stopped minifying and combining changed files for good. Cache busters no longer name copies; files record when pages last used them; a full folder now schedules a background clean-up of files no page used for a day, followed by a refresh of saved pages.
* Fixed: a page type waiting for optimized CSS whose scheduled sync was lost waited forever; its next visit schedules it again. Results of page types no longer tracked are deleted (daily and when the list is trimmed) instead of filling uploads.
* Improved: Defer scripts moves WordPress's jQuery (jquery-core, jquery-migrate and their inline parts) from the head to just before the first script, inline event handler or asynchronous script of the page, so it no longer blocks the first paint. Nothing moves when something in the head may use it; developers can veto with the arvaseo_connector_move_head_scripts filter.

= 2.10.1 =
* Fixed: successful image compression now notifies page and connected CDN caches, including when a larger WebP sidecar is removed. Images that do not change do not trigger a purge.

= 2.10.0 =
* New: inline CSS/JavaScript minification and explicitly approved static inline/external sources in compatible ordered bundles; external files are cached in bounded pinned background requests, never fetched while rendering pages.
* New: inline or cached-file UCSS, per-URL CSS generation, inline/external asynchronous CSS loader and supported font-display swap.
* New: deferred/delayed JavaScript loading choice under the existing script control; changing the public mode returns it to preview.
* Improved: existing-image compression is visible directly on the Speed card and available as a native Media Library bulk action, with queue/compression/savings status. Added lossless local WebP compression while retaining originals and cloud consent/allowances.
* Kept: default-off new switches, existing settings, exclusions, optimized-CSS delivery, source files and unsupported-resource fallback.

= 2.9.0 =
* New: separate opt-in Combine CSS and Combine JavaScript controls with administrator-only Try mode and existing file/page exclusions.
* Compatible neighboring local files share a cached bundle in their existing order. CSS image and font URLs are preserved; unsupported, inline, asynchronous, module and optimized-CSS delivery stays separate.
* Combining is separate from minification. Original files are untouched, failed or unsupported groups retain their original delivery, and both controls start off.


= 2.8.1 =
* Fixed: large completed CSS results no longer exceed the connector’s normal response limit when fetched together. CSS results download individually with a bounded CSS-only response allowance; other API calls retain their existing limit.
* Result checks prioritize recently requested page types and process a bounded number per run, so large old backlogs continue in the background without delaying current pages indefinitely.
* Fixed: uninstall finishes speed cleanup before removing its logs and options, avoiding writes to already-removed tables.

= 2.8.0 =
* New: Minify CSS and Minify JavaScript serve smaller cached copies of supported local files, preserve original files and loading order, and fall back to originals for unsupported or unsafe files. Both start off and respect existing exclusions and other optimizers.
* New: DNS prefetch and Preconnect for explicitly chosen external HTTPS origins, with limits, duplicate prevention and optional anonymous preconnect for fonts or CORS resources. These start off and do not change DNS records.
* Fixed: themes adding a changing v/ver timestamp to Google Fonts no longer make unchanged styles a new CSS optimization on every request. Font family, subset, display and text choices still distinguish different CSS.
* Updating refreshes cached pages once when speed fixes are on, so HTML from the previous version does not hide the updated behavior. Settings and saved CSS results are preserved.

= 2.7.0 =
* Show the main image first now works on every page you tested with PageSpeed in Arva SEO, not only the homepage, and when a slider or page builder shows the main image as a background: it's preloaded at the top of the page. WordPress's own "load first" hint no longer goes to another image, such as the logo.
* Changes sent from Arva SEO (turning a PageSpeed fix on or off, browser caching, new content and SEO titles) apply within seconds, while Arva SEO waits for the answer, instead of at the next visit that runs WordPress's scheduled tasks. The scheduled task stays as a fallback.
* Security scan, Changed since the last scan: the scan records a fingerprint of every PHP and JavaScript file in themes, child themes, must-use plugins, drop-ins and plugins that aren't WordPress.org's copies, and reports files that are new or changed since. Updates are recorded again quietly. For a theme's functions.php, header.php and footer.php and must-use plugins, the last copy is kept in your database so the finding shows the lines that were added. A change stays listed until you mark it as expected.
* A risky file that changes between weekly scans starts a scan the next day.
* Files saved with WordPress's theme or plugin file editor are recorded as it happens, with who saved them, when, from which address and the lines added, and sent to Arva SEO, which emails the workspace's owners and admins at once.
* New repair: Turn off the file editor adds DISALLOW_FILE_EDIT to wp-config.php, and can be undone.
* Scripts from other websites: the scan reads your homepage, latest post and latest page as a phone visitor, lists the scripts they load from other websites, and looks for where unfamiliar ones are added (a file and line, a setting, or a code snippet). Arva SEO checks the hosts (how new the domain is, whether the script hides what it does, Google's list of unsafe sites), free on every plan. A host Arva SEO confirmed as bad is flagged straight away, from the security feed.
* The security feed also teaches the scan which lines and hosts are safe, so changes many sites share (a host's own line) stop being reported.
* The new details these checks share with Arva SEO (added lines, script addresses, who edited a file and from where) are sent only after an administrator agrees once more, with Share these too on the Health page. Until then the scan's results are shared as before.
* Page caching: a change Arva SEO makes to every page also clears your host's cache (SiteGround's Speed Optimizer, LiteSpeed Cache, WP Engine, Breeze), and SEO title updates clear SiteGround's copy of the page. On SiteGround without its Speed Optimizer plugin, the Speed page explains why changes can take hours to show.

= 2.6.1 =
* Speed, Optimized CSS: the results of Optimize now, Check for results and Regenerate show under the buttons, not at the top of the page. While page types are being optimized, the page checks every 20 seconds and updates when they're ready.
* Page types that couldn't be optimized are listed with the reason in plain words (for example, a host's bot protection blocking Arva SEO's page renderer). Optimize now tries them again.
* Missing files that reach your site's not-found page (a stylesheet, a script, manifest.json) are no longer treated as page types to optimize.
* Leave things alone is always open on the Speed page.
* Security scan: Wordfence's firewall loader no longer shows as an unexpected file or an .htaccess rule found in hacked websites when it builds its paths from its own folder, as Wordfence's newer loaders do. A loader of anything outside a security plugin is still reported.

= 2.6.0 =
* New PageSpeed fix, Page caching: pages are saved as ready-made HTML and sent to visitors without running WordPress. On Apache and LiteSpeed the server sends them through rules at the top of .htaccess; elsewhere advanced-cache.php sends them before WordPress loads, with WP_CACHE added to wp-config.php.
* Turning it on checks your homepage is made once and then served from the cache; if it isn't (a cookie for every visitor, a no-cache header, the host's cache answering first), everything is taken out again and the card says why.
* Never cached: signed-in users, comment authors, password-protected posts, carts and checkouts, searches, addresses with a query other than tracking parameters, and pages that set cookies or ask not to be cached. Pages left alone on the Speed page aren't cached either.
* Pages are cleared when a post, comment, product stock, menu, widget, theme or a fix changes, or from Clear the cache (also in the toolbar), with Cloudflare's copy; the main pages are refilled in the background, a few a minute, and pages past 10 hours are made again.
* A copy of .htaccess is kept from before the plugin first changed it, and before its latest change; both can be downloaded from the Speed page (Browser caching and compression too).
* Other cache plugins keep the job, and Arva SEO's page cache turns itself off if one becomes active. On hosts with their own cache (SiteGround, LiteSpeed Cache, WP Engine, Kinsta, Cloudways), the host's cache is kept by default and you can choose Arva SEO's instead.
* A separate copy for phones, for themes that send phones different HTML (off by default).

= 2.5.1 =
* Speed: each fix shows its name, state, what it does and its buttons underneath; the PageSpeed problems it fixes, numbers and options open from Details. Leave things alone and Cloudflare's setup open when you need them.
* Health: each finding shows its severity, title and file with its buttons; what it means and how to fix it open from one link.
* When this server can't reach Arva SEO, connecting says why (DNS, a timeout or a secure connection) instead of blaming the code, and Activity keeps the server's own error.

= 2.5.0 =
* New: while the site is connected, the security scan downloads Arva SEO's security feed once a day. A file that a deep scan found to be malware on another website is reported as Known malware here too, without a deep scan of your own; a file Arva SEO checked and found safe (such as a premium plugin that hides its code) is no longer reported; and new patterns arrive between plugin updates. Files are recognized by their SHA-256 fingerprint.
* New: the scan says when your server's PHP is missing its latest security fixes (PHP released them on October 21, 2026: 8.5.12, 8.4.27, 8.3.36 and 8.2.35), or no longer gets them (PHP 8.1 and older), with the version to ask your host for. On Ubuntu, Debian and Red Hat builds, which add fixes under an older number, it's marked Medium.
* Shared scan results include each flagged file's SHA-256 fingerprint, so Arva SEO can tell when the same file turns up on several websites. Because that's new, Health asks an administrator once more before sharing the results again.

= 2.4.0 =
* New: Compress images (Speed). Images you upload are saved smaller a minute after WordPress makes their sizes, and the media library can be done in the background with progress, Stop and Restore. A file is replaced only when it gets at least 5% smaller, and the original is kept in wp-content/arvaseo-originals unless you turn that off.
* On your own server, free: full-size photos above quality 85 are saved at 82 without the hidden camera data (the colour profile is kept, and a photo that still needs turning upright is left alone), and PNGs are saved again without loss. On Arva SEO's servers, when the site is connected and an administrator allows it: stronger compression of every size, each image counted once against the plan's monthly image compressions, and only when it gets at least 5% smaller. When none are left, your own server carries on.
* The security scan shows its step (1 of 4), a progress bar and how many files it checked, against the last scan's count.
* SiteGround's and LiteSpeed's own plugins are named as running your host's page cache; keeping them is usually best.
* A deep scan's result says it checked the files that aren't official copies.

= 2.3.0 =
* New: Speed lists other active speed plugins that do some of the same jobs (WP Rocket, LiteSpeed Cache, Autoptimize, Smush and others), with the fixes each one does, and a Deactivate button for each. A plugin that also caches pages or compresses images says so first, because Arva SEO doesn't do either. "Keep them" leaves those fixes to them until another speed plugin is activated.
* After activating Arva SEO, the next dashboard page names those plugins once, and Overview lists them under Needs you until you choose.
* "Done by another plugin" on your latest test links to that list.
* Fixed: a deep scan of a site with many small files stopped while sending them, and then every new one was refused as "already running". It now sends at most 400 files a request.
* Fixed: a running deep scan showed one line and no progress. It now shows its step (1 of 3), a progress bar while the files are sent, how many are sent, and when it started.

= 2.2.1 =
* Other plugins' and themes' notices ("recommended plugin" reminders, offers and the like) no longer show on the Arva SEO pages. They still show everywhere else in the dashboard. Arva SEO's own notices and WordPress's recovery mode notice stay.
* Fixed: the security scan reported the firewall of Wordfence, All In One WP Security, MalCare or NinjaFirewall as a hack (its line in .htaccess, and its loader file such as wordfence-waf.php). They're recognized now, and any other line in the same .htaccess is still checked.
* Fixed: plugin logs kept in the uploads folder as PHP that stops on its first line (Mailchimp for WordPress's debug-log.php, for example) were reported as PHP in the uploads folder.
* Results in .htaccess say what to do about a rule in a settings file, instead of restoring a plugin or theme.
* Health → Security and Health → Website health: the buttons under a result no longer touch the boxes above them, and opening "See the code it matched" or the evidence no longer moves them.

= 2.2.0 =
* New: "Restore the official copy" for a changed or missing WordPress file or a changed WordPress.org plugin file. The copy comes from WordPress.org for your version and is used only when it matches WordPress.org's checksum.
* New: "Move to quarantine" for PHP in the uploads folder, code disguised as an image and PHP files WordPress doesn't ship. Quarantined files are kept where they can't run (wp-content/arvaseo-quarantine, closed to web requests, under random names).
* Every repair can be undone for 30 days under "Changes made here", unless the file changed again since. Nothing is repaired where WordPress disallows file changes or needs FTP details.

= 2.1.0 =
* New, when connected: deep scans on Health → Security. The files the security scan couldn't match to an official copy go to Arva SEO, where ClamAV's database of known malware and Arva SEO's own rules check them, and are deleted straight after. What a deep scan finds joins the scan's results, stays while the file is unchanged, and reaches Arva SEO with them.
* Deep scans have their own choice (on Health, or in Settings → Diagnostics and data sharing), need the scan's results to be shared, and are each started by an administrator. Secrets, logs, database exports, backups and media are never sent.
* Each website's deep scans this month show on Health, with a link to get more in Arva SEO when they run out.

= 2.0.0 =
* New name: Arva SEO – Speed Booster & Security Scanner. Your settings, fixes and connection stay as they are.
* New: a security scan on Health → Security, on your own server, weekly (Settings can turn that off) and on Scan now, in background batches that resume where they stopped. It works without an account and only reports.
* It compares WordPress's own files and your WordPress.org plugins with their official checksums (changed, missing and extra files), looks for PHP in uploads, code disguised as images and unexpected PHP in the main folder, matches patterns found in hacked websites in files that aren't official copies, and checks for exposed .env, .git, debug log and backup files, folder listings, file editing in the dashboard, an "admin" administrator, XML-RPC and username lists.
* Each result says what it means and what to do; "This is expected" hides one until the file changes. Serious results come first on Overview.
* New, when connected: known vulnerabilities in your installed plugins, themes and WordPress version on Health, from Wordfence Intelligence, with a link to each record and the data's copyright notices.
* New, optional: share the scan's results with Arva SEO (a separate choice when connecting, on Health, or in Settings → Diagnostics and data sharing), for Website Health, email alerts, tasks and reports. Withdrawing the plugin list and health consent withdraws it too.

= 1.9.0 =
* New: Cloudflare, on the Speed page. Connect it with an API token, and Cloudflare's copy of every page is cleared when a fix goes on or off for visitors, your choices change, or new optimized CSS, WebP copies or local fonts are ready. Trying a fix clears nothing, since only you see it.
* New: a post's own pages on Cloudflare (the post, the homepage, the blog page, the feed and its category and tag pages) are cleared when it's published, updated or unpublished, including content sent from Arva SEO. It can be switched off if Cloudflare's own plugin does it.
* New: "Clear Cloudflare's copy now", the last clear and its result, and a warning when Cloudflare's Rocket Loader is on alongside Defer scripts or Delay chat widgets and trackers.
* New: when an administrator opens the site through Cloudflare, Overview suggests connecting it; a token Cloudflare stops accepting shows there too.
* For developers: the arvaseo_connector_pages_changed action runs after any change that alters what visitors get, so other page caches can clear their copies.
* The Cloudflare token is stored encrypted and removed when you disconnect or delete the plugin. Diagnostics show only whether Cloudflare is connected and the last clear.

= 1.8.0 =
* New: a redesigned admin in Arva SEO's look, with tabs for Overview, Speed, Content, Health, Activity and Settings.
* New: Overview shows what needs you first, most urgent at the top, with your PageSpeed score, Website Health results and content settings at a glance. Before connecting it's Get started, which shows the speed fixes work without an account.
* Speed: your latest PageSpeed score at the top, the fixes it asks for, and a filter for all fixes, the ones the test asks for, and those on, being tried or off. Fixes are grouped by what they improve.
* Settings has a section list, and the plugin list and Diagnostics and data sharing are now pages of Settings (under Data and privacy).
* The pages are named Overview (was Connection), Speed (PageSpeed fixes), Content (Content Sync), Health (Website Health), Activity (Activity Log) and Plugin list (Plugin Inventory). Links from Arva SEO open the same pages as before.
* The fonts (Fraunces and Inter) come with the plugin, so the admin pages load nothing from other sites.
* Nothing changes on your site by updating: every fix, setting and switch stays as it was.

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
