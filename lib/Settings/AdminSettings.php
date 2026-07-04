<?php

declare(strict_types=1);

namespace OCA\SmbMtimeFix\Settings;

use OCA\SmbMtimeFix\AppInfo\Application;
use OCA\SmbMtimeFix\Service\MtimeFixService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
    public function __construct(
        private MtimeFixService $service,
    ) {
    }

    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'admin');
        Util::addStyle(Application::APP_ID, 'admin');

        $logLevels = [];
        $logLevelDefaults = [];
        foreach (MtimeFixService::CATEGORIES as $category) {
            $logLevels[$category] = $this->service->getCategoryLogLevel($category);
            $logLevelDefaults[$category] = $this->service->getCategoryDefaultLevel($category);
        }

        return new TemplateResponse(Application::APP_ID, 'admin', [
            'dryRunState' => $this->service->getDryRunState(),
            'logLevels' => $logLevels,
            'logLevelDefaults' => $logLevelDefaults,
        ], '');
    }

    public function getSection(): string {
        return 'nextcloud_smb_mtime_fix';
    }

    public function getPriority(): int {
        return 50;
    }
}
