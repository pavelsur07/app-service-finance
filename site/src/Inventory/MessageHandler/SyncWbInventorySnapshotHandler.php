<?php

declare(strict_types=1);

namespace App\Inventory\MessageHandler;

use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Exception\WbInventoryApiException;
use App\Inventory\Exception\WbInventoryRateLimitException;
use App\Inventory\Exception\WbInventoryTemporaryApiException;
use App\Inventory\Infrastructure\Api\Wildberries\WbInventoryClient;
use App\Inventory\Message\NormalizeInventorySnapshotMessage;
use App\Inventory\Message\SyncWbInventorySnapshotMessage;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceApiException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class SyncWbInventorySnapshotHandler
{
    public function __construct(
        private InventorySnapshotSessionRepository $sessionRepository,
        private MarketplaceFacade $marketplaceFacade,
        private WbInventoryClient $inventoryClient,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private AppLogger $logger,
        private int $pageLimit = WbInventoryClient::DEFAULT_LIMIT,
    ) {
    }

    public function __invoke(SyncWbInventorySnapshotMessage $message): void
    {
        $session = $this->sessionRepository->findByIdAndCompany($message->snapshotSessionId, $message->companyId);
        if (null === $session || MarketplaceType::WILDBERRIES !== $session->getSource()) {
            $this->logger->warning('WB inventory snapshot session not found or has wrong source.', [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $message->snapshotSessionId,
            ]);

            return;
        }
        if (in_array($session->getStatus(), [SnapshotSessionStatus::Completed, SnapshotSessionStatus::Partial, SnapshotSessionStatus::Failed], true)) {
            return;
        }

        $credentials = $this->marketplaceFacade->getConnectionCredentials(
            $message->companyId,
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
            $message->connectionId,
        );
        if (null === $credentials || '' === trim($credentials['api_key'])) {
            $session->markFailed('Wildberries SELLER credentials not found for inventory snapshot fetching.');
            $this->entityManager->flush();

            return;
        }

        $session->markInProgress();
        $this->entityManager->flush();

        $savedPages = 0;
        $offset = 0;
        $page = 1;
        $seenPageHashes = [];

        try {
            $this->marketplaceFacade->refreshWbListingCatalog($message->companyId, $message->connectionId);

            do {
                $startedAt = microtime(true);
                $response = $this->inventoryClient->fetchStocks($credentials['api_key'], $this->pageLimit, $offset);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                $pageHash = hash('sha256', json_encode($response->items, \JSON_THROW_ON_ERROR));
                if (isset($seenPageHashes[$pageHash])) {
                    throw new WbInventoryApiException('WB Inventory API repeated a pagination page.');
                }
                $seenPageHashes[$pageHash] = true;

                $rawSnapshot = new InventoryRawSnapshot(
                    companyId: $message->companyId,
                    snapshotSessionId: $session->getId(),
                    source: MarketplaceType::WILDBERRIES,
                    sourceEndpoint: WbInventoryClient::ENDPOINT,
                    requestParams: [
                        'connectionId' => $message->connectionId,
                        'marketplace' => MarketplaceType::WILDBERRIES->value,
                        'page' => $page,
                        'offset' => $offset,
                        'limit' => $this->pageLimit,
                        'requestedAt' => $session->getStartedAt()->format(\DATE_ATOM),
                        'correlationId' => $session->getCorrelationId(),
                    ],
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

                $offset += count($response->items);
                ++$page;
            } while ($response->hasNextPage);

            $session->markCompleted();
            $this->entityManager->flush();
        } catch (MarketplaceRateLimitException|MarketplaceTemporaryApiException|WbInventoryRateLimitException|WbInventoryTemporaryApiException $e) {
            $this->logger->warning('WB inventory snapshot temporary API failure.', [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $session->getId(),
                'savedPages' => $savedPages,
                'exceptionClass' => $e::class,
                'errorMessage' => $e->getMessage(),
            ]);
            $this->finishFailedSession($session, $savedPages, 'Temporary Wildberries API failure while fetching inventory.');

            return;
        } catch (MarketplaceApiException|WbInventoryApiException $e) {
            $this->logger->error('WB inventory snapshot API failure.', null, [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $session->getId(),
                'savedPages' => $savedPages,
                'exceptionClass' => $e::class,
                'errorMessage' => $e->getMessage(),
            ]);
            $this->finishFailedSession($session, $savedPages, 'Wildberries API rejected the inventory snapshot request.');

            return;
        } catch (\Throwable $e) {
            $this->logger->error('WB inventory snapshot failed.', $e, [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $session->getId(),
                'savedPages' => $savedPages,
            ]);
            $this->finishFailedSession($session, $savedPages, 'Unexpected Wildberries inventory snapshot failure.');

            return;
        }

        $this->messageBus->dispatch(new NormalizeInventorySnapshotMessage(
            companyId: $message->companyId,
            snapshotSessionId: $session->getId(),
            source: MarketplaceType::WILDBERRIES->value,
        ));
    }

    private function finishFailedSession(InventorySnapshotSession $session, int $savedPages, string $message): void
    {
        if (0 === $savedPages) {
            $session->markFailed($message);
        } else {
            $session->markPartial($message);
        }
        $this->entityManager->flush();
    }
}
