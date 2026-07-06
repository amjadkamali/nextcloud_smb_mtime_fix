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
  installed, review the list, and apply fixes on demand. There's also a
  "scan and fix all automatically" button next to it that skips the review
  step - see the caveat below before using it on a large share. Both apply
  paths respect the dry-run setting above, the same as the real-time
  listener.
- **Scan scope**: an "SMB mount" dropdown restricts a scan to one specific
  mount (or all of them), and an optional "Folder" field under Options
  further restricts it to one path within that mount and everything under
  it - useful for testing against a small, known-affected subfolder before
  trusting it with a whole share.
- **Options** (under the retroactive scan section):
  - **Detection mode** - "Database compare" (default) checks two
    already-cached database columns (`mtime` vs `storage_mtime`) with a
    single query per batch, no `smbclient` calls during scanning at all.
    "Live SMB read" instead reads each file's real mtime off the share via
    `smbclient` during the scan itself - slower, but always current at
    scan time.
  - **Live recheck before writing** (default on) - right before writing,
    confirms the file's mtime still actually disagrees with the intended
    value, skipping it if something else already fixed it since the scan
    ran. Reuses a Live SMB read scan's own reading instead of reading the
    file again (no extra `smbclient` call); with Database compare, does a
    fresh read, since the scan never took one.
  - **Never move mtime forward** (default on) - refuses to write a
    timestamp later than the most recently known value for that file.
    Checks against a cached value even without live recheck, or against a
    fresh reading if live recheck ran.
  - Files these two skip are left in the results table with the reason
    shown in a Status column, not silently dropped from the count.
- **Results table stays a full record**: after applying, fixed rows stay
  in the table (marked "Fixed", checkbox disabled so they can't be
  re-applied) instead of disappearing - skipped and failed rows also show
  their reason in the same Status column. Everything clears on a new scan
  or a switch to auto-fix.
- **Dry run mode** (on by default): log what the app *would* do without
  touching anything, until you've verified it against your own SMB server.
  Applies to every write path, including both retroactive apply flows.
- **Configurable logging**: independent log levels - Dry-run output &
  success messages (default Info), Errors (default Error), and Skipped
  files (default Warning) - all through Nextcloud's normal logger.
- **Advanced diagnostics**: a collapsed "Advanced" section with a "Test
  allinfo parsing" tool - runs the exact same `smbclient allinfo` call and
  parsing logic the scan uses against one file you choose, and shows the
  raw output, which line matched, and which parsing path was taken. Useful
  for confirming the parsing matches your specific Samba server before
  trusting scan results at scale, without needing shell access.

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

- Commands are sent to `smbclient` via stdin (`echo '...' | smbclient ...`)
  rather than its `-c` flag - confirmed real-world evidence shows `-c`
  splits on `;` as a command separator regardless of quoting, so a
  filename containing one could cause part of it to run as a second,
  independent smbclient command, with no escape able to prevent it.
  Stdin-piped commands were confirmed (against a real file) not to share
  that bug. Paths containing `"` are still refused entirely (read or
  write) as a defensive precaution - `"` is the character used to quote
  paths, and this parser has already shown it doesn't reliably respect
  quoting, so a literal `"` in a filename could plausibly break out of it
  the same way `;` did - this hasn't been independently confirmed the
  same rigorous way, just blocked out of caution. Affected files are
  logged under the `unsafe_path` message type - not scanned, not written
  to, on any path (real-time listener, retroactive scan, or retroactive
  apply).
- `allinfo` output parsing (used by Live SMB read detection and the
  real-time listener to read a file's actual on-share mtime) has some
  version-to-version drift across Samba/smbclient releases. The plain
  human-readable form with a trailing timezone abbreviation (e.g.
  `write_time: Sat Jul  4 23:02:35 2026 UTC`) is confirmed working against
  a live server; a variant some builds are reported to use, with the raw
  epoch additionally appended in parentheses, is still unconfirmed in the
  wild but handled as a preferred path if present. Use the "Test allinfo
  parsing" tool under **Advanced** on the admin page to check which one
  your server actually produces, instead of guessing.
- **Database compare** detection mode (the default) never takes a live
  reading during scanning - its "actual mtime" is `storage_mtime`, only as
  fresh as whenever Nextcloud last looked at the file. **Never move mtime
  forward** still checks against that cached value even with live recheck
  off, just less precisely than against a fresh reading; **Live recheck**
  sharpens that to a live value and additionally catches files already
  fixed by something else since the scan ran - a real improvement, not a
  hard dependency this mode can't function without.
- **Live SMB read** detection mode shells out to `smbclient` once per file
  checked, so it's inherently slow on shares with many files - the scan
  runs in small, bounded batches (following a resumable cursor) so a
  single request can't run long enough to hit PHP's `max_execution_time`
  or a reverse proxy's timeout regardless of share size, and you can stop
  it partway and keep whatever it's found so far, but the underlying
  per-file cost doesn't go away. Use the optional Folder filter or Limit
  field to scope a scan down for a quicker run.
- Only admin-configured (global) SMB mounts are covered; personal
  (user-added) SMB external storage isn't scanned or auto-fixed.
- "Scan & fix all automatically" writes to every mismatched file it finds
  with no per-file review - it's built on the same batching as the manual
  flow, so it won't time out, but (when dry-run is off) it will happily
  write thousands of corrections in a row based on the detection mode and
  parsing behavior described above. Confirm it with the Advanced
  diagnostic tool, and a normal scan + manual apply, before trusting it
  with a whole share. It also only guards against double-starting from the
  same browser tab - running it from two tabs or two admin sessions at
  once isn't prevented.

## License

MIT - see [LICENSE](LICENSE).

## Author

amjadkamali
