# Changelog

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
