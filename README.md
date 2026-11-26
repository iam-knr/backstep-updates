# Backstep Updates

**Update WordPress plugins without fear.** Backstep saves a copy of a plugin before every update, checks your site right afterwards, and restores the old version automatically if the update broke something.

[Landing page](https://backstep.iamknr.in/) · [Download v0.2.1](https://github.com/iam-knr/backstep-updates/raw/main/docs/backstep-updates-0.2.1.zip) · GPL-2.0-or-later

## What it does

- **Snapshot before every update.** Covers the Plugins screen, the Updates screen, bulk updates, automatic updates and WP-CLI. You choose how many past versions to keep.
- **Health check afterwards.** Requests your home page, login page and any extra pages you list (for example `/shop/`). A server error (HTTP 5xx) or a visible PHP fatal error is a failure. Problems that already existed before the update are ignored.
- **Automatic rollback.** If the update caused a new failure, the previous version is restored and you get an email.
- **Version hold.** A version that broke your site is not installed automatically again until you release the hold.
- **Restore and log.** Restore any snapshot in one click from **Tools → Backstep**, with a log of every update, rollback and restore.

## What it does not do

- It is not a backup plugin: plugin files only, not your database, uploads or themes.
- It does not update themes or WordPress core.
- It cannot detect every kind of breakage, only server errors and PHP fatals on the pages it checks.
- WordPress 6.6+ already rolls back an automatic plugin update that causes a fatal error. Backstep adds coverage for manual, bulk and WP-CLI updates, other server errors, lasting snapshots, version hold and alerts.
- Single sites only for now; multisite is not supported yet.

## Install

1. Download the zip above (or zip the `backstep-updates/` folder yourself).
2. **Plugins → Add New → Upload Plugin**, choose the zip, activate.
3. Open **Tools → Backstep** to add pages to check. Updates you enable in WordPress's own auto-update settings are protected too.

Requires WordPress 6.0+ and PHP 7.4+.

## Privacy

Backstep connects to no external service. The health check only requests your own site. Snapshots are stored as zip archives in `wp-content/uploads/backstep-updates/` and removed on uninstall.

## Repository layout

| Path | Contents |
| --- | --- |
| `backstep-updates/` | The plugin (this folder is what gets zipped and installed) |
| `docs/` | Landing page, served with GitHub Pages |
| `.wordpress-org/` | Icon and banner for the WordPress.org listing |

## Development notes

- Checked with Plugin Check 2.2.0. One warning is expected: `update_modification_detected` for the `auto_update_plugin` filter, which Backstep uses only to veto a version it already rolled back; it never turns updates on or changes update sources.
- Rollback was tested on Apache (mod_php); nginx + PHP-FPM was tested on 0.2.0 and not re-run for 0.2.1. There are no automated unit tests yet; contributions are welcome.
- PHP syntax is linted on 7.4 and 8.2; it has not been run on a real PHP 7.4 runtime.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
