# Changelog

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
