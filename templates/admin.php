<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="smb-mtime-fix-settings" class="section" data-dry-run-state="<?php p($_['dryRunState']); ?>">
    <h2><?php p($l->t('SMB Mtime Fix')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Corrects file modification times on SMB external storage mounts, which the SMB backend otherwise silently ignores.')); ?>
    </p>

    <h3><?php p($l->t('Dry run')); ?></h3>
    <div id="smb-mtime-fix-dry-run-options">
        <p>
            <input type="radio" name="smb-mtime-fix-dry-run" id="smb-mtime-fix-dry-run-on" class="radio"
                   value="on" <?php p($_['dryRunState'] === 'on' ? 'checked' : ''); ?> />
            <label for="smb-mtime-fix-dry-run-on">
                <strong><?php p($l->t('On')); ?></strong>
                &mdash;
                <?php p($l->t('log what would be fixed on every write, never modify anything (default)')); ?>
            </label>
        </p>
        <p>
            <input type="radio" name="smb-mtime-fix-dry-run" id="smb-mtime-fix-dry-run-temp" class="radio"
                   value="temp_off" <?php p($_['dryRunState'] === 'temp_off' ? 'checked' : ''); ?> />
            <label for="smb-mtime-fix-dry-run-temp">
                <strong><?php p($l->t('Temporarily off')); ?></strong>
                &mdash;
                <?php p($l->t('apply fixes for real until the next restart of the Nextcloud PHP workers, then automatically switch back to On')); ?>
            </label>
        </p>
        <p>
            <input type="radio" name="smb-mtime-fix-dry-run" id="smb-mtime-fix-dry-run-off" class="radio"
                   value="off" <?php p($_['dryRunState'] === 'off' ? 'checked' : ''); ?> />
            <label for="smb-mtime-fix-dry-run-off">
                <strong><?php p($l->t('Off')); ?></strong>
                &mdash;
                <?php p($l->t('apply fixes for real, permanently (survives restarts too)')); ?>
            </label>
        </p>
    </div>
    <p class="settings-hint">
        <?php p($l->t('"Temporarily off" is useful for testing: it needs a local cache (e.g. APCu) configured to survive between requests, and reverts to "On" automatically the next time PHP restarts, so you can\'t forget to turn it back on.')); ?>
    </p>

    <hr/>

    <h3><?php p($l->t('nextcloud_smb_mtime_fix log levels')); ?></h3>
    <p class="settings-hint">
        <?php p($l->t('Each category logs through Nextcloud\'s normal logger at whichever level you pick - set them independently. As with any Nextcloud log call, a message only actually gets written if it also meets your instance-wide log level (config.php\'s "loglevel", or occ log:manage).')); ?>
    </p>
    <?php
    $logLevelLabels = [
        'status' => $l->t('Dry-run output & success messages'),
        'errors' => $l->t('Errors'),
        'skipped' => $l->t('Skipped files'),
    ];
    $levelNames = [
        0 => $l->t('Debug'),
        1 => $l->t('Info'),
        2 => $l->t('Warning'),
        3 => $l->t('Error'),
        4 => $l->t('Fatal'),
    ];
    ?>
    <table class="grid" style="max-width: 700px;">
        <tbody>
            <?php foreach ($_['logLevels'] as $category => $level): ?>
            <?php $defaultLevel = $_['logLevelDefaults'][$category] ?? 2; ?>
            <tr>
                <td><?php p($logLevelLabels[$category] ?? $category); ?></td>
                <td>
                    <select id="smb-mtime-fix-log-level-<?php p($category); ?>" class="select smb-mtime-fix-log-level-select" data-category="<?php p($category); ?>">
                        <?php foreach ($levelNames as $value => $name): ?>
                        <option value="<?php p($value); ?>" <?php p((int)$level === $value ? 'selected' : ''); ?>>
                            <?php p($value === $defaultLevel ? $name . ' ' . $l->t('(default)') : $name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <span id="smb-mtime-fix-log-level-msg"></span>

    <hr/>

    <h3><?php p($l->t('Find files affected before this app was installed')); ?></h3>
    <p class="settings-hint">
        <?php p($l->t('Scans your configured SMB mounts and compares each file\'s intended mtime against what\'s actually stamped on the share. Runs in small batches so it can\'t time out on a large share - you can stop it partway and keep whatever it found so far. Both actions respect the dry-run setting above.')); ?>
    </p>

    <p>
        <label for="smb-mtime-fix-mount-select"><?php p($l->t('SMB mount:')); ?></label>
        <select id="smb-mtime-fix-mount-select" class="select">
            <option value=""><?php p($l->t('All mounts')); ?></option>
            <?php foreach ($_['smbMounts'] as $mount): ?>
            <option value="<?php p($mount['id']); ?>"><?php p($mount['label']); ?></option>
            <?php endforeach; ?>
        </select>
    </p>

    <h4><?php p($l->t('Options')); ?></h4>
    <p>
        <label for="smb-mtime-fix-path-filter"><?php p($l->t('Folder (optional) - restrict the scan to this path and everything under it:')); ?></label><br/>
        <input type="text" id="smb-mtime-fix-path-filter" class="input" style="width: 100%; max-width: 400px;" placeholder="<?php p($l->t('e.g. Photos/2020 - blank scans the whole mount')); ?>" />
    </p>
    <p>
        <label for="smb-mtime-fix-scan-limit"><?php p($l->t('Limit (optional) - stop after finding/fixing this many:')); ?></label>
        <input type="number" id="smb-mtime-fix-scan-limit" class="input" min="1" placeholder="<?php p($l->t('unlimited')); ?>" style="width: 8em;" />
    </p>
    <p>
        <strong><?php p($l->t('Detection mode:')); ?></strong><br/>
        <input type="radio" name="smb-mtime-fix-detection-mode" id="smb-mtime-fix-detection-smb" class="radio"
               value="smb" <?php p($_['detectionMode'] === 'smb' ? 'checked' : ''); ?> />
        <label for="smb-mtime-fix-detection-smb">
            <?php p($l->t('Live SMB read')); ?> &mdash;
            <?php p($l->t('reads each file\'s real mtime off the share via smbclient during the scan. Slower (one smbclient call per file), but always current at scan time.')); ?>
        </label><br/>
        <input type="radio" name="smb-mtime-fix-detection-mode" id="smb-mtime-fix-detection-db" class="radio"
               value="db" <?php p($_['detectionMode'] === 'db' ? 'checked' : ''); ?> />
        <label for="smb-mtime-fix-detection-db">
            <?php p($l->t('Database compare (default)')); ?> &mdash;
            <?php p($l->t('compares two already-cached database columns with a single query - no smbclient calls at all during scanning, much faster. The "actual mtime" this finds is Nextcloud\'s own last-known value, not a live reading. "Never move mtime forward" below still checks against that cached value even without a live recheck - "Live recheck before writing" just sharpens it to a fresh reading, and additionally catches files that may have been modified since the last scan ran.')); ?>
        </label>
    </p>
    <p>
        <input type="checkbox" id="smb-mtime-fix-live-recheck" class="checkbox" <?php p($_['liveRecheckEnabled'] ? 'checked' : ''); ?> />
        <label for="smb-mtime-fix-live-recheck">
            <strong><?php p($l->t('Live recheck before writing (default)')); ?></strong> &mdash;
            <?php p($l->t('right before writing, confirms the file\'s mtime still actually disagrees with the intended value. With Live SMB read, reuses that scan\'s own reading instead of reading the file again (close enough - no extra smbclient call); with Database compare, does a fresh read, since the scan never took one. Skips the file if something else already fixed it, or if the current value can\'t be confirmed.')); ?>
        </label>
    </p>
    <p>
        <input type="checkbox" id="smb-mtime-fix-never-forward" class="checkbox" <?php p($_['neverForwardEnabled'] ? 'checked' : ''); ?> />
        <label for="smb-mtime-fix-never-forward">
            <strong><?php p($l->t('Never move mtime forward (default)')); ?></strong> &mdash;
            <?php p($l->t('refuses to write a timestamp later than the most recently known value for that file. A legitimate fix should only ever move a timestamp backward - a forward move means something unrelated changed the file for real since, and writing over it would silently destroy that.')); ?>
        </label>
    </p>

    <button id="smb-mtime-fix-scan" class="button">
        <?php p($l->t('Scan for mismatches')); ?>
    </button>
    <button id="smb-mtime-fix-auto" class="button">
        <?php p($l->t('Scan & fix all automatically')); ?>
    </button>
    <span id="smb-mtime-fix-scan-status"></span>
    <span id="smb-mtime-fix-auto-status"></span>

    <div id="smb-mtime-fix-results" style="display:none;">
        <table class="grid">
            <thead>
                <tr>
                    <th style="width: 2em;"><input type="checkbox" id="smb-mtime-fix-select-all" checked /></th>
                    <th><?php p($l->t('Path')); ?></th>
                    <th><?php p($l->t('Currently recorded mtime')); ?></th>
                    <th id="smb-mtime-fix-actual-mtime-header"><?php p($l->t('Actual mtime on share')); ?></th>
                    <th><?php p($l->t('Status')); ?></th>
                </tr>
            </thead>
            <tbody id="smb-mtime-fix-results-body"></tbody>
        </table>
        <button id="smb-mtime-fix-apply" class="button primary">
            <?php p($l->t('Update selected files')); ?>
        </button>
        <span id="smb-mtime-fix-apply-status"></span>
    </div>

    <hr/>

    <details id="smb-mtime-fix-advanced">
        <summary style="cursor: pointer; font-weight: bold;"><?php p($l->t('Advanced')); ?></summary>
        <div style="margin-top: 0.75em;">
            <h4><?php p($l->t('Test allinfo parsing')); ?></h4>
            <?php if ($_['smbMounts'] === []): ?>
            <p class="settings-hint">
                <?php p($l->t('No SMB mounts configured yet - nothing to test against.')); ?>
            </p>
            <?php else: ?>
            <p class="settings-hint">
                <?php p($l->t('Runs smbclient allinfo against one specific file and shows exactly what it returned and how this app parsed it - the same logic the scan uses to read a file\'s real mtime. Use this to confirm that parsing matches your Samba server before trusting scan results at scale. Read-only - never writes anything.')); ?>
            </p>
            <p>
                <label for="smb-mtime-fix-debug-mount"><?php p($l->t('Mount:')); ?></label>
                <select id="smb-mtime-fix-debug-mount" class="select">
                    <?php foreach ($_['smbMounts'] as $mount): ?>
                    <option value="<?php p($mount['id']); ?>"><?php p($mount['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="smb-mtime-fix-debug-path"><?php p($l->t('File path, relative to the mount (e.g. folder/file.txt):')); ?></label><br/>
                <input type="text" id="smb-mtime-fix-debug-path" class="input" style="width: 100%; max-width: 500px;" placeholder="folder/file.txt" />
            </p>
            <button id="smb-mtime-fix-debug-btn" class="button">
                <?php p($l->t('Test allinfo parsing')); ?>
            </button>
            <span id="smb-mtime-fix-debug-status"></span>

            <div id="smb-mtime-fix-debug-result" style="display:none; margin-top: 1em;">
                <p><strong><?php p($l->t('Parsed result:')); ?></strong> <span id="smb-mtime-fix-debug-parsed"></span></p>
                <p><strong><?php p($l->t('Line matched:')); ?></strong> <code id="smb-mtime-fix-debug-line"></code></p>
                <p><strong><?php p($l->t('Raw allinfo output:')); ?></strong></p>
                <pre id="smb-mtime-fix-debug-raw" style="background: var(--color-background-dark, #f0f0f0); padding: 0.75em; overflow-x: auto; white-space: pre-wrap; border-radius: 4px;"></pre>
            </div>
            <?php endif; ?>
        </div>
    </details>
</div>
