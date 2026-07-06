# Changelog

## 0.5.11
- The "Run a raw smbclient command" tool under Advanced now supports two
  invocation modes, toggled by radio button: the existing `-c` flag
  (what the real scan/apply code uses today), and a new "piped via
  stdin" mode (`echo '...' | smbclient ...`) - a different code path
  that a Samba forum thread reports does not share `-c`'s confirmed
  `;`-splitting bug, separating multiple commands on newlines instead.
  The command field is now a multi-line textarea - in stdin mode, each
  line runs as a separate command; in `-c` mode only the first line is
  used, matching real `-c` usage. Purely a diagnostic addition - nothing
  in the real scan/apply/listener code changed, and stdin-piping isn't
  used anywhere else in the app yet.

## 0.5.10
- **Security fix**: confirmed via real-world evidence (a filename
  containing `;`, producing literal "command not found" errors) that
  smbclient's own `-c` command parser splits on `;` as a command
  separator regardless of quoting - anything after it in a path runs as
  a second, independent smbclient command. Both the read path
  (`queryActualMtimeInner`, used by scanning and live recheck) and the
  write path (`applyFixInner`, used by the real-time listener and both
  retroactive apply flows) now refuse to touch any path containing `;`
  or `"` (the latter blocked defensively, since it's the character we
  use to quote paths and this parser has already shown it doesn't
  reliably respect quoting) - logged under a new `unsafe_path` message
  type in the Errors category, rather than silently attempting a command
  that could execute unintended, potentially destructive operations
  (`del`, `rmdir`, etc.) if part of a filename happened to match a real
  smbclient command name.
- Brought back a scoped-down "Run a raw smbclient command" tool under
  Advanced (mount + command field only) for testing this class of
  behavior directly - deliberately not subject to the new safety check,
  since its purpose is to let an admin test exactly these characters on
  disposable data.

## 0.5.9
- Renamed the top log level from "Critical" to "Fatal" - matches
  Nextcloud's own `occ log:manage`/config.php terminology for that level,
  even though it's the same underlying PSR-3 `critical()` call.
- Successful writes in "Update selected files" no longer remove their row
  from the results table - they stay, marked "Fixed" in the Status
  column, with the checkbox unchecked and disabled so a fixed row can't
  be accidentally re-applied. The table now reads as a full record of
  what happened to every scanned file, not just the ones that didn't get
  fixed. Fixed rows are dimmed via CSS to stay visually distinct.
  Everything still clears on a new scan or a switch to auto-fix, as
  before.
- Fixed: the "X/Y updated" apply-status line never cleared when starting
  a new scan or switching to auto-fix, leaving stale text next to fresh
  results. Now clears alongside everything else that already resets at
  those points.

## 0.5.8
- Fixed: Live recheck was doing a redundant `smbclient` read even when
  the scan (in Live SMB read mode) had already taken a live reading
  moments earlier - three calls per applied file (scan read, recheck
  read, write) instead of two. It now reuses that scan-time reading as-is
  when it's already live, only doing a genuinely fresh read when the
  existing value is a cached one (Database compare's `storage_mtime`) or
  missing entirely. Each mismatch now carries a `liveActualMtime` flag so
  `applyMismatch()` can tell the difference.

## 0.5.7
- **Database compare is now the default detection mode** (was Live SMB
  read). Checkbox/radio labels simplified from "(default on)" to
  "(default)", and the Database compare description now explicitly notes
  that Live recheck additionally catches files modified since the last
  scan ran.
- New **Folder (optional)** field under Options: restricts a scan to one
  path within the mount (e.g. `Photos/2020`) and everything under it,
  instead of always scanning the whole mount. Implemented as a `path`
  prefix match directly in the paginated query, so it works cleanly with
  the existing resumable-cursor scanning and needs no changes there.
  Works alongside the existing mount dropdown in both "Scan for
  mismatches" and "Scan & fix all automatically".

## 0.5.6
- Fixed: the new "skipped" log category was missing from the admin
  page's label list, showing the raw lowercase key instead of a proper
  name - now shows "Skipped files".
- Corrected overstated wording: Database compare mode does not "rely on"
  Live recheck for safety - "Never move mtime forward" still checks
  against the cached `storage_mtime` value even with live recheck off,
  just less precisely than with a fresh reading. Live recheck sharpens
  that check and adds an "already fixed by something else" check with no
  DB-mode equivalent, but it's an improvement on top, not a hard
  dependency Database compare mode can't function without.

## 0.5.5
- New "Options" section under the retroactive scan, with three settings:
  - **Detection mode** (default: Live SMB read) - the existing per-file
    `smbclient` comparison, or a new **Database compare** mode that
    checks `mtime` vs `storage_mtime` with a single query per batch, no
    `smbclient` calls during scanning at all. Much faster, but
    `storage_mtime` is Nextcloud's own last-known value, not a live
    reading - the results table's "actual mtime" column is relabeled
    accordingly in this mode.
  - **Live recheck before writing** (default on) - re-reads the file's
    live mtime immediately before writing and confirms it still actually
    disagrees with the intended value. Skips (doesn't fail) a file if
    something else already fixed it since the scan ran, or if the
    current value can't be confirmed. This is what keeps Database
    compare mode safe to use for real writes.
  - **Never move mtime forward** (default on) - refuses to write a
    timestamp later than the most recently known value for that file.
    Catches the case where a file was genuinely edited on the share by
    something unrelated after the scan ran - without this, that edit
    would be silently overwritten by the (older, wrong) cached value.
  - All three are implemented entirely in the retroactive apply path
    (`applyMismatch()`), not the shared low-level fix routine - the
    real-time listener is completely unaffected by any of them.
- New "skipped" log category (default level: Warning) for the two skip
  reasons above.
- Results table gained a Status column - skipped and failed rows now
  show why, instead of failed rows silently having no visible reason
  (a pre-existing gap) and skipped rows having nowhere to appear at all.
  Both "Update selected files" and "Scan & fix all automatically" report
  skip counts alongside fixed/failed counts.

## 0.5.4
- Retroactive apply's success and dry-run log messages now report the
  mtime being corrected *from*, not just what it's being set *to* (e.g.
  "corrected mtime for {path} from 2020-01-01 12:00:00 UTC to
  2025-06-15 09:30:00 UTC"). Only available on the retroactive scan/apply
  path, which knows the previous on-share value from its scan comparison
  - the real-time listener corrects mtime immediately after a write
  without ever reading a prior value, so it still only reports the target
  time. Log-only; nothing shown in the admin UI changed.
- Synced `js/admin.js` and `templates/admin.php` with hand-edited changes
  made directly on GitHub (simplified confirm-dialog wording on both the
  manual apply and auto-fix buttons).

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
