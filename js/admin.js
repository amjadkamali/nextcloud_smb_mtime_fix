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

                scanStatus.textContent = ' ' + t('nextcloud_smb_mtime_fix', 'Scanning… {examined} files checked, {count} mismatch(es) found so far.', {
                    examined: examinedTotal, count: lastMismatches.length,
                });

                jsonFetch(apiUrl('/scan'), {
                    method: 'POST',
                    body: JSON.stringify({ cursor: cursor, limit: limit, batchSize: 200 }),
                })
                    .then(function (data) {
                        examinedTotal += data.examined || 0;
                        (data.mismatches || []).forEach(function (m) {
                            lastMismatches.push(m);
                            appendResultRow(m, lastMismatches.length - 1);
                        });

                        if (data.cursor && !scanCancelled) {
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

        // --- apply --------------------------------------------------------------

        // A single request holding thousands of files would run one
        // smbclient process per file, sequentially, inside one PHP request -
        // easy to exceed PHP's max_execution_time or a reverse proxy's read
        // timeout long before it finishes, and an all-or-nothing failure
        // wouldn't tell you how far it got. Chunking keeps each HTTP request
        // short, shows real progress, and means a hiccup only costs you the
        // current chunk - already-applied files stay applied.
        var APPLY_CHUNK_SIZE = 25;

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
                'Update mtimes for {count} file(s) directly on the SMB share? This writes to the share now, regardless of the dry-run setting above.',
                { count: selected.length }
            );
            if (!window.confirm(confirmMsg)) {
                return;
            }

            applyBtn.disabled = true;

            var chunks = [];
            for (var i = 0; i < selected.length; i += APPLY_CHUNK_SIZE) {
                chunks.push(selected.slice(i, i + APPLY_CHUNK_SIZE));
            }

            var total = selected.length;
            var totalOk = 0;
            var totalDone = 0;
            var hadRequestFailure = false;

            function runChunk(chunkIndex) {
                if (chunkIndex >= chunks.length) {
                    applyStatus.textContent = ' ' + t('nextcloud_smb_mtime_fix', '{ok}/{total} updated.{note}', {
                        ok: totalOk,
                        total: total,
                        note: hadRequestFailure ? ' ' + t('nextcloud_smb_mtime_fix', '(one or more batches failed - check the server log; unfixed files are still listed below)') : '',
                    });
                    applyBtn.disabled = false;
                    return;
                }

                var chunk = chunks[chunkIndex];
                applyStatus.textContent = ' ' + t('nextcloud_smb_mtime_fix', 'Applying… {done}/{total}', {
                    done: totalDone,
                    total: total,
                });

                jsonFetch(apiUrl('/apply'), {
                    method: 'POST',
                    body: JSON.stringify({ items: chunk }),
                })
                    .then(function (data) {
                        var results = data.results || [];
                        results.forEach(function (r) {
                            if (r.ok) {
                                totalOk++;
                                removeResultRow(r.path);
                            }
                        });
                    })
                    .catch(function () {
                        hadRequestFailure = true;
                    })
                    .finally(function () {
                        totalDone += chunk.length;
                        runChunk(chunkIndex + 1);
                    });
            }

            runChunk(0);
        });
    });
})();
