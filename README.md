# Nextcloud SMB Mtime Fix

A Nextcloud app that corrects file modification times on SMB/CIFS external
storage mounts.

Claude heavy.

## The problem

Nextcloud's SMB external storage backend silently ignores the mtime it's
told to write. Files uploaded (via web, sync client, or WebDAV) end up
stamped with the upload time instead of their real timestamp, and there's
no built-in way to fix this after the fact.

## What this app does

- **Real-time fix**: listens for writes to SMB mounts and pushes the
  correct mtime back to the share via `smbclient` immediately, then patches
  the cache's `storage_mtime` so the ETag is never recomputed - meaning
  synced clients never see a spurious "file changed" and never redownload.
- **Retroactive scan**: an admin settings page lets you scan existing SMB
  mounts for files that were already affected before this app was
  installed, review the list, and apply fixes on demand.
- **Dry run mode** (on by default): log what the app *would* do without
  touching anything, until you've verified it against your own SMB server.
- **Configurable logging**: separate log levels for routine status messages
  vs. errors, using Nextcloud's normal logger.

## Requirements

- Nextcloud 27–34
- `smbclient` (or the `php-smbclient` module) installed on the Nextcloud
  host - same requirement as Nextcloud's own SMB external storage backend
- At least one **global** (admin-configured) SMB external storage mount;
  personal/user-added SMB mounts aren't scanned

## Installation

Nextcloud requires the app's folder name (inside `apps/` or wherever your
`custom_apps_directory` points) to exactly match its app ID:
`nextcloud_smb_mtime_fix` - which is also this repo's name, so a plain
`git clone` already produces the right folder:

```bash
cd /path/to/nextcloud/apps  # or your custom_apps_directory
git clone https://github.com/amjadkamali/nextcloud_smb_mtime_fix.git
chown -R www-data:www-data nextcloud_smb_mtime_fix   # match your web server user

sudo -u www-data php occ app:enable nextcloud_smb_mtime_fix
```

Then go to **Settings → Administration → SMB Mtime Fix** to configure it.

## Updating

```bash
cd /path/to/nextcloud/apps/nextcloud_smb_mtime_fix
git pull
```

Bump the `<version>` in `appinfo/info.xml` if you want Nextcloud/browsers to
reliably bust their cache of this app's JS/CSS assets after pulling changes.

## Failure handling

Every operation that touches something outside this app's control (raw
queries against Nextcloud's database, `files_external` internals, shelling
out to `smbclient`) is wrapped so an unexpected failure - a Nextcloud/DB
schema change, `exec()` disabled, an internal API changing shape - results
in "log an error and do nothing" rather than a crash, a partial write, or
any risk of disrupting the actual file write happening in Nextcloud core.
These show up under the **Errors** log category on the settings page, and
are also always written to PHP's native error log as a backstop.

## Known limitations / things worth verifying on your own setup

- `allinfo` output parsing (used by the retroactive scan to read a file's
  real on-share mtime) has some version-to-version drift across Samba/
  smbclient releases. Spot-check with `smbclient //host/share -U
  user%pass -c 'allinfo "path/to/file"'` if scan results look off.
- The retroactive scan shells out to `smbclient` once per file checked. It
  runs in small, bounded batches (following a resumable cursor) so a single
  request can't run long enough to hit PHP's `max_execution_time` or a
  reverse proxy's timeout regardless of share size - you can also stop it
  partway and keep whatever it's found so far. It's still inherently slow
  on shares with many files; use the optional limit field to cap total
  mismatches found for a quicker test run.
- Only admin-configured (global) SMB mounts are covered; personal
  (user-added) SMB external storage isn't scanned or auto-fixed.

## License

MIT - see [LICENSE](LICENSE).

## Author

amjadkamali
