<?php

declare(strict_types=1);

namespace OCA\SmbMtimeFix\Service;

use OCA\Files_External\Lib\Storage\SMB;
use OCA\Files_External\Service\GlobalStoragesService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Single place for all SMB-mtime-correction logic. Used by the real-time
 * event listener (fixMtime) and by the admin-page scan/apply endpoints
 * (scanForMismatches / applyMismatch). Keeping the smbclient invocation and
 * cache-patching logic in one method (applyFix) means both code paths stay
 * behaviorally identical.
 */
class MtimeFixService {
    public const APP_ID = 'nextcloud_smb_mtime_fix';

    // smbclient/SMB mtime resolution and clock skew between client and
    // server can both introduce a few seconds of "false positive" drift.
    // Only flag it as a real mismatch past this threshold.
    private const MISMATCH_THRESHOLD_SECONDS = 2;

    private const DRY_RUN_STATES = ['on', 'temp_off', 'off'];
    private const TEMP_OFF_CACHE_KEY = 'dry_run_temp_off';

    /** @var array<string, array{host:string,share:string,user:string,password:string,domain:string,root:string}|null> */
    private array $resolvedCache = [];

    public function __construct(
        private LoggerInterface $logger,
        private GlobalStoragesService $globalStoragesService,
        private IConfig $config,
        private IDBConnection $db,
        private ICacheFactory $cacheFactory,
    ) {
    }

    // ---------------------------------------------------------------
    // Dry-run toggle - tri-state: on / temp_off / off
    // ---------------------------------------------------------------
    //
    // Governs the AUTOMATIC real-time listener only. The manual retroactive
    // scan/apply flow does NOT consult this - that flow already requires
    // the admin to review a concrete file list and click "Update selected
    // files", which is its own confirmation step. See applyMismatch().
    //
    // - "on" (default): persisted in app config. Survives everything.
    // - "off": persisted in app config. Also survives everything - this is
    //   for admins who are done testing and want the fix applied forever.
    // - "temp_off": the persisted app-config value stays "on" (that's what
    //   it reverts to), and a separate flag is set in Nextcloud's *local*
    //   cache (APCu-backed on a typical single-server install). That cache
    //   is tied to the running PHP worker processes, so restarting
    //   PHP-FPM/Apache/the container clears it and dry-run silently goes
    //   back to "on" without anyone having to remember to re-enable it.
    //
    // CAVEAT: this requires a real local cache to be configured
    // (config.php's 'memcache.local', e.g. \OC\Memcache\APCu). Without one,
    // Nextcloud's local cache is a no-op and "temporarily off" cannot
    // survive between requests at all - setDryRunState() falls back to a
    // fully persistent "off" in that case and logs a warning, since that's
    // safer than silently doing nothing.

    public function getDryRunState(): string {
        $persisted = $this->config->getAppValue(self::APP_ID, 'dry_run_state', 'on');
        if ($persisted !== 'on') {
            return 'off';
        }
        return $this->isTempOffFlagSet() ? 'temp_off' : 'on';
    }

    public function setDryRunState(string $state): void {
        if (!in_array($state, self::DRY_RUN_STATES, true)) {
            $state = 'on';
        }

        if ($state === 'off') {
            $this->config->setAppValue(self::APP_ID, 'dry_run_state', 'off');
            $this->clearTempOff();
            return;
        }

        if ($state === 'temp_off') {
            try {
                $this->cacheFactory->createLocal(self::APP_ID)->set(self::TEMP_OFF_CACHE_KEY, '1');
                $this->config->setAppValue(self::APP_ID, 'dry_run_state', 'on');
            } catch (\Throwable $e) {
                $this->logMessage(
                    self::MESSAGE_TYPE_TEMP_OFF_UNSUPPORTED,
                    'nextcloud_smb_mtime_fix: no local cache (e.g. APCu) configured, so a restart-scoped '
                    . 'temporary override isn\'t possible - falling back to a persistent "off". '
                    . 'Remember to turn dry-run back on manually.'
                );
                $this->config->setAppValue(self::APP_ID, 'dry_run_state', 'off');
            }
            return;
        }

        // 'on'
        $this->config->setAppValue(self::APP_ID, 'dry_run_state', 'on');
        $this->clearTempOff();
    }

    /**
     * Convenience used by the real-time listener.
     */
    public function isDryRunEnabled(): bool {
        return $this->getDryRunState() === 'on';
    }

    private function isTempOffFlagSet(): bool {
        try {
            return $this->cacheFactory->createLocal(self::APP_ID)->get(self::TEMP_OFF_CACHE_KEY) === '1';
        } catch (\Throwable $e) {
            // No local cache available - safe default is "not temporarily
            // off", i.e. respect whatever is persisted (which will be "on").
            return false;
        }
    }

    private function clearTempOff(): void {
        try {
            $this->cacheFactory->createLocal(self::APP_ID)->remove(self::TEMP_OFF_CACHE_KEY);
        } catch (\Throwable $e) {
            // nothing to clean up
        }
    }

    public function isSmbStorage(IStorage $storage): bool {
        return $storage->instanceOfStorage(SMB::class);
    }

    // ---------------------------------------------------------------
    // Log level, grouped into two categories, using Nextcloud's own
    // logger:
    //
    //   - "status": the two routine messages this app writes on every
    //     write to an SMB mount - "[dry-run] would set mtime..." and
    //     "corrected mtime...".
    //   - "errors": everything that indicates something went wrong -
    //     unresolved mounts, smbclient failures, allinfo/parse failures,
    //     and the "no local cache configured" Temporarily-off fallback.
    //
    // Each category maps to whichever Nextcloud/PSR-3 logger method
    // (debug/info/warning/error/critical) you choose for it, set
    // independently. As with any Nextcloud log call, a message only
    // actually gets written if it also meets your instance-wide log level
    // (config.php's "loglevel", or `occ log:manage`) - this only controls
    // which method nextcloud_smb_mtime_fix calls, it doesn't override that.
    // ---------------------------------------------------------------

    private const SEVERITY_DEBUG = 0;
    private const SEVERITY_INFO = 1;
    private const SEVERITY_WARNING = 2;
    private const SEVERITY_ERROR = 3;
    private const SEVERITY_CRITICAL = 4;
    private const APP_LOG_LEVELS = [
        self::SEVERITY_DEBUG, self::SEVERITY_INFO, self::SEVERITY_WARNING,
        self::SEVERITY_ERROR, self::SEVERITY_CRITICAL,
    ];

    // Individual message identifiers - used internally to pick which
    // category a given log call belongs to. Not separately configurable
    // any more; see CATEGORIES.
    public const MESSAGE_TYPE_DRY_RUN = 'dry_run';
    public const MESSAGE_TYPE_SUCCESS = 'success';
    public const MESSAGE_TYPE_RESOLVE_FAILED = 'resolve_failed';
    public const MESSAGE_TYPE_SMBCLIENT_FAILED = 'smbclient_failed';
    public const MESSAGE_TYPE_ALLINFO_FAILED = 'allinfo_failed';
    public const MESSAGE_TYPE_PARSE_FAILED = 'parse_failed';
    public const MESSAGE_TYPE_TEMP_OFF_UNSUPPORTED = 'temp_off_unsupported';

    public const CATEGORY_STATUS = 'status';
    public const CATEGORY_ERRORS = 'errors';

    /** Single source of truth for the admin page to render one row per category. */
    public const CATEGORIES = [self::CATEGORY_STATUS, self::CATEGORY_ERRORS];

    private const MESSAGE_TYPE_CATEGORY = [
        self::MESSAGE_TYPE_DRY_RUN => self::CATEGORY_STATUS,
        self::MESSAGE_TYPE_SUCCESS => self::CATEGORY_STATUS,
        self::MESSAGE_TYPE_RESOLVE_FAILED => self::CATEGORY_ERRORS,
        self::MESSAGE_TYPE_SMBCLIENT_FAILED => self::CATEGORY_ERRORS,
        self::MESSAGE_TYPE_ALLINFO_FAILED => self::CATEGORY_ERRORS,
        self::MESSAGE_TYPE_PARSE_FAILED => self::CATEGORY_ERRORS,
        self::MESSAGE_TYPE_TEMP_OFF_UNSUPPORTED => self::CATEGORY_ERRORS,
    ];

    public function getCategoryLogLevel(string $category): int {
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = self::CATEGORY_STATUS;
        }
        return (int)$this->config->getAppValue(self::APP_ID, $category . '_log_level', (string)self::SEVERITY_WARNING);
    }

    public function setCategoryLogLevel(string $category, int $level): void {
        if (!in_array($category, self::CATEGORIES, true)) {
            return;
        }
        if (!in_array($level, self::APP_LOG_LEVELS, true)) {
            $level = self::SEVERITY_WARNING;
        }
        $this->config->setAppValue(self::APP_ID, $category . '_log_level', (string)$level);
    }

    /**
     * Calls the Nextcloud/PSR-3 logger method matching the given severity.
     */
    private function logAt(int $severity, string $message, array $context = []): void {
        $context['app'] = self::APP_ID;
        match ($severity) {
            self::SEVERITY_DEBUG => $this->logger->debug($message, $context),
            self::SEVERITY_INFO => $this->logger->info($message, $context),
            self::SEVERITY_ERROR => $this->logger->error($message, $context),
            self::SEVERITY_CRITICAL => $this->logger->critical($message, $context),
            default => $this->logger->warning($message, $context),
        };
    }

    /**
     * Logs one of the known message types at whichever level is currently
     * set for its category (see MESSAGE_TYPE_CATEGORY / CATEGORIES).
     */
    private function logMessage(string $messageType, string $message, array $context = []): void {
        $category = self::MESSAGE_TYPE_CATEGORY[$messageType] ?? self::CATEGORY_ERRORS;
        $this->logAt($this->getCategoryLogLevel($category), $message, $context);
    }

    // ---------------------------------------------------------------
    // Real-time path (called from MtimeFixListener on every write)
    // ---------------------------------------------------------------

    /**
     * @return array{ok:bool, dryRun:bool, message:string}
     */
    public function fixMtime(IStorage $storage, string $internalPath, int $intendedMtime): array {
        $conn = $this->resolveBackendOptions($storage);
        if ($conn === null) {
            $msg = "could not resolve SMB connection details for {$internalPath}";
            $this->logMessage(self::MESSAGE_TYPE_RESOLVE_FAILED, 'nextcloud_smb_mtime_fix: ' . $msg);
            return ['ok' => false, 'dryRun' => $this->isDryRunEnabled(), 'message' => $msg];
        }

        $fileId = $storage->getCache()->getId($internalPath);

        return $this->applyFix($conn, $internalPath, $intendedMtime, $fileId, $this->isDryRunEnabled());
    }

    /**
     * Resolve host/share/credentials for the SMB mount backing $storage, by
     * matching its storage ID against the configured mounts known to
     * files_external (which holds the decrypted password).
     *
     * VERIFIED: matches apps/files_external/lib/Lib/Storage/SMB.php::getId()
     * as of nextcloud/server master:
     *   'smb::' . $user . '@' . $host . '//' . $share . '/' . $root
     * (double slash after host is intentional - Nextcloud keeps it for
     * storage-id backward compatibility). $root there is always normalized
     * to have both a leading and trailing slash; see normalizeRootForId().
     *
     * @return array{host:string,share:string,user:string,password:string,domain:string,root:string}|null
     */
    private function resolveBackendOptions(IStorage $storage): ?array {
        $storageId = $storage->getId();
        if (!str_starts_with($storageId, 'smb::')) {
            return null;
        }

        if (array_key_exists($storageId, $this->resolvedCache)) {
            return $this->resolvedCache[$storageId];
        }

        $candidatesTried = [];

        foreach ($this->globalStoragesService->getAllStorages() as $mountConfig) {
            if ($mountConfig->getBackend()->getIdentifier() !== 'smb') {
                continue;
            }

            $options = $mountConfig->getBackendOptions();
            $host = $options['host'] ?? '';
            $share = $options['share'] ?? '';
            $user = $options['user'] ?? '';
            $root = trim($options['root'] ?? '', '/');

            $candidateId = $this->buildStorageId($user, $host, $share, $options['root'] ?? '/');
            $candidatesTried[] = 'mount#' . $mountConfig->getId() . ' => ' . $candidateId;

            if ($candidateId === $storageId) {
                $resolved = [
                    'host' => $host,
                    'share' => $share,
                    'user' => $user,
                    'password' => $options['password'] ?? '',
                    'domain' => $options['domain'] ?? '',
                    'root' => $root,
                ];
                $this->resolvedCache[$storageId] = $resolved;
                return $resolved;
            }
        }

        // DIAGNOSTIC: nothing matched. Logging the real ID next to every
        // candidate ID we computed is the fastest way to tell whether this
        // is a formatting mismatch (compare them character by character) or
        // a mount GlobalStoragesService simply doesn't know about (e.g. a
        // per-user external storage, or an auth mechanism - session
        // credentials, global credentials, Kerberos - where the effective
        // username at runtime isn't the literal 'user' backend option, so
        // no candidate built from admin-configured options could ever
        // match).
        $this->logMessage(
            self::MESSAGE_TYPE_RESOLVE_FAILED,
            'nextcloud_smb_mtime_fix: no configured mount matched storage id "{storageId}". Candidates tried: {candidates}',
            [
                'storageId' => $storageId,
                'candidates' => $candidatesTried !== []
                    ? implode(' | ', $candidatesTried)
                    : '(none - no SMB mounts visible via GlobalStoragesService; if this is a personal/user-added external storage rather than an admin-configured one, that would explain it - only global mounts are checked)',
            ]
        );

        $this->resolvedCache[$storageId] = null;
        return null;
    }

    /**
     * Reproduces \OCA\Files_External\Lib\Storage\SMB::getId(), including
     * its user-splitting (workgroup/user or workgroup\user) and its root
     * normalization, so the storage ID we build here matches exactly what
     * a live SMB storage instance reports.
     */
    private function buildStorageId(string $rawUser, string $host, string $rawShare, string $rawRoot): string {
        $user = $this->extractUsername($rawUser);
        $share = trim($rawShare, '/');
        $root = $this->normalizeRootForId($rawRoot);

        return 'smb::' . $user . '@' . $host . '//' . $share . '/' . $root;
    }

    private function extractUsername(string $rawUser): string {
        if (str_contains($rawUser, '/')) {
            [, $user] = explode('/', $rawUser, 2);
            return $user;
        }
        if (str_contains($rawUser, '\\')) {
            [, $user] = explode('\\', $rawUser, 2);
            return $user;
        }
        return $rawUser;
    }

    /**
     * Matches SMB::__construct()'s root normalization exactly: always a
     * leading slash, always a trailing slash.
     */
    private function normalizeRootForId(string $rawRoot): string {
        $root = $rawRoot === '' ? '/' : $rawRoot;
        $root = '/' . ltrim($root, '/');
        $root = rtrim($root, '/') . '/';
        return $root;
    }

    // ---------------------------------------------------------------
    // Retroactive scan
    // ---------------------------------------------------------------

    /**
     * Scans every configured SMB mount's filecache entries and compares the
     * cached (intended) mtime against what's actually stamped on the share.
     * Returns a flat list an admin can review before applying anything.
     *
     * PERFORMANCE NOTE: this shells out to smbclient once per file to read
     * its real mtime, which is slow on shares with many files. $limit, when
     * greater than 0, caps how many mismatches are collected - not how many
     * files get examined along the way, so a small limit can still take a
     * while if mismatches are sparse. 0 (the default) means unlimited -
     * this is meant to be run on demand from the admin page, not on a
     * tight schedule.
     *
     * @return list<array{storageId:string, mountId:int, fileId:int, path:string, cachedMtime:int, actualMtime:int}>
     */
    public function scanForMismatches(int $limit = 0): array {
        $mismatches = [];

        foreach ($this->globalStoragesService->getAllStorages() as $mountConfig) {
            if ($mountConfig->getBackend()->getIdentifier() !== 'smb') {
                continue;
            }

            $options = $mountConfig->getBackendOptions();
            $host = $options['host'] ?? '';
            $share = $options['share'] ?? '';
            $user = $options['user'] ?? '';
            $password = $options['password'] ?? '';
            $domain = $options['domain'] ?? '';
            $root = trim($options['root'] ?? '', '/');

            $storageId = $this->buildStorageId($user, $host, $share, $options['root'] ?? '/');
            $numericId = $this->getNumericStorageId($storageId);
            if ($numericId === null) {
                continue;
            }

            foreach ($this->iterateCacheEntries($numericId) as $entry) {
                if ($entry['mimetype'] === 'httpd/unix-directory') {
                    continue;
                }

                $actualMtime = $this->queryActualMtime($host, $share, $user, $password, $domain, $root, $entry['path']);
                if ($actualMtime === null) {
                    continue;
                }

                if (abs($actualMtime - (int)$entry['mtime']) > self::MISMATCH_THRESHOLD_SECONDS) {
                    $mismatches[] = [
                        'storageId' => $storageId,
                        'mountId' => $mountConfig->getId(),
                        'fileId' => (int)$entry['fileid'],
                        'path' => $entry['path'],
                        'cachedMtime' => (int)$entry['mtime'],
                        'actualMtime' => $actualMtime,
                    ];

                    if ($limit > 0 && count($mismatches) >= $limit) {
                        return $mismatches;
                    }
                }
            }
        }

        return $mismatches;
    }

    /**
     * Applies a single mismatch found by scanForMismatches(). Called only
     * from the admin "Update selected files" button - i.e. after the admin
     * has already seen the concrete file list and confirmed. This always
     * performs a real write regardless of the dry-run toggle, because the
     * click itself is the confirmation; the dry-run toggle exists to gate
     * the *unattended* real-time path, not a manually-reviewed one.
     *
     * @param array{mountId:int, fileId:int, path:string, cachedMtime:int} $mismatch
     * @return array{ok:bool, dryRun:bool, message:string}
     */
    public function applyMismatch(array $mismatch): array {
        try {
            $mountConfig = $this->globalStoragesService->getStorage((int)$mismatch['mountId']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'dryRun' => false, 'message' => 'mount config not found'];
        }

        if ($mountConfig === null) {
            return ['ok' => false, 'dryRun' => false, 'message' => 'mount config not found'];
        }

        $options = $mountConfig->getBackendOptions();
        $conn = [
            'host' => $options['host'] ?? '',
            'share' => $options['share'] ?? '',
            'user' => $options['user'] ?? '',
            'password' => $options['password'] ?? '',
            'domain' => $options['domain'] ?? '',
            'root' => trim($options['root'] ?? '', '/'),
        ];

        return $this->applyFix($conn, $mismatch['path'], (int)$mismatch['cachedMtime'], (int)$mismatch['fileId'], false);
    }

    // ---------------------------------------------------------------
    // Shared low-level fix routine
    // ---------------------------------------------------------------

    /**
     * @param array{host:string,share:string,user:string,password:string,domain:string,root:string} $conn
     * @return array{ok:bool, dryRun:bool, message:string}
     */
    private function applyFix(array $conn, string $internalPath, int $intendedMtime, int $fileId, bool $dryRun): array {
        ['host' => $host, 'share' => $share, 'user' => $user, 'password' => $password,
            'domain' => $domain, 'root' => $root] = $conn;

        $smbPath = trim(rtrim($root, '/') . '/' . ltrim($internalPath, '/'), '/');

        // NOTE: verify against your SMB server's expected timezone - adjust
        // to date() (local) instead of gmdate() if utimes ends up off by
        // your UTC offset. Test on one file first with
        // `smbclient -c "allinfo <path>"` before trusting this broadly.
        $smbTime = gmdate('Y:m:d-H:i:s', $intendedMtime);

        if ($dryRun) {
            $this->logMessage(
                self::MESSAGE_TYPE_DRY_RUN,
                'nextcloud_smb_mtime_fix: [dry-run] would set mtime for {path} to {time}',
                ['path' => $smbPath, 'time' => $smbTime]
            );
            return ['ok' => true, 'dryRun' => true, 'message' => "would set mtime to {$smbTime}"];
        }

        $cmd = sprintf(
            'smbclient %s -U %s -c %s 2>&1',
            escapeshellarg('//' . $host . '/' . $share),
            escapeshellarg(($domain !== '' ? $domain . '\\' : '') . $user . '%' . $password),
            escapeshellarg(sprintf('utimes "%s" -1 -1 %s -1', $smbPath, $smbTime))
        );

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            $msg = 'smbclient utimes failed: ' . implode("\n", $output);
            $this->logMessage(self::MESSAGE_TYPE_SMBCLIENT_FAILED, 'nextcloud_smb_mtime_fix: {msg} for {path}', [
                'msg' => $msg, 'path' => $smbPath,
            ]);
            return ['ok' => false, 'dryRun' => false, 'message' => $msg];
        }

        // Patch storage_mtime directly by fileId, through a query builder
        // rather than a raw SQL string, so this works whether or not we
        // have a live ICache object for this storage (the retroactive path
        // only has host/share/creds + fileId, not a mounted IStorage).
        if ($fileId !== -1) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('filecache')
                ->set('storage_mtime', $qb->createNamedParameter($intendedMtime, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        }

        $this->logMessage(
            self::MESSAGE_TYPE_SUCCESS,
            'nextcloud_smb_mtime_fix: corrected mtime for {path} to {time}',
            ['path' => $smbPath, 'time' => $smbTime]
        );

        return ['ok' => true, 'dryRun' => false, 'message' => 'mtime corrected'];
    }

    // ---------------------------------------------------------------
    // DB helpers
    // ---------------------------------------------------------------

    private function getNumericStorageId(string $storageId): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('numeric_id')
            ->from('storages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($storageId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? (int)$row['numeric_id'] : null;
    }

    /**
     * @return \Generator<array{fileid:int|string, path:string, mtime:int|string, mimetype:?string}>
     */
    private function iterateCacheEntries(int $numericStorageId): \Generator {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fc.fileid', 'fc.path', 'fc.mtime', 'mt.mimetype')
            ->from('filecache', 'fc')
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->where($qb->expr()->eq('fc.storage', $qb->createNamedParameter($numericStorageId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            yield $row;
        }
        $result->closeCursor();
    }

    /**
     * Reads the real on-disk mtime for a single file via `smbclient
     * allinfo`.
     *
     * PARSING NOTES: Samba's own test suite confirms `allinfo` prints the
     * literal sentinel "NTTIME(0)" for a genuinely zero/unset timestamp -
     * we treat that as mtime 0. For a normal (non-zero) timestamp, some
     * smbclient builds print a human-readable date (the same asctime-style
     * format used by `dir`/`ls`), and some additionally append the raw
     * epoch in parentheses. This parses both: it prefers the parenthesized
     * epoch when present (unambiguous), and otherwise falls back to
     * strtotime() on the human-readable portion.
     *
     * STILL WORTH A QUICK CHECK: exact `allinfo` formatting has drifted
     * across Samba versions, and I couldn't fully confirm the non-zero
     * case byte-for-byte against a live server. Run this by hand once
     * against a real file - `smbclient //host/share -U user%pass -c
     * 'allinfo "path/to/file"'` - and eyeball that a "write_time:" line is
     * present and looks like one of the two forms above before trusting
     * scan results at scale.
     */
    private function queryActualMtime(string $host, string $share, string $user, string $password, string $domain, string $root, string $internalPath): ?int {
        $smbPath = trim(rtrim($root, '/') . '/' . ltrim($internalPath, '/'), '/');

        $cmd = sprintf(
            'smbclient %s -U %s -c %s 2>&1',
            escapeshellarg('//' . $host . '/' . $share),
            escapeshellarg(($domain !== '' ? $domain . '\\' : '') . $user . '%' . $password),
            escapeshellarg(sprintf('allinfo "%s"', $smbPath))
        );

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            $this->logMessage(self::MESSAGE_TYPE_ALLINFO_FAILED, 'nextcloud_smb_mtime_fix: allinfo failed for {path}: {output}', [
                'path' => $smbPath, 'output' => implode("\n", $output),
            ]);
            return null;
        }

        foreach ($output as $line) {
            if (stripos($line, 'write_time:') === false) {
                continue;
            }

            if (stripos($line, 'NTTIME(0)') !== false) {
                return 0;
            }

            // Prefer an explicit epoch in parentheses when present.
            if (preg_match('/\((\d{9,})\)/', $line, $m)) {
                return (int)$m[1];
            }

            // Otherwise parse whatever human-readable date follows the label.
            $value = trim(substr($line, stripos($line, 'write_time:') + strlen('write_time:')));
            if ($value !== '') {
                $parsed = strtotime($value);
                if ($parsed !== false) {
                    return $parsed;
                }
            }
        }

        $this->logMessage(self::MESSAGE_TYPE_PARSE_FAILED, 'nextcloud_smb_mtime_fix: could not parse write_time from allinfo for {path}: {output}', [
            'path' => $smbPath, 'output' => implode("\n", $output),
        ]);
        return null;
    }
}
