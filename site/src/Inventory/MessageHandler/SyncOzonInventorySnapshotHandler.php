<?php

declare(strict_types=1);

namespace App\Inventory\MessageHandler;

use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Exception\OzonInventoryRateLimitException;
use App\Inventory\Infrastructure\Api\Ozon\OzonInventoryClient;
use App\Inventory\Message\SyncOzonInventorySnapshotMessage;
use App\Inventory\Message\NormalizeInventorySnapshotMessage;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class SyncOzonInventorySnapshotHandler
{
    private const ENDPOINT = '/v4/product/info/stocks';
    private const PAGE_LIMIT = 1000;

    public function __construct(
        private readonly InventorySnapshotSessionRepository $sessionRepository,
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly OzonInventoryClient $ozonInventoryClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly AppLogger $logger,
    ) {
    }

    public function __invoke(SyncOzonInventorySnapshotMessage $message): void
    {
        $this->logger->info('Inventory snapshot handler started.', [
            'companyId' => $message->companyId,
            'snapshotSessionId' => $message->snapshotSessionId,
            'connectionId' => $message->connectionId,
            'triggerType' => $message->triggerType,
        ]);

        $session = $this->sessionRepository->findByIdAndCompany($message->snapshotSessionId, $message->companyId);
        if (null === $session) {
            $this->logger->warning('Inventory snapshot session not found, message acknowledged.', ['snapshotSessionId' => $message->snapshotSessionId, 'companyId' => $message->companyId]);
            return;
        }
        if (in_array($session->getStatus(), [SnapshotSessionStatus::Completed, SnapshotSessionStatus::Partial, SnapshotSessionStatus::Failed], true)) {
            return;
        }

        $credentials = $this->marketplaceFacade->getConnectionCredentials($message->companyId, MarketplaceType::OZON, MarketplaceConnectionType::SELLER);
        if (null === $credentials || '' === trim((string) ($credentials['api_key'] ?? '')) || '' === trim((string) ($credentials['client_id'] ?? ''))) {
            $this->logger->warning('Inventory snapshot credentials missing for Ozon SELLER connection.', [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $message->snapshotSessionId,
                'connectionId' => $message->connectionId,
                'marketplace' => MarketplaceType::OZON->value,
                'connectionType' => MarketplaceConnectionType::SELLER->value,
            ]);
            $session->markFailed('Ozon SELLER credentials not found for inventory snapshot fetching.');
            $this->entityManager->flush();
            return;
        }

        $session->markInProgress();
        $this->entityManager->flush();

        $savedPages = 0;
        $lastId = null;
        $page = 1;

        try {
            do {
                $this->logger->info('Inventory snapshot page fetch started.', [
                    'snapshotSessionId' => $session->getId(),
                    'page' => $page,
                    'hasLastId' => null !== $lastId,
                    'limit' => self::PAGE_LIMIT,
                ]);
                $startedAt = microtime(true);
                $response = $this->ozonInventoryClient->fetchStocks((string) $credentials['client_id'], (string) $credentials['api_key'], self::PAGE_LIMIT, $lastId);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                $rawSnapshot = new InventoryRawSnapshot(
                    companyId: $message->companyId,
                    snapshotSessionId: $session->getId(),
                    source: MarketplaceType::OZON,
                    sourceEndpoint: self::ENDPOINT,
                    requestParams: array_filter([
                        'connectionId' => $message->connectionId,
                        'marketplace' => MarketplaceType::OZON->value,
                        'page' => $page,
                        'last_id' => $lastId,
                        'limit' => self::PAGE_LIMIT,
                        'requestedAt' => $session->getStartedAt()->format(DATE_ATOM),
                        'correlationId' => $session->getCorrelationId(),
                    ], static fn (mixed $value): bool => null !== $value),
                    responseStatus: 200,
                    responseBody: $response->raw,
                    fetchedAt: new \DateTimeImmutable(),
                    fetchDurationMs: max(0, $durationMs),
                    correlationId: $session->getCorrelationId(),
                    pageNumber: $page,
                );

                $this->entityManager->persist($rawSnapshot);
                ++$savedPages;
                $session->incrementReceivedPages();
                $this->entityManager->flush();
                $this->logger->info('Inventory snapshot page saved.', [
                    'snapshotSessionId' => $session->getId(),
                    'page' => $page,
                    'durationMs' => max(0, $durationMs),
                    'receivedPages' => $session->getReceivedPages(),
                ]);

                $lastId = $response->nextLastId;
                ++$page;
            } while (null !== $lastId);

            $session->markCompleted();
            $this->entityManager->flush();

            $this->messageBus->dispatch(new NormalizeInventorySnapshotMessage(
                companyId: $message->companyId,
                snapshotSessionId: $session->getId(),
                source: MarketplaceType::OZON->value,
            ));

            $this->logger->info('Inventory normalization dispatched after completed raw snapshot sync.', [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $session->getId(),
                'source' => MarketplaceType::OZON->value,
                'correlationId' => $session->getCorrelationId(),
            ]);
        } catch (OzonInventoryRateLimitException $e) {
            $this->logger->warning('Ozon inventory rate limit while fetching raw snapshots.', [
                'snapshotSessionId' => $session->getId(),
                'companyId' => $message->companyId,
                'savedPages' => $savedPages,
                'errorMessage' => $e->getMessage(),
            ]);
            $savedPages > 0
                ? $session->markPartial('Rate limit exceeded while fetching Ozon inventory snapshots.')
                : $session->markFailed('Rate limit exceeded before first Ozon inventory page was saved.');
            $this->entityManager->flush();

            $this->logger->info('Inventory normalization skipped because snapshot session is not completed.', [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $session->getId(),
                'source' => MarketplaceType::OZON->value,
                'correlationId' => $session->getCorrelationId(),
                'status' => $session->getStatus()->value,
            ]);

            return;
        } catch (\Throwable $e) {
            $this->logger->error('Inventory snapshot fetching failed with unhandled exception.', [
                'snapshotSessionId' => $session->getId(),
                'companyId' => $message->companyId,
                'connectionId' => $message->connectionId,
                'savedPages' => $savedPages,
                'exceptionClass' => $e::class,
                'errorMessage' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $savedPages > 0
                ? $session->markPartial('Inventory snapshot fetching failed after partial save: '.$e->getMessage())
                : $session->markFailed('Inventory snapshot fetching failed before saving pages: '.$e->getMessage());
            $this->entityManager->flush();

            $this->logger->info('Inventory normalization skipped because snapshot session is not completed.', [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $session->getId(),
                'source' => MarketplaceType::OZON->value,
                'correlationId' => $session->getCorrelationId(),
                'status' => $session->getStatus()->value,
            ]);

            return;
        }
    }
}
