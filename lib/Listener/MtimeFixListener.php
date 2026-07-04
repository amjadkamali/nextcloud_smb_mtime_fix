<?php

declare(strict_types=1);

namespace OCA\SmbMtimeFix\Listener;

use OCA\SmbMtimeFix\Service\MtimeFixService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<NodeWrittenEvent>
 */
class MtimeFixListener implements IEventListener {
    public function __construct(
        private MtimeFixService $service,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        // Absolute last line of defense: this fires inline during every
        // real Nextcloud file write, for every storage backend, not just
        // SMB. MtimeFixService already catches everything it reasonably
        // can internally, but if literally anything here still throws -
        // even something as basic as $event->getNode() misbehaving - the
        // actual file write happening in Nextcloud core must never be
        // disrupted by this app. Swallow and log, always.
        try {
            $this->handleInner($event);
        } catch (\Throwable $e) {
            $this->logger->error('nextcloud_smb_mtime_fix: unexpected error in event listener: {msg}', [
                'msg' => $e->getMessage(),
                'exception' => $e,
                'app' => MtimeFixService::APP_ID,
            ]);
        }
    }

    private function handleInner(Event $event): void {
        if (!($event instanceof NodeWrittenEvent)) {
            return;
        }

        $node = $event->getNode();

        try {
            $storage = $node->getStorage();
        } catch (\Throwable $e) {
            return;
        }

        // Only SMB mounts need this - Local storage's touch() already works,
        // and other backends (S3, SFTP, etc.) have their own separate bugs
        // if any, out of scope here.
        if (!$this->service->isSmbStorage($storage)) {
            return;
        }

        // Respects the dry-run toggle internally - see MtimeFixService.
        $this->service->fixMtime($storage, $node->getInternalPath(), $node->getMTime());
    }
}
