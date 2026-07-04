<?php

declare(strict_types=1);

namespace OCA\SmbMtimeFix\Controller;

use OCA\SmbMtimeFix\Service\MtimeFixService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class AdminController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private MtimeFixService $service,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Manual admin-only gate. Written explicitly (rather than relying on an
     * attribute) so this keeps working on the older end of the supported
     * Nextcloud range (min-version 27 in info.xml).
     */
    private function requireAdmin(): ?JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return new JSONResponse(['message' => 'admin only'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    /**
     * The service layer already catches everything it reasonably can
     * (see MtimeFixService::logUnexpectedError() and its callers), but
     * this is the outermost backstop for the admin endpoints themselves -
     * if anything still slips through (a bad request param, an OCP API
     * behaving unexpectedly), the admin page gets a clean JSON error
     * instead of Nextcloud's generic 500/stack-trace page, and nothing
     * partially-applied is left in an unclear state.
     */
    private function runSafely(callable $action): JSONResponse {
        try {
            return $action();
        } catch (\Throwable $e) {
            $this->logger->error('nextcloud_smb_mtime_fix: unexpected error in admin endpoint: {msg}', [
                'msg' => $e->getMessage(),
                'exception' => $e,
                'app' => MtimeFixService::APP_ID,
            ]);
            return new JSONResponse(['message' => 'unexpected error - see server log'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    public function getDryRun(): JSONResponse {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->runSafely(function () {
            return new JSONResponse(['state' => $this->service->getDryRunState()]);
        });
    }

    public function setDryRun(): JSONResponse {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->runSafely(function () {
            $state = (string)$this->request->getParam('state', 'on');
            $this->service->setDryRunState($state);
            return new JSONResponse(['state' => $this->service->getDryRunState()]);
        });
    }

    public function scan(): JSONResponse {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->runSafely(function () {
            // 0 (or missing/invalid) means unlimited - see MtimeFixService::scanForMismatches().
            $limit = (int)$this->request->getParam('limit', '0');
            $mismatches = $this->service->scanForMismatches(max($limit, 0));
            return new JSONResponse(['mismatches' => $mismatches]);
        });
    }

    public function getLogLevel(): JSONResponse {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->runSafely(function () {
            $category = (string)$this->request->getParam('category', MtimeFixService::CATEGORY_STATUS);
            return new JSONResponse(['category' => $category, 'level' => $this->service->getCategoryLogLevel($category)]);
        });
    }

    public function setLogLevel(): JSONResponse {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->runSafely(function () {
            $category = (string)$this->request->getParam('category', MtimeFixService::CATEGORY_STATUS);
            $level = (int)$this->request->getParam('level', 2);
            $this->service->setCategoryLogLevel($category, $level);
            return new JSONResponse(['category' => $category, 'level' => $this->service->getCategoryLogLevel($category)]);
        });
    }

    public function apply(): JSONResponse {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->runSafely(function () {
            $items = $this->request->getParam('items', []);
            if (!is_array($items)) {
                $items = [];
            }

            $results = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $outcome = $this->service->applyMismatch($item);
                $results[] = array_merge(['path' => $item['path'] ?? ''], $outcome);
            }

            return new JSONResponse(['results' => $results]);
        });
    }
}
