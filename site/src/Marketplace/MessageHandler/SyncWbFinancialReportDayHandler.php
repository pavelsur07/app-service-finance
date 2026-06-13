<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportReconciliationService;
use App\Marketplace\Application\Service\WbFinancialReportSyncStatusUpdater;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Exception\WbRawDocumentRefreshConflictException;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final class SyncWbFinancialReportDayHandler
{
    private const LOCK_TTL_SECONDS = 600;
    private const REPORT_TYPE = 'sales_report';
    private const API_ENDPOINT = 'wildberries::finance-sales-reports-detailed';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly WbFinancialReportPeriodResolver $periodResolver,
        private readonly WbFinanceSalesReportClient $financeSalesReportClient,
        private readonly WbFinancialReportSyncStatusUpdater $syncStatusUpdater,
        private readonly WbFinancialReportReconciliationService $reconciliationService,
        private readonly LockFactory $lockFactory,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly int $financeRetryDelaySeconds,
    ) {
    }

    public function __invoke(SyncWbFinancialReportDayMessage $message): void
    {
        $businessDate = $this->periodResolver->normalizeBusinessDate($message->businessDate);
        $mode = FinancialReportSyncMode::from($message->mode);

        $lock = $this->lockFactory->createLock(
            sprintf('marketplace_financial_report_sync:%s:%s:%s:%s', $message->companyId, MarketplaceType::WILDBERRIES->value, self::REPORT_TYPE, $businessDate->format('Y-m-d')),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            $this->logger->warning('WB day sync lock not acquired, skipping.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
            ]);

            return;
        }

        try {
            $this->handle($message, $businessDate, $mode);
        } finally {
            $lock->release();
        }
    }

    private function handle(SyncWbFinancialReportDayMessage $message, \DateTimeImmutable $businessDate, FinancialReportSyncMode $mode): void
    {
        $this->logger->info('WB day sync started.', [
            'company_id' => $message->companyId,
            'connection_id' => $message->connectionId,
            'business_date' => $businessDate->format('Y-m-d'),
            'mode' => $mode->value,
            'force_refresh' => $message->forceRefresh,
        ]);

        $company = $this->em->find(Company::class, $message->companyId);
        if (!$company) {
            $this->logger->warning('WB day sync skipped: company not found.', ['company_id' => $message->companyId]);

            return;
        }

        $connection = $this->connectionRepository->findByIdAndCompany($message->connectionId, $company);
        if (!$connection) {
            $this->logger->warning('WB day sync skipped: connection not found for company.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
            ]);

            return;
        }

        if (!$connection->isActive() || MarketplaceType::WILDBERRIES !== $connection->getMarketplace() || MarketplaceConnectionType::SELLER !== $connection->getConnectionType()) {
            $this->logger->warning('WB day sync skipped: invalid connection state/type.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'is_active' => $connection->isActive(),
                'marketplace' => $connection->getMarketplace()->value,
                'connection_type' => $connection->getConnectionType()->value,
            ]);

            return;
        }

        $status = $this->syncStatusUpdater->findOrCreateForDay($connection->getId(), $message->companyId, self::REPORT_TYPE, self::API_ENDPOINT, $businessDate);
        if (
            null !== $message->rawDocumentId
            && ($status->getStagingRawDocumentId() !== $message->rawDocumentId || $status->getNextRrdId() !== $message->rrdId)
        ) {
            $this->logger->info('WB day sync skipped stale continuation message.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'message_rrd_id' => $message->rrdId,
                'message_raw_document_id' => $message->rawDocumentId,
                'status_rrd_id' => $status->getNextRrdId(),
                'status_raw_document_id' => $status->getStagingRawDocumentId(),
                'status' => $status->getStatus()->value,
            ]);

            return;
        }

        $effectiveRrdId = $status->getNextRrdId() ?? $message->rrdId;
        $effectiveRawDocumentId = $status->getStagingRawDocumentId() ?? $message->rawDocumentId;
        $bucket = $this->financeSalesReportClient->resolveSalesReportsBucket($connection);
        $sellerBucketId = $bucket['bucket_id'];
        $bucketSource = $bucket['bucket_source'];

        if (\in_array($status->getStatus(), [FinancialReportSyncStatus::QUEUED, FinancialReportSyncStatus::FAILED], true)
            && null !== $status->getNextRetryAt()
            && $status->getNextRetryAt() > $this->clock->now()
        ) {
            $this->dispatchContinuation(new SyncWbFinancialReportDayMessage(
                $message->companyId,
                $message->connectionId,
                $message->businessDate,
                $message->mode,
                $message->forceRefresh,
                $effectiveRrdId,
                $effectiveRawDocumentId,
            ), $status->getNextRetryAt());

            $this->logger->info('WB day sync postponed until persisted next retry time.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'bucket_id' => $sellerBucketId,
                'bucket_source' => $bucketSource,
                'rrd_id' => $effectiveRrdId,
                'raw_document_id' => $effectiveRawDocumentId,
                'next_retry_at' => $status->getNextRetryAt()->format(\DateTimeInterface::ATOM),
                'status' => $status->getStatus()->value,
            ]);

            return;
        }

        $cooldownUntil = $this->financeSalesReportClient->getActiveSalesReportsCooldownUntil($sellerBucketId);
        if (null !== $cooldownUntil) {
            $this->syncStatusUpdater->markPageQueued($status, $mode, $message->forceRefresh, $cooldownUntil, $effectiveRawDocumentId, $effectiveRrdId);
            $this->em->flush();
            $this->dispatchContinuation(new SyncWbFinancialReportDayMessage(
                $message->companyId,
                $message->connectionId,
                $message->businessDate,
                $message->mode,
                $message->forceRefresh,
                $effectiveRrdId,
                $effectiveRawDocumentId,
            ), $cooldownUntil);

            $this->logger->info('WB finance cooldown active, API request skipped', [
                'cooldown_until' => $cooldownUntil->format(\DateTimeInterface::ATOM),
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'bucket_id' => $sellerBucketId,
                'bucket_source' => $bucketSource,
            ]);

            return;
        }

        $apiKey = $connection->getApiKey();
        $sellerRateLimitKey = $this->financeSalesReportClient->buildSalesReportsRateLimitKeyForSellerBucket($sellerBucketId);
        $retryAfter = $this->financeSalesReportClient->tryConsume($sellerRateLimitKey);
        if (null !== $retryAfter) {
            $this->syncStatusUpdater->markPageQueued($status, $mode, $message->forceRefresh, $retryAfter, $effectiveRawDocumentId, $effectiveRrdId);
            $this->em->flush();
            $this->dispatchContinuation(new SyncWbFinancialReportDayMessage(
                $message->companyId,
                $message->connectionId,
                $message->businessDate,
                $message->mode,
                $message->forceRefresh,
                $effectiveRrdId,
                $effectiveRawDocumentId,
            ), $retryAfter);

            $this->logger->info('WB day sync postponed by local throttle before API request.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'bucket_id' => $sellerBucketId,
                'bucket_source' => $bucketSource,
                'rrd_id' => $effectiveRrdId,
                'raw_document_id' => $effectiveRawDocumentId,
                'next_retry_at' => $retryAfter->format(\DateTimeInterface::ATOM),
            ]);

            return;
        }

        $this->syncStatusUpdater->markLoading($status, $mode);

        try {
            $page = $this->financeSalesReportClient->fetchDetailedDayPage($connection->getId(), $apiKey, $businessDate, $effectiveRrdId, true, $sellerBucketId);

            if ([] === $page->rows && null === $effectiveRawDocumentId) {
                $this->syncStatusUpdater->markEmpty($status);
                $connection->markSyncSuccess();
                $this->em->flush();

                $this->logger->info('WB day sync finished with empty dataset.', [
                    'company_id' => $message->companyId,
                    'connection_id' => $message->connectionId,
                    'business_date' => $businessDate->format('Y-m-d'),
                    'mode' => $mode->value,
                    'records_count' => 0,
                ]);

                return;
            }

            $rawDocument = $this->reconciliationService->appendRawDocumentPage(
                $company,
                $connection,
                $businessDate,
                $page->rows,
                $message->forceRefresh,
                $effectiveRawDocumentId,
            );

            $this->em->persist($rawDocument);

            if ($page->hasNextPage) {
                $nextRetryAt = $this->retryAfterFromNow($this->financeRetryDelaySeconds);
                $nextRrdId = $page->nextRrdId ?? $effectiveRrdId;
                $rawDocument->markLoading();
                $this->syncStatusUpdater->markPageQueued($status, $mode, $message->forceRefresh, $nextRetryAt, $rawDocument->getId(), $nextRrdId);
                $this->em->flush();

                $this->dispatchContinuation(new SyncWbFinancialReportDayMessage(
                    $message->companyId,
                    $message->connectionId,
                    $message->businessDate,
                    $message->mode,
                    $message->forceRefresh,
                    $nextRrdId,
                    $rawDocument->getId(),
                ), $nextRetryAt);

                $this->logger->info('WB day sync page loaded, next page scheduled.', [
                    'company_id' => $message->companyId,
                    'connection_id' => $message->connectionId,
                    'business_date' => $businessDate->format('Y-m-d'),
                    'mode' => $mode->value,
                    'records_count' => $rawDocument->getRecordsCount(),
                    'raw_document_id' => $rawDocument->getId(),
                    'next_rrd_id' => $page->nextRrdId,
                    'next_retry_at' => $nextRetryAt->format(\DateTimeInterface::ATOM),
                ]);

                return;
            }

            $rawDocument->resetProcessingStatus();
            $rows = $rawDocument->getRawData();
            $this->syncStatusUpdater->markRawLoaded(
                $status,
                $rawDocument->getId(),
                $rawDocument->getRecordsCount(),
                hash('sha256', json_encode($rows, \JSON_THROW_ON_ERROR)),
            );
            $this->syncStatusUpdater->markProcessing($status);
            $connection->markSyncSuccess();
            $this->em->flush();

            $this->messageBus->dispatch(new ProcessDayReportMessage(
                companyId: $message->companyId,
                rawDocumentId: $rawDocument->getId(),
                forceRefresh: $message->forceRefresh,
                syncStatusId: $status->getId(),
                connectionId: $message->connectionId,
                marketplace: MarketplaceType::WILDBERRIES->value,
                reportType: self::REPORT_TYPE,
                mode: $mode->value,
                businessDate: $businessDate->format('Y-m-d'),
            ));

            $this->logger->info('WB day sync finished and processing dispatched.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'records_count' => $rawDocument->getRecordsCount(),
                'raw_document_id' => $rawDocument->getId(),
            ]);
        } catch (MarketplaceRateLimitException $e) {
            $nextRetryAt = $this->financeSalesReportClient->cooldownUntilAfterRemote429($e->getRetryAfter());
            $this->financeSalesReportClient->setSalesReportsCooldownUntil($sellerBucketId, $nextRetryAt);

            $this->syncStatusUpdater->markPageQueued(
                $status,
                $mode,
                $message->forceRefresh,
                $nextRetryAt,
                $effectiveRawDocumentId,
                $effectiveRrdId,
            );
            $this->syncStatusUpdater->recordRetryableError(
                $status,
                $e::class,
                $e->getMessage(),
                $e->getStatusCode(),
                $e->getResponseExcerpt(),
            );
            $this->em->flush();
            $this->dispatchContinuation(new SyncWbFinancialReportDayMessage(
                $message->companyId,
                $message->connectionId,
                $message->businessDate,
                $message->mode,
                $message->forceRefresh,
                $effectiveRrdId,
                $effectiveRawDocumentId,
            ), $nextRetryAt);

            $this->logger->warning('WB finance cooldown set after remote 429', [
                'cooldown_until' => $nextRetryAt->format(\DateTimeInterface::ATOM),
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'bucket_id' => $sellerBucketId,
                'bucket_source' => $bucketSource,
                'rrd_id' => $effectiveRrdId,
                'raw_document_id' => $effectiveRawDocumentId,
                'next_retry_at' => $nextRetryAt->format(\DateTimeInterface::ATOM),
            ]);

            return;
        } catch (MarketplaceAuthException $e) {
            $this->markStagingRawDocumentFailed($status->getStagingRawDocumentId() ?? $effectiveRawDocumentId);
            $this->syncStatusUpdater->markAuthFailed($status, $e::class, $e->getMessage(), $e->getStatusCode(), $e->getResponseExcerpt());
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            $this->logger->error('WB day sync auth failed.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'http_status' => $e->getStatusCode(),
                'error_message' => $e->getMessage(),
            ]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (MarketplaceTemporaryApiException $e) {
            $this->syncStatusUpdater->markFailedRetryablePreservingCursor(
                $status,
                $e::class,
                $e->getMessage(),
                $e->getStatusCode(),
                $e->getResponseExcerpt(),
                null,
                $this->retryAfterFromNow(15 * 60),
                $effectiveRawDocumentId,
                $effectiveRrdId,
            );
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();
            $this->logger->warning('WB day sync temporary API failure.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'http_status' => $e->getStatusCode(),
                'error_message' => $e->getMessage(),
            ]);

            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (WbRawDocumentRefreshConflictException|\InvalidArgumentException $e) {
            $this->markStagingRawDocumentFailed($status->getStagingRawDocumentId() ?? $effectiveRawDocumentId);
            $this->syncStatusUpdater->markConflict($status, $e::class, $e->getMessage(), null, null);
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();
            $this->logger->warning('WB day sync conflict: raw document is in-flight.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
            ]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (MarketplaceBadRequestException|MarketplaceInvalidApiResponseException $e) {
            $this->markStagingRawDocumentFailed($status->getStagingRawDocumentId() ?? $effectiveRawDocumentId);
            $this->syncStatusUpdater->markFailedFinal($status, $e::class, $e->getMessage(), $e->getStatusCode(), $e->getResponseExcerpt());
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();
            $this->logger->error('WB day sync failed with non-retryable payload/response error.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'http_status' => $e->getStatusCode(),
                'error_message' => $e->getMessage(),
            ]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            // Без этого блока неожиданное исключение оставляет статус в LOADING навсегда:
            // такой день не попадает ни в retry-выборку, ни в missing (строка уже существует).
            $this->recordUnexpectedFailure($status, $connection, $e, $effectiveRawDocumentId, $effectiveRrdId);

            $this->logger->error('WB day sync failed with unexpected error.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function recordUnexpectedFailure(
        MarketplaceFinancialReportSyncStatus $status,
        MarketplaceConnection $connection,
        \Throwable $e,
        ?string $effectiveRawDocumentId,
        ?int $effectiveRrdId,
    ): void {
        try {
            $this->syncStatusUpdater->markFailedRetryablePreservingCursor(
                $status,
                $e::class,
                $e->getMessage(),
                null,
                null,
                null,
                $this->retryAfterFromNow(15 * 60),
                $effectiveRawDocumentId,
                $effectiveRrdId,
            );
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();
        } catch (\Throwable $persistError) {
            $this->logger->error('WB day sync could not persist failure status, status may stay stale until stuck-reclaim.', [
                'company_id' => $status->getCompanyId(),
                'business_date' => $status->getBusinessDate()->format('Y-m-d'),
                'persist_error' => $persistError->getMessage(),
            ]);
        }
    }

    private function dispatchContinuation(SyncWbFinancialReportDayMessage $message, \DateTimeImmutable $retryAfter): void
    {
        $delayMs = max(0, ($retryAfter->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000);
        $this->messageBus->dispatch($message, [new DelayStamp($delayMs)]);
    }

    private function retryAfterFromNow(int $seconds): \DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('+%d seconds', max(1, $seconds)));
    }

    private function markStagingRawDocumentFailed(?string $rawDocumentId): void
    {
        if (null === $rawDocumentId) {
            return;
        }

        $rawDocument = $this->em->find(MarketplaceRawDocument::class, $rawDocumentId);
        if (!$rawDocument instanceof MarketplaceRawDocument) {
            return;
        }

        $rawDocument->markFailed();
        $this->em->persist($rawDocument);
    }
}
