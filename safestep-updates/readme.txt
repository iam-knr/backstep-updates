=== SafeStep Updates ===
Contributors: kailasnathr
Tags: updates, auto update, rollback, snapshot, plugins
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Snapshot every plugin update, check your site afterwards, and roll back automatically if an update breaks it.

== Description ==

Plugin updates are the main way WordPress sites get fixed, and also the main thing site owners are afraid of. SafeStep removes that fear.

**What it does**

* **Snapshot before every update.** A copy of the plugin's current files is saved just before WordPress replaces them. This covers updates from the Plugins screen, the Updates screen, automatic updates and WP-CLI.
* **Health check afterwards.** Your home page, login page and any extra pages you list are requested. A server error (HTTP 5xx) or a visible PHP fatal error counts as a failure. Problems that already existed before the update are ignored.
* **Automatic rollback.** If the update caused a new failure, the previous version is restored, you get an email, and that exact version is not installed automatically again.
* **Restore any snapshot** with one click from Tools > SafeStep, and a log of every update, rollback and restore.

**What it does not do**

* It is not a backup plugin. It stores copies of plugin files only, not your database, uploads or themes.
* It does not update themes or WordPress core.
* It cannot detect every kind of breakage. It looks for server errors and PHP fatal errors on the pages it checks, not for a checkout that silently stops working.
* WordPress 6.6 and later already rolls back an automatic plugin update that causes a fatal error. SafeStep adds coverage for manual, bulk and WP-CLI updates, other server errors, lasting snapshots, a hold on bad versions and alerts.

== External services ==

This plugin does not connect to any external service. To check that your site still works after an update, it requests your own home page and login page (and any pages you list, on your own domain only). These requests never leave your site.

== Privacy ==

SafeStep does not collect or transmit personal data about your visitors. Snapshots of plugin files are stored in a randomly named, access-protected folder inside your uploads directory and are deleted when you uninstall the plugin.

== Installation ==

1. Upload the `safestep-updates` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it. Manual updates are protected straight away.
3. Go to Tools > SafeStep. To also install updates automatically, choose a mode under "Auto-update plugins".

== Frequently Asked Questions ==

= Will it roll back an update that is fine but happens to break one of my pages? =

Only the pages it checks are considered. Add important pages (for example your shop or checkout) under "Extra pages to check" so they are covered.

= What if the site is already broken before an update? =

SafeStep compares against the state before the update, so existing problems do not trigger a rollback.

= Does it work on multisite? =

Not yet. It is built for single sites.

= Why does it ask a page on my own site to clear the PHP cache? =

Web servers can keep an old compiled copy of a plugin for a few seconds, which would hide a problem. Before checking, SafeStep sends a one-time request, secured with a random token that expires after 60 seconds, that asks the server to clear its opcache.

== Changelog ==

= 0.2.0 =
* Fix: updates through WordPress's bulk updater and WP-CLI were not snapshotted.
* Fix: a broken update could look healthy because of cached PHP code or maintenance mode.
* Fix: a healthy update could be rolled back because of maintenance mode.
* Fix: API keys containing symbols were altered when saved.

= 0.1.0 =
* First version: snapshots, health check, automatic rollback, restore and log.

== Upgrade Notice ==

= 0.2.0 =
The "Auto-update plugins" checkbox became a setting. If you had it ticked, it is now "All updates".
