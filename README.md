# SafeStep Updates

**Update WordPress plugins without fear.** SafeStep saves a copy of a plugin before every update, checks your site right afterwards, and restores the old version automatically if the update broke something.

[Landing page](https://iam-knr.github.io/safestep-updates/) · [Download v0.2.0](https://github.com/iam-knr/safestep-updates/raw/main/docs/safestep-updates-0.2.0.zip) · GPL-2.0-or-later

## What it does

- **Snapshot before every update.** Covers the Plugins screen, the Updates screen, bulk updates, automatic updates and WP-CLI. You choose how many past versions to keep.
- **Health check afterwards.** Requests your home page, login page and any extra pages you list (for example `/shop/`). A server error (HTTP 5xx) or a visible PHP fatal error is a failure. Problems that already existed before the update are ignored.
- **Automatic rollback.** If the update caused a new failure, the previous version is restored and you get an email.
- **Version hold.** A version that broke your site is not installed automatically again until you release the hold.
- **Restore and log.** Restore any snapshot in one click from **Tools → SafeStep**, with a log of every update, rollback and restore.

## What it does not do

- It is not a backup plugin: plugin files only, not your database, uploads or themes.
- It does not update themes or WordPress core.
- It cannot detect every kind of breakage, only server errors and PHP fatals on the pages it checks.
- WordPress 6.6+ already rolls back an automatic plugin update that causes a fatal error. SafeStep adds coverage for manual, bulk and WP-CLI updates, other server errors, lasting snapshots, version hold and alerts.
- Single sites only for now; multisite is not supported yet.

## Install

1. Download the zip above (or zip the `safestep-updates/` folder yourself).
2. **Plugins → Add New → Upload Plugin**, choose the zip, activate.
3. Open **Tools → SafeStep** to turn on auto-updates and add pages to check.

Requires WordPress 6.0+ and PHP 7.4+.

## Privacy

SafeStep connects to no external service. The health check only requests your own site. Snapshots are stored in a randomly named, access-protected folder inside `wp-content/uploads/` and removed on uninstall.

## Repository layout

| Path | Contents |
| --- | --- |
| `safestep-updates/` | The plugin (this folder is what gets zipped and installed) |
| `docs/` | Landing page, served with GitHub Pages |
| `.wordpress-org/` | Icon and banner for the WordPress.org listing |

## Development notes

- Checked with Plugin Check 2.2.0. One warning is expected: `update_modification_detected` for the `auto_update_plugin` filter, which SafeStep uses only to apply the user's chosen auto-update mode, per plugin.
- Rollback was tested on Apache (mod_php) and nginx + PHP-FPM. There are no automated unit tests yet; contributions are welcome.
- PHP syntax is linted on 7.4 and 8.2; it has not been run on a real PHP 7.4 runtime.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
