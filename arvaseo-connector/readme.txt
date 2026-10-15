=== Arva SEO Connector ===
Contributors: arvaseo, avdajay
Tags: seo, site health, publishing, plugins, maintenance
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Arva SEO: Website Health with fix guidance, content delivery, and SEO title and description updates within limits you set.

== Description ==

The Arva SEO Connector links this site to a website in your Arva SEO workspace.

* **Secure connection.** Approve the connection on app.arvaseo.com. No Arva SEO password is ever entered in WordPress; the site gets its own revocable, encrypted credentials.
* **Plugin inventory.** Installed plugins (including must-use and drop-ins) and themes with their versions, active state and update state, from WordPress's own data. Missing or old update data is shown as unknown, never as up to date.
* **Website Health.** Around 40 checks across core, plugins and themes, runtime, security, reliability, performance and SEO, using WordPress's Site Health tests and APIs. Arva SEO turns them into findings that say what is wrong, why it matters, how to fix it, who should do it, and how to verify and undo the change. Nothing is ever changed on your site automatically.
* **Content from Arva SEO.** Drafts written in Arva SEO arrive as drafts, pending review, scheduled or published posts, but never beyond the publishing mode you choose here (Draft only by default).
* **SEO titles and descriptions.** If an administrator allows it (off by default), the SEO titles and meta descriptions your team approves in Arva SEO are written to Yoast SEO or Rank Math for published posts, pages and products and the homepage, in bulk. Only those two fields change, the page caches are cleared, and every change can be undone from Arva SEO.
* **Privacy.** Nothing about the site is shared until an administrator agrees to the exact list of fields. No visitor data, user accounts, emails, passwords or settings are ever sent. Existing content isn't sent either, except the previous SEO title and meta description of a page whose SEO title or description Arva SEO changes (when allowed), so that change can be undone.

== Installation ==

1. Upload the plugin through Plugins → Add New → Upload Plugin, and activate it.
2. Open Arva SEO → Connection, review what is shared, and click Connect to Arva SEO.
3. Sign in to Arva SEO, choose the website, and approve. You return to WordPress connected.
4. In Arva SEO → Settings, choose the default author and publishing mode.

For reliable scheduled publishing on low-traffic sites, run WP-Cron from a server scheduler (see the administrator guide).

== Frequently Asked Questions ==

= Does the plugin change my site? =

No. Website Health is read-only and every fix is a recommendation. Arva SEO can only create or update posts it sent, within your publishing mode, and, if you allow it in Settings, change the SEO title and meta description of published content in Yoast SEO or Rank Math.

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

* **When connecting:** you are sent to app.arvaseo.com to sign in and approve; the plugin then exchanges a one-time code with api.arvaseo.com for this site's own credentials, and sends the site address, name, WordPress and plugin versions, time zone, environment type, your publishing settings and your data-sharing choice.
* **Plugin inventory and Website Health (only if you agree on the Connection screen):** installed plugins and themes with versions and update state, and the results of about 40 health checks (booleans, counts and versions only). Sent after connecting, twice a day for the inventory and once or twice a day for health checks, after plugin changes, and when you or Arva SEO ask for a recheck.
* **Content:** every 5 minutes the plugin asks api.arvaseo.com for content you sent from Arva SEO, and reports back the post ID, status and link it created.
* **Signed requests from Arva SEO:** api.arvaseo.com may call this site's /wp-json/arvaseo/v1/events endpoint to ask it to check for content or run a recheck sooner.
* **Featured images (off by default):** only from image hosts you approve.

No visitor data, user accounts, emails, passwords, settings or existing content is ever sent. Arva SEO terms: https://arvaseo.com/terms/ · Privacy policy: https://arvaseo.com/privacy/

== Privacy ==

See Arva SEO → Diagnostics & Privacy for the full list of shared fields and their purpose, and the suggested text added to Settings → Privacy.

== Changelog ==

= 1.1.0 =
* New: bulk SEO title and meta description updates from Arva SEO into Yoast SEO or Rank Math, for published posts, pages, products and the homepage. Off until an administrator allows it in Settings; every change can be undone from Arva SEO.
* Yoast SEO's cache (indexables) is rebuilt for each changed post, and page caches are cleared (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache).
* Settings: the SEO plugin is detected automatically; adding SEO fields to new drafts is its own setting.

= 1.0.0 =
* First release: secure connection, plugin inventory, Website Health with assessed findings, content delivery with a local publishing ceiling, activity log, diagnostics.
