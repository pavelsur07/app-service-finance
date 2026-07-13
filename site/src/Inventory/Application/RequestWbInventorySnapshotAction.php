<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Inventory\Application\DTO\WbInventorySnapshotRequestResult;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Message\SyncWbInventorySnapshotMessage;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

final readonly class RequestWbInventorySnapshotAction
{
    public function __construct(
        private MarketplaceFacade $marketplaceFacade,
        private InventorySnapshotSessionRepository $sessionRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private AppLogger $logger,
    ) {
    }

    public function __invoke(string $companyId, SnapshotTriggerType $triggerType, ?string $actorUserId = null): WbInventorySnapshotRequestResult
    {
        Assert::uuid($companyId);
        if (null !== $actorUserId) {
            Assert::uuid($actorUserId);
        }

        $connections = $this->marketplaceFacade->getActiveWbSellerConnections($companyId);
        if ([] === $connections) {
            return new WbInventorySnapshotRequestResult(0, 0, false, false, ['No active Wildberries SELLER connections found.']);
        }

        $activeSession = $this->sessionRepository->findLatestActiveByCompanyAndSource($companyId, MarketplaceType::WILDBERRIES);
        if (null !== $activeSession) {
            return new WbInventorySnapshotRequestResult(0, count($connections), true, true, ['Snapshot request skipped: active session already exists.']);
        }

        $session = new InventorySnapshotSession(
            companyId: $companyId,
            source: MarketplaceType::WILDBERRIES,
            triggerType: $triggerType,
            triggeredBy: $actorUserId,
        );
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $queuedCount = 0;
        $messages = [];
        foreach ($connections as $connection) {
            $connectionId = (string) $connection['connectionId'];
            try {
                $this->messageBus->dispatch(new SyncWbInventorySnapshotMessage(
                    companyId: $companyId,
                    connectionId: $connectionId,
                    snapshotSessionId: $session->getId(),
                    triggerType: $triggerType->value,
                ));
                ++$queuedCount;
            } catch (\Throwable $e) {
                $this->logger->error('WB inventory snapshot message dispatch failed.', $e, [
                    'companyId' => $companyId,
                    'snapshotSessionId' => $session->getId(),
                    'connectionId' => $connectionId,
                ]);
                $messages[] = sprintf('Failed to queue connection %s.', $connectionId);
            }
        }

        $skippedCount = count($connections) - $queuedCount;
        if (0 === $queuedCount) {
            $session->markFailed('Failed to queue all Wildberries inventory snapshot messages.');
            $this->entityManager->flush();
        }

        return new WbInventorySnapshotRequestResult($queuedCount, $skippedCount, true, false, $messages);
    }
}
