<?php

declare(strict_types=1);

namespace OCA\SmbMtimeFix\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l,
    ) {
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('nextcloud_smb_mtime_fix', 'app.svg');
    }

    public function getID(): string {
        return 'nextcloud_smb_mtime_fix';
    }

    public function getName(): string {
        return $this->l->t('SMB Mtime Fix');
    }

    public function getPriority(): int {
        return 75;
    }
}
