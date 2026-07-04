<?php

declare(strict_types=1);

namespace OCA\SmbMtimeFix\Listener;

use OCA\SmbMtimeFix\Service\MtimeFixService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;

/**
 * @template-implements IEventListener<NodeWrittenEvent>
 */
class MtimeFixListener implements IEventListener {
    public function __construct(
        private MtimeFixService $service,
    ) {
    }

    public function handle(Event $event): void {
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
