<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Inventory\Application\DTO\OzonInventorySnapshotRequestResult;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Message\SyncOzonInventorySnapshotMessage;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

final readonly class RequestOzonInventorySnapshotAction
{
    public function __construct(
        private MarketplaceFacade $marketplaceFacade,
        private InventorySnapshotSessionRepository $sessionRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private AppLogger $logger,
    ) {
    }

    public function __invoke(string $companyId, SnapshotTriggerType $triggerType, ?string $actorUserId = null): OzonInventorySnapshotRequestResult
    {
        Assert::uuid($companyId);

        if ($actorUserId !== null) {
            Assert::uuid($actorUserId);
        }

        $connections = $this->marketplaceFacade->getActiveOzonSellerConnections($companyId);

        if ($connections === []) {
            $this->logger->info('Inventory snapshot request skipped: no active Ozon SELLER connections.', [
                'companyId' => $companyId,
                'triggerType' => $triggerType->value,
            ]);
            return new OzonInventorySnapshotRequestResult(
                queuedCount: 0,
                skippedCount: 0,
                hasConnections: false,
                hasActiveSession: false,
                messages: ['No active Ozon SELLER connections found.'],
            );
        }

        $validConnectionIds = [];
        $skippedCount = 0;
        $messages = [];

        foreach ($connections as $connection) {
            $connectionId = (string) ($connection['connectionId'] ?? '');
            if ($connectionId === '') {
                $this->logger->warning('Inventory snapshot request skipped: empty connectionId.', [
                    'companyId' => $companyId,
                    'triggerType' => $triggerType->value,
                    'queuedCount' => 0,
                    'skippedCount' => $skippedCount + 1,
                ]);
                ++$skippedCount;
                $messages[] = 'Skipped one connection: empty connectionId.';

                continue;
            }

            if (!Uuid::isValid($connectionId)) {
                $this->logger->warning('Inventory snapshot request skipped: invalid connectionId.', [
                    'companyId' => $companyId,
                    'connectionId' => $connectionId,
                    'triggerType' => $triggerType->value,
                    'queuedCount' => 0,
                    'skippedCount' => $skippedCount + 1,
                ]);
                ++$skippedCount;
                $messages[] = sprintf('Skipped connection with invalid UUID: %s', $connectionId);

                continue;
            }

            $validConnectionIds[] = $connectionId;
        }

        if ($validConnectionIds === []) {
            return new OzonInventorySnapshotRequestResult(
                queuedCount: 0,
                skippedCount: $skippedCount,
                hasConnections: true,
                hasActiveSession: false,
                messages: $messages,
            );
        }

        $activeSession = $this->sessionRepository->findLatestActiveByCompanyAndSource($companyId, MarketplaceType::OZON);

        if ($activeSession !== null) {
            $this->logger->info('Inventory snapshot request skipped: active session already exists.', [
                'companyId' => $companyId,
                'snapshotSessionId' => $activeSession->getId(),
                'triggerType' => $triggerType->value,
                'queuedCount' => 0,
                'skippedCount' => $skippedCount + count($validConnectionIds),
            ]);
            return new OzonInventorySnapshotRequestResult(
                queuedCount: 0,
                skippedCount: $skippedCount + count($validConnectionIds),
                hasConnections: true,
                hasActiveSession: true,
                messages: array_merge($messages, ['Snapshot request skipped: active session already exists.']),
            );
        }

        $session = new InventorySnapshotSession(
            companyId: $companyId,
            source: MarketplaceType::OZON,
            triggerType: $triggerType,
            triggeredBy: $actorUserId,
        );

        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $this->logger->info('Inventory snapshot session created.', [
            'companyId' => $companyId,
            'snapshotSessionId' => $session->getId(),
            'triggerType' => $triggerType->value,
        ]);

        $queuedCount = 0;

        foreach ($validConnectionIds as $connectionId) {

            try {
                $this->messageBus->dispatch(new SyncOzonInventorySnapshotMessage(
                    companyId: $companyId,
                    connectionId: $connectionId,
                    snapshotSessionId: $session->getId(),
                    triggerType: $triggerType->value,
                ));
                ++$queuedCount;
                $this->logger->info('Inventory snapshot message dispatched.', [
                    'companyId' => $companyId,
                    'snapshotSessionId' => $session->getId(),
                    'connectionId' => $connectionId,
                    'triggerType' => $triggerType->value,
                    'queuedCount' => $queuedCount,
                    'skippedCount' => $skippedCount,
                ]);
            } catch (\Throwable $e) {
                ++$skippedCount;
                $this->logger->error('Inventory snapshot message dispatch failed.', $e, [
                    'companyId' => $companyId,
                    'snapshotSessionId' => $session->getId(),
                    'connectionId' => $connectionId,
                    'triggerType' => $triggerType->value,
                    'queuedCount' => $queuedCount,
                    'skippedCount' => $skippedCount,
                    'exceptionClass' => $e::class,
                    'errorMessage' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                $messages[] = sprintf('Failed to queue connection %s: %s', $connectionId, $e->getMessage());
            }
        }

        if ($queuedCount === 0) {
            $session->markFailed('Failed to queue all Ozon inventory snapshot messages.');
            $this->entityManager->flush();
        }

        return new OzonInventorySnapshotRequestResult(
            queuedCount: $queuedCount,
            skippedCount: $skippedCount,
            hasConnections: true,
            hasActiveSession: false,
            messages: $messages,
        );
    }
}
