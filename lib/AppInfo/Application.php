<?php

declare(strict_types=1);

namespace OCA\SmbMtimeFix\AppInfo;

use OCA\SmbMtimeFix\Listener\MtimeFixListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeWrittenEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'nextcloud_smb_mtime_fix';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Fires after any file write completes, including uploads to
        // external storage. See lib/Service/MtimeFixService.php for the
        // actual fix logic; lib/Listener/MtimeFixListener.php just wires
        // this event to it.
        $context->registerEventListener(NodeWrittenEvent::class, MtimeFixListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
