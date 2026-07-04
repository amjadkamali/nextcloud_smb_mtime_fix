(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('smb-mtime-fix-settings');
        if (!root) {
            return;
        }

        var dryRunRadios = document.querySelectorAll('input[name="smb-mtime-fix-dry-run"]');
        var logLevelSelects = document.querySelectorAll('.smb-mtime-fix-log-level-select');
        var logLevelStatus = document.getElementById('smb-mtime-fix-log-level-msg');
        var scanBtn = document.getElementById('smb-mtime-fix-scan');
        var scanLimitInput = document.getElementById('smb-mtime-fix-scan-limit');
        var mountSelect = document.getElementById('smb-mtime-fix-mount-select');
        var scanStatus = document.getElementById('smb-mtime-fix-scan-status');
        var resultsWrap = document.getElementById('smb-mtime-fix-results');
        var resultsBody = document.getElementById('smb-mtime-fix-results-body');
        var selectAll = document.getElementById('smb-mtime-fix-select-all');
        var applyBtn = document.getElementById('smb-mtime-fix-apply');
        var applyStatus = document.getElementById('smb-mtime-fix-apply-status');

        var lastMismatches = [];

        function apiUrl(path) {
            return OC.generateUrl('/apps/nextcloud_smb_mtime_fix' + path);
        }

        function jsonFetch(url, options) {
            options = options || {};
            options.headers = Object.assign({
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json',
            }, options.headers || {});
            return fetch(url, options).then(function (r) {
                return r.json();
            });
        }

        function escapeHtml(s) {
            var div = document.createElement('div');
            div.textContent = s == null ? '' : String(s);
            return div.innerHTML;
        }

        function formatDate(ts) {
            return new Date(ts * 1000).toLocaleString();
        }

        function getSelectedMountId() {
            var raw = mountSelect ? mountSelect.value : '';
            return raw === '' ? null : parseInt(raw, 10);
        }

        // --- dry-run tri-state ------------------------------------------------

        dryRunRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) {
                    return;
                }
                var previousValue = root.dataset.dryRunState;
                jsonFetch(apiUrl('/dry-run'), {
                    method: 'POST',
                    body: JSON.stringify({ state: radio.value }),
                })
                    .then(function (data) {
                        root.dataset.dryRunState = data.state;
                    })
                    .catch(function () {
                        // revert the UI so it doesn't lie about server state
                        dryRunRadios.forEach(function (r) {
                            r.checked = (r.value === previousValue);
                        });
                    });
            });
        });

        // --- per-message-type log level -----------------------------------

        logLevelSelects.forEach(function (select) {
            var previousValue = select.value;
            var category = select.dataset.category;

            select.addEventListener('change', function () {
                var newValue = select.value;
                jsonFetch(apiUrl('/log-level'), {
                    method: 'POST',
                    body: JSON.stringify({ category: category, level: parseInt(newValue, 10) }),
                })
                    .then(function (data) {
                        previousValue = String(data.level);
                        select.value = previousValue;
                        logLevelStatus.textContent = ' ' + t('nextcloud_smb_mtime_fix', 'Updated.');
                    })
                    .catch(function () {
                        select.value = previousValue;
                        logLevelStatus.textContent = ' ' + t('nextcloud_smb_mtime_fix', 'Failed to update - check the server log.');
                    });
            });
        });

        // --- scan -------------------------------------------------------------

        // Each request only examines a bounded batch of files (server-side
        // default 200) and returns a cursor to resume from - so no single
        // request can run long enough to hit PHP's max_execution_time or a
        // reverse proxy's read timeout, no matter how large the share is.
        // The browser just keeps following the cursor until the server
        // reports there's nothing left (or the admin clicks Stop).
        var scanCancelled = false;

        scanBtn.addEventListener('click', function () {
            if (scanBtn.dataset.mode === 'stop') {
                scanCancelled = true;
                return;
            }

            scanBtn.textContent = t('nextcloud_smb_mtime_fix', 'Stop scanning');
            scanBtn.dataset.mode = 'stop';
            resultsWrap.style.display = 'none';
            resultsBody.innerHTML = '';
            lastMismatches = [];
            scanCancelled = false;

            var limitRaw = scanLimitInput ? scanLimitInput.value.trim() : '';
            var limit = 0;
            if (limitRaw !== '') {
                var parsedLimit = parseInt(limitRaw, 10);
                if (parsedLimit > 0) {
                    limit = parsedLimit;
                }
            }

            var examinedTotal = 0;

            function finish(statusText) {
                scanStatus.textContent = ' ' + statusText;
                scanBtn.textContent = t('nextcloud_smb_mtime_fix', 'Scan for mismatches');
                scanBtn.dataset.mode = 'scan';
            }

            function runBatch(cursor) {
                if (scanCancelled) {
                    finish(t('nextcloud_smb_mtime_fix', 'Stopped. {examined} files checked, {count} mismatch(es) found so far.', {
                        examined: examinedTotal, count: lastMismatches.length,
                    }));
                    return;
                }

                // The server has no memory between batch calls, so it can
                // only cap mismatches found *within one call* - the
                // cumulative stop has to happen here. We pass only the
                // remaining budget so a single batch can't overshoot past
                // the limit either.
                if (limit > 0 && lastMismatches.length >= limit) {
                    finish(t('nextcloud_smb_mtime_fix', '{examined} files checked, {count} mismatched file(s) found (limit reached).', {
                        examined: examinedTotal, count: lastMismatches.length,
                    }));
                    return;
                }
                var remainingLimit = limit > 0 ? Math.max(limit - lastMismatches.length, 1) : 0;

                scanStatus.textContent = ' ' + t('nextcloud_smb_mtime_fix', 'Scanning… {examined} files checked, {count} mismatch(es) found so far.', {
                    examined: examinedTotal, count: lastMismatches.length,
                });

                jsonFetch(apiUrl('/scan'), {
                    method: 'POST',
                    body: JSON.stringify({ cursor: cursor, limit: remainingLimit, batchSize: 200, mountId: getSelectedMountId() }),
                })
                    .then(function (data) {
                        examinedTotal += data.examined || 0;
                        (data.mismatches || []).forEach(function (m) {
                            lastMismatches.push(m);
                            appendResultRow(m, lastMismatches.length - 1);
                        });

                        if (limit > 0 && lastMismatches.length >= limit) {
                            finish(t('nextcloud_smb_mtime_fix', '{examined} files checked, {count} mismatched file(s) found (limit reached).', {
                                examined: examinedTotal, count: lastMismatches.length,
                            }));
                        } else if (data.cursor && !scanCancelled) {
                            runBatch(data.cursor);
                        } else {
                            finish(lastMismatches.length
                                ? t('nextcloud_smb_mtime_fix', '{examined} files checked, {count} mismatched file(s) found.', { examined: examinedTotal, count: lastMismatches.length })
                                : t('nextcloud_smb_mtime_fix', '{examined} files checked, no mismatches found.', { examined: examinedTotal }));
                        }
                    })
                    .catch(function () {
                        finish(t('nextcloud_smb_mtime_fix', 'Scan failed - check the server log. {examined} files checked, {count} mismatch(es) found before the failure.', {
                            examined: examinedTotal, count: lastMismatches.length,
                        }));
                    });
            }

            runBatch(null);
        });

        function appendResultRow(m, index) {
            var tr = document.createElement('tr');
            tr.dataset.path = m.path;
            tr.innerHTML =
                '<td><input type="checkbox" class="smb-mtime-fix-row" checked data-index="' + index + '" /></td>' +
                '<td>' + escapeHtml(m.path) + '</td>' +
                '<td>' + formatDate(m.cachedMtime) + '</td>' +
                '<td>' + formatDate(m.actualMtime) + '</td>';
            resultsBody.appendChild(tr);
            resultsWrap.style.display = '';
        }

        // Removes a single fixed file's row from the table (and marks it null
        // in lastMismatches so it's skipped if the admin clicks Apply again
        // without re-scanning). Leaves failed rows in place so they stay
        // visible and selectable for a retry.
        function removeResultRow(path) {
            var idx = lastMismatches.findIndex(function (m) {
                return m && m.path === path;
            });
            if (idx !== -1) {
                lastMismatches[idx] = null;
            }
            var row = resultsBody.querySelector('tr[data-path="' + CSS.escape(path) + '"]');
            if (row) {
                row.remove();
            }
            if (!resultsBody.querySelector('tr')) {
                resultsWrap.style.display = 'none';
            }
        }

        selectAll.addEventListener('change', function () {
            resultsBody.querySelectorAll('.smb-mtime-fix-row').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
        });

        // --- shared: apply a list of items in bounded, sequential chunks ------

        // A single request holding thousands of files would run one
        // smbclient process per file, sequentially, inside one PHP request -
        // easy to exceed PHP's max_execution_time or a reverse proxy's read
        // timeout long before it finishes, and an all-or-nothing failure
        // wouldn't tell you how far it got. Chunking keeps each HTTP request
        // short and means a hiccup only costs you the current chunk -
        // already-applied files stay applied. Shared by the manual "Update
        // selected files" button and the automatic scan-and-fix flow below,
        // so both get this for free and can't drift apart.
        var APPLY_CHUNK_SIZE = 25;

        function applyItemsInChunks(items, onProgress, onComplete) {
            var chunks = [];
            for (var i = 0; i < items.length; i += APPLY_CHUNK_SIZE) {
                chunks.push(items.slice(i, i + APPLY_CHUNK_SIZE));
            }

            var okCount = 0;
            var dryRunCount = 0;
            var failCount = 0;
            var hadRequestFailure = false;

            function runChunk(chunkIndex) {
                if (chunkIndex >= chunks.length) {
                    onComplete({ ok: okCount, dryRun: dryRunCount, failed: failCount, hadRequestFailure: hadRequestFailure });
                    return;
                }

                var chunk = chunks[chunkIndex];
                jsonFetch(apiUrl('/apply'), {
                    method: 'POST',
                    body: JSON.stringify({ items: chunk }),
                })
                    .then(function (data) {
                        (data.results || []).forEach(function (r) {
                            if (r.ok && r.dryRun) {
                                // Dry-run means nothing was actually written -
                                // leave the row in place so it's still there
                                // to apply for real later.
                                dryRunCount++;
                            } else if (r.ok) {
                                okCount++;
                                removeResultRow(r.path);
                            } else {
                                failCount++;
                            }
                        });
                    })
                    .catch(function () {
                        hadRequestFailure = true;
                        failCount += chunk.length;
                    })
                    .finally(function () {
                        onProgress(okCount, dryRunCount, failCount);
                        runChunk(chunkIndex + 1);
                    });
            }

            runChunk(0);
        }

        // --- apply (manual, from the reviewed results list) --------------------

        applyBtn.addEventListener('click', function () {
            var selected = [];
            resultsBody.querySelectorAll('.smb-mtime-fix-row').forEach(function (cb) {
                if (cb.checked) {
                    selected.push(lastMismatches[parseInt(cb.dataset.index, 10)]);
                }
            });

            if (!selected.length) {
                return;
            }

            var confirmMsg = t(
                'nextcloud_smb_mtime_fix',
                'Update mtimes for {count} file(s) on the SMB share? With dry-run on (see the setting above), this only logs what it would do - turn dry-run off first if you want it to write for real.',
                { count: selected.length }
            );
            if (!window.confirm(confirmMsg)) {
                return;
            }

            applyBtn.disabled = true;
            var total = selected.length;

            applyItemsInChunks(
                selected,
                function (ok, dryRun, failed) {
                    applyStatus.textContent = ' ' + t('nextcloud_smb_mtime_fix', 'Applying… {done}/{total}', {
                        done: ok + dryRun + failed,
                        total: total,
                    });
                },
                function (result) {
                    var note = '';
                    if (result.dryRun > 0) {
                        note += ' ' + t('nextcloud_smb_mtime_fix', '{count} logged as dry-run (nothing written).', { count: result.dryRun });
                    }
                    if (result.hadRequestFailure) {
                        note += ' ' + t('nextcloud_smb_mtime_fix', '(one or more batches failed - check the server log; unfixed files are still listed below)');
                    }
                    applyStatus.textContent = ' ' + t('nextcloud_smb_mtime_fix', '{ok}/{total} updated.{note}', {
                        ok: result.ok,
                        total: total,
                        note: note,
                    });
                    applyBtn.disabled = false;
                }
            );
        });

        // --- scan & fix all automatically ---------------------------------------

        // Chains the same bounded scan batches used above with the same
        // chunked apply helper, so this inherits both timeout fixes for
        // free: no single request ever holds more than one scan batch
        // (200 files examined) or one apply chunk (25 files written).
        var autoBtn = document.getElementById('smb-mtime-fix-auto');
        var autoStatus = document.getElementById('smb-mtime-fix-auto-status');
        var autoCancelled = false;
        var backgroundRunActive = false;

        function setManualControlsDisabled(disabled) {
            scanBtn.disabled = disabled;
            applyBtn.disabled = disabled;
        }

        autoBtn.addEventListener('click', function () {
            if (autoBtn.dataset.mode === 'stop') {
                autoCancelled = true;
                return;
            }

            if (backgroundRunActive) {
                // Guards against double-starting from this same browser tab
                // (e.g. a stray double-click). Doesn't protect against a
                // second admin or a second tab starting one at the same
                // time - only a server-side lock could do that, which this
                // doesn't have.
                return;
            }

            var confirmMsg = t(
                'nextcloud_smb_mtime_fix',
                'This scans the selected mount(s) and immediately applies every mismatch it finds, with no review step. It respects the dry-run setting above - with dry-run on, it only logs what it would do; turn dry-run off first if you want it to write for real. Make sure you\'ve already confirmed a normal scan and manual "Update selected files" looks correct on your SMB server. Continue?'
            );
            if (!window.confirm(confirmMsg)) {
                return;
            }

            backgroundRunActive = true;
            autoCancelled = false;
            autoBtn.textContent = t('nextcloud_smb_mtime_fix', 'Stop');
            autoBtn.dataset.mode = 'stop';
            setManualControlsDisabled(true);

            var limitRaw = scanLimitInput ? scanLimitInput.value.trim() : '';
            var fixLimit = 0;
            if (limitRaw !== '') {
                var parsedLimit = parseInt(limitRaw, 10);
                if (parsedLimit > 0) {
                    fixLimit = parsedLimit;
                }
            }

            var examined = 0;
            var fixed = 0;
            var wouldFix = 0;
            var failed = 0;

            function finish(note) {
                var parts = [t('nextcloud_smb_mtime_fix', '{examined} files checked', { examined: examined })];
                if (wouldFix > 0) {
                    parts.push(t('nextcloud_smb_mtime_fix', '{count} would be fixed (dry-run - nothing written)', { count: wouldFix }));
                }
                parts.push(t('nextcloud_smb_mtime_fix', '{count} fixed', { count: fixed }));
                if (failed > 0) {
                    parts.push(t('nextcloud_smb_mtime_fix', '{count} failed', { count: failed }));
                }
                autoStatus.textContent = ' ' + parts.join(', ') + '.' + (note ? ' ' + note : '');
                autoBtn.textContent = t('nextcloud_smb_mtime_fix', 'Scan & fix all automatically');
                autoBtn.dataset.mode = 'scan';
                backgroundRunActive = false;
                setManualControlsDisabled(false);
            }

            function progressText() {
                return t('nextcloud_smb_mtime_fix', 'Working… {examined} checked, {fixed} fixed{wouldFixPart} so far.', {
                    examined: examined,
                    fixed: fixed,
                    wouldFixPart: wouldFix > 0 ? t('nextcloud_smb_mtime_fix', ' ({wouldFix} dry-run)', { wouldFix: wouldFix }) : '',
                });
            }

            function runScanBatch(cursor) {
                if (autoCancelled) {
                    finish(t('nextcloud_smb_mtime_fix', '(stopped)'));
                    return;
                }

                autoStatus.textContent = ' ' + progressText();

                jsonFetch(apiUrl('/scan'), {
                    // Always unlimited per scan batch - the fix-count limit
                    // is enforced below, across the whole run, not per batch.
                    method: 'POST',
                    body: JSON.stringify({ cursor: cursor, limit: 0, batchSize: 200, mountId: getSelectedMountId() }),
                })
                    .then(function (scanData) {
                        examined += scanData.examined || 0;
                        var found = scanData.mismatches || [];

                        if (!found.length) {
                            if (scanData.cursor && !autoCancelled) {
                                runScanBatch(scanData.cursor);
                            } else {
                                finish();
                            }
                            return;
                        }

                        applyItemsInChunks(
                            found,
                            function (ok, dryRun) {
                                autoStatus.textContent = ' ' + t('nextcloud_smb_mtime_fix', 'Working… {examined} checked, {fixed} fixed{wouldFixPart} so far.', {
                                    examined: examined,
                                    fixed: fixed + ok,
                                    wouldFixPart: (wouldFix + dryRun) > 0 ? t('nextcloud_smb_mtime_fix', ' ({wouldFix} dry-run)', { wouldFix: wouldFix + dryRun }) : '',
                                });
                            },
                            function (result) {
                                fixed += result.ok;
                                wouldFix += result.dryRun;
                                failed += result.failed;

                                // In dry-run, "fixed" never grows (nothing is
                                // ever written), so the fix-count limit only
                                // makes sense against real writes - if
                                // dry-run is on for the whole run, this cap
                                // simply won't trigger and the scan runs to
                                // completion, which is the same "just show me
                                // what dry-run would do" behavior as before.
                                if (fixLimit > 0 && fixed >= fixLimit) {
                                    finish();
                                    return;
                                }
                                if (scanData.cursor && !autoCancelled) {
                                    runScanBatch(scanData.cursor);
                                } else {
                                    finish();
                                }
                            }
                        );
                    })
                    .catch(function () {
                        finish(t('nextcloud_smb_mtime_fix', '(scan request failed - check the server log)'));
                    });
            }

            runScanBatch(null);
        });
    });
})();
