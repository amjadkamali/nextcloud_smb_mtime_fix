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
    ];
    $levelNames = [
        0 => $l->t('Debug'),
        1 => $l->t('Info'),
        2 => $l->t('Warning'),
        3 => $l->t('Error'),
        4 => $l->t('Critical'),
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
        <?php p($l->t('Scans your configured SMB mounts and compares each file\'s intended mtime against what\'s actually stamped on the share. Runs in small batches so it can\'t time out on a large share - you can stop it partway and keep whatever it found so far. Independent of the dry-run toggle above - nothing is changed until you review the list and click "Update selected files" below.')); ?>
    </p>

    <p>
        <label for="smb-mtime-fix-scan-limit"><?php p($l->t('Limit (optional) - stop after finding this many mismatches:')); ?></label>
        <input type="number" id="smb-mtime-fix-scan-limit" class="input" min="1" placeholder="<?php p($l->t('unlimited')); ?>" style="width: 8em;" />
    </p>
    <button id="smb-mtime-fix-scan" class="button">
        <?php p($l->t('Scan for mismatches')); ?>
    </button>
    <span id="smb-mtime-fix-scan-status"></span>

    <div id="smb-mtime-fix-results" style="display:none;">
        <table class="grid">
            <thead>
                <tr>
                    <th style="width: 2em;"><input type="checkbox" id="smb-mtime-fix-select-all" checked /></th>
                    <th><?php p($l->t('Path')); ?></th>
                    <th><?php p($l->t('Currently recorded mtime')); ?></th>
                    <th><?php p($l->t('Actual mtime on share')); ?></th>
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

    <h4><?php p($l->t('Or: scan and fix everything automatically')); ?></h4>
    <p class="settings-hint">
        <?php p($l->t('Skips the review step above entirely - every mismatch found gets fixed immediately as the scan runs, in small batches, with no pause to look at any of them first. Always writes for real, the same as clicking "Update selected files" does. Make sure you\'ve confirmed a normal scan and manual apply looks correct on your SMB server before trusting this with your whole share. Uses the limit field above, if set, as a cap on total files fixed.')); ?>
    </p>
    <button id="smb-mtime-fix-auto" class="button">
        <?php p($l->t('Scan & fix all automatically')); ?>
    </button>
    <span id="smb-mtime-fix-auto-status"></span>
</div>

