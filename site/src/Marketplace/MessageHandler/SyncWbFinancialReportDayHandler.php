<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportReconciliationService;
use App\Marketplace\Application\Service\WbFinancialReportSyncStatusUpdater;
use App\Marketplace\Enum\FinancialReportSyncMode;
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
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

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
    ) {}

    public function __invoke(SyncWbFinancialReportDayMessage $message): void
    {
        $businessDate = $this->periodResolver->normalizeBusinessDate($message->businessDate);
        $mode = FinancialReportSyncMode::from($message->mode);

        $lock = $this->lockFactory->createLock(
            sprintf('marketplace_wb_day_sync:%s:%s:%s', $message->companyId, $message->connectionId, $businessDate->format('Y-m-d')),
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

        $status = $this->syncStatusUpdater->startLoading($connection->getId(), $message->companyId, self::REPORT_TYPE, self::API_ENDPOINT, $businessDate, $mode);

        try {
            $rows = $this->financeSalesReportClient->fetchDetailedDay($connection->getId(), $connection->getApiKey(), $businessDate);

            if ([] === $rows) {
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

            $rawDocument = $this->reconciliationService->createOrRefreshRawDocument(
                $company,
                $connection,
                $businessDate,
                $rows,
                $message->forceRefresh,
            );

            $this->em->persist($rawDocument);
            $this->em->flush();

            $this->syncStatusUpdater->markRawLoaded(
                $status,
                $rawDocument->getId(),
                count($rows),
                hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            );
            $this->syncStatusUpdater->markProcessing($status);
            $connection->markSyncSuccess();
            $this->em->flush();

            $this->messageBus->dispatch(new ProcessDayReportMessage($message->companyId, $rawDocument->getId()));

            $this->logger->info('WB day sync finished and processing dispatched.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
                'records_count' => count($rows),
                'raw_document_id' => $rawDocument->getId(),
            ]);
        } catch (MarketplaceRateLimitException $e) {
            $nextRetryAt = null;
            if (null !== $e->getRetryAfter()) {
                $nextRetryAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', max(1, $e->getRetryAfter())));
            }

            $this->syncStatusUpdater->markFailedRetryable($status, $e::class, $e->getMessage(), $e->getStatusCode(), $e->getResponseExcerpt(), null, $nextRetryAt);
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            $this->logger->warning('WB day sync rate-limited, retry scheduled.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
            ]);

            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e, (($e->getRetryAfter() ?? 61) * 1000));
        } catch (MarketplaceAuthException $e) {
            $this->syncStatusUpdater->markAuthFailed($status, $e::class, $e->getMessage(), $e->getStatusCode(), $e->getResponseExcerpt());
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            $this->logger->error('WB day sync auth failed.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
            ]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (MarketplaceTemporaryApiException $e) {
            $this->syncStatusUpdater->markFailedRetryable($status, $e::class, $e->getMessage(), $e->getStatusCode(), $e->getResponseExcerpt());
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();
            $this->logger->warning('WB day sync temporary API failure.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
            ]);

            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (WbRawDocumentRefreshConflictException $e) {
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
            $this->syncStatusUpdater->markFailedFinal($status, $e::class, $e->getMessage(), $e->getStatusCode(), $e->getResponseExcerpt());
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();
            $this->logger->error('WB day sync failed with non-retryable payload/response error.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'business_date' => $businessDate->format('Y-m-d'),
                'mode' => $mode->value,
            ]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }
}
