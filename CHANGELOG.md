# Changelog

## 0.5.6
- Both Advanced tools now show the exact, fully-assembled smbclient
  command line (password redacted) they actually ran, built from the
  same code path used for the real `exec()` call - not a reconstruction,
  the literal string. Lets you visually confirm exactly what bytes smbclient
  received instead of trusting a trace of PHP's escaping.

## 0.5.5
- Added an optional "Initial directory" (`-D`) field to the "Run a raw
  smbclient command" tool under Advanced. `-D` is a genuine top-level
  smbclient command-line flag, parsed separately from whatever's inside
  `-c` - a 2004 Samba mailing list thread documents `-D "Some Folder"
  -c ls` working where `-c "ls Some Folder"` fails on the exact same
  space-in-path truncation this app has been hitting. Lets that be tested
  directly from the admin page.

## 0.5.4
- "Test allinfo parsing" under Advanced can now try single-quoting the
  path instead of the double-quoting the real scan/apply code uses today
  - for testing whether smbclient's own command-string parser handles
    paths with spaces differently depending on quote style (real-world
    reports suggest it might: `NT_STATUS_OBJECT_NAME_NOT_FOUND`/
  `NT_STATUS_OBJECT_PATH_NOT_FOUND` on paths containing spaces, in a
  pattern consistent with the path getting split apart before reaching
  the server). The exact command tried is now shown in the result.
- New "Run a raw smbclient command" tool under Advanced: type any -c
  command and run it against a selected mount with its stored
  credentials. Lets quoting/escaping strategies (or anything else) be
  tried directly from the admin page while diagnosing an issue, without
  needing a new app release for every variant to test. Not read-only -
  clearly labeled as such, since it will run whatever is typed, including
  destructive sub-commands.

## 0.5.3
- Documentation update: the `allinfo` write_time parsing has now been
  confirmed against a live server via the Advanced diagnostic tool - the
  plain human-readable form with a trailing timezone abbreviation (e.g.
  `Sat Jul  4 23:02:35 2026 UTC`) parses correctly, including honoring the
  timezone label to produce the right absolute instant. The
  epoch-in-parentheses variant remains a defensive fallback but is still
  unconfirmed in the wild. No code changes, just replacing "unverified"
  language with what's actually been checked.

## 0.5.2
- New collapsed "Advanced" section on the admin page with a "Test allinfo
  parsing" tool: pick a mount and a file path, and it runs the exact same
  `smbclient allinfo` + parsing logic the scan uses, then shows the raw
  output, which line matched, which parsing path was taken (zero-timestamp
  sentinel / epoch-in-parentheses / strtotime fallback), and the resulting
  timestamp - so you can confirm the parsing matches your actual Samba
  server without SSH access or reading the source. Read-only, never
  writes anything. Refactored the underlying allinfo call and parser into
  shared helpers so this diagnostic and the real scan can't drift apart.

## 0.5.1
- Starting a manual scan now clears any leftover "Scan & fix all
  automatically" status text, and starting an auto-fix run now clears the
  manual scan's review list and status - each no longer leaves stale
  output from the other lying around on screen.

## 0.5.0
- **Behavior change**: "Update selected files" and "Scan & fix all
  automatically" now respect the dry-run setting, the same as the
  real-time listener. Previously retroactive apply always wrote for real
  regardless of dry-run, on the theory that clicking the button was itself
  confirmation - one single switch governing every write path was judged
  more consistent. With dry-run on, both now just log what they'd do;
  rows that were only dry-run-logged stay in the review list (nothing was
  actually written) instead of disappearing as if fixed.
- Consolidated "Scan & fix all automatically" into the main scan section -
  it's now a second button next to "Scan for mismatches", sharing the same
  limit field, instead of a separate section with its own heading and
  explanation. The confirmation dialog carries the necessary warning on
  its own.
- Added a "SMB mount" dropdown to restrict a scan (manual or automatic) to
  one specific mount instead of always scanning every configured SMB
  mount.

## 0.4.8
- Fixed: the manual "Scan for mismatches" limit field didn't actually cap
  the total across a multi-batch scan. The backend has no memory between
  batch calls, so its per-call limit check only capped mismatches found
  *within one 200-file batch* - if mismatches were sparse, each batch
  would find fewer than the limit on its own, never trigger the stop
  condition, and the scan would keep going well past the number you set.
  The running total is now tracked client-side, with only the remaining
  budget passed to each batch call, so it stops exactly at the number
  requested regardless of how batches happen to split up.

## 0.4.7
- New "Scan & fix all automatically" button: chains the existing bounded
  scan batches and chunked apply into one loop, so mismatches get fixed
  as they're found with no manual review step. Gated behind a confirm
  dialog that calls out the tradeoff (no per-file review, relies on the
  same `allinfo`-parsing caveat as the manual flow), can be stopped
  mid-run, and disables the manual Scan/Apply buttons while it's running
  to avoid overlapping runs in the same tab. Reuses the same batch sizes
  as the manual flows (200 examined / 25 applied per request), so it
  carries no new timeout risk despite running unattended for longer.

## 0.4.6
- "Scan for mismatches" now runs in bounded batches (200 files examined per
  request) following a resumable cursor, instead of one request walking
  the entire share. Results appear incrementally as each batch completes,
  the button doubles as a Stop control, and a scan that fails partway
  still keeps whatever it found. Previously this was the one remaining
  unbounded-request risk in the app - it had the same
  `max_execution_time`/proxy-timeout exposure Apply used to have, and was
  arguably worse since the mismatch-count limit only capped *results*, not
  how many files got examined getting there.
- Log level defaults changed: dry-run/success messages now default to
  Info (was Warning), errors now default to Error (was Warning) - so a
  fresh install's two categories are meaningfully different out of the
  box instead of both starting at the same level.

## 0.4.5
- "Update selected files" now applies in batches of 25 instead of one
  request for the whole selection, with a live progress count. Fixed rows
  disappear from the list as each batch completes; failed ones stay
  visible for a retry. This avoids a single request holding thousands of
  files running long enough to hit PHP's `max_execution_time` or a reverse
  proxy's read timeout, and means a mid-batch failure only costs you that
  batch instead of the whole thing.
- Added a 30-second per-operation timeout (`smbclient -t`) to both
  `smbclient` invocations, so a hung/slow connection to the SMB server no
  longer has an unbounded wait - on the real-time path that previously
  meant a stuck user upload with no way out short of killing the PHP
  worker.

## 0.4.4
- **Reliability**: every risky operation (raw SQL against `oc_filecache`/
  `oc_storages`, calls into `files_external` internals, `exec()`) is now
  wrapped so unexpected failures - a Nextcloud/DB schema change,
  `exec()` disabled via `disable_functions`, an internal API changing
  shape - degrade to "log an error and do nothing" instead of an
  uncaught exception, a partial write, or (on the real-time path) any
  risk of disrupting the actual file write happening in Nextcloud core.
  New "unexpected error" messages fall under the existing Errors log
  category; a copy is also always written via PHP's native `error_log()`
  as a backstop that doesn't depend on Nextcloud's own config being
  intact.
- **Breaking**: app id renamed from `smb_mtime_fix` to `nextcloud_smb_mtime_fix`
  to align with this repo's name. If you're upgrading from an earlier
  install, disable and remove the old `smb_mtime_fix` folder/app first,
  then install fresh under the new id - there's no in-place rename path,
  and settings (dry-run state, log levels) are stored per-app-id, so
  they'll reset to defaults under the new id.

## 0.4.3
- Clarified that the retroactive scan's optional limit caps the number of
  mismatches found, not the number of files examined along the way.

## 0.4.1
- Optional scan limit now defaults to unlimited when left blank, instead
  of defaulting to 500.

## 0.4.0
- Fixed the SMB backend identifier check (`smb`, not `files_external::smb`)
  - global SMB mounts weren't being matched at all before this.
- Verified the storage-ID construction against Nextcloud's actual
  `SMB.php::getId()` implementation instead of a guessed format.
- Improved `allinfo` output parsing (epoch-in-parentheses when present,
  falls back to parsing the human-readable date).
- Consolidated per-message log level controls into two categories: dry-run
  output & success messages, and errors - each independently configurable,
  using Nextcloud's own logger.
- Added an optional limit field to the retroactive scan.

## 0.3.x
- Dry run is now tri-state: On (persisted) / Temporarily off (reverts to
  On on the next restart, via a local cache) / Off (persisted).

## 0.2.0
- Consolidated the real-time listener and admin UI onto a shared service
  class.
- Added an admin settings page: dry-run toggle (default on), "Scan for
  mismatches" button, review list, and "Update selected files" to apply
  fixes retroactively.

## 0.1.0
- Initial real-time fix: listens for writes to SMB mounts and corrects the
  mtime via `smbclient`, patching `storage_mtime` so ETags don't change.
