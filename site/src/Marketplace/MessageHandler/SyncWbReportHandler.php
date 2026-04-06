<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\SyncWbReportMessage;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Загружает сырые данные WB за последние 7 дней и сохраняет MarketplaceRawDocument.
 * Без обработки продаж/возвратов/затрат — только загрузка.
 */
#[AsMessageHandler]
final class SyncWbReportHandler
{
    private const SYNC_PERIOD_DAYS = 7;
    private const LOCK_TTL_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncWbReportMessage $message): void
    {
        $companyId    = $message->companyId;
        $connectionId = $message->connectionId;

        $lock = $this->lockFactory->createLock(
            'marketplace_sync_' . $companyId . '_wildberries',
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            $this->logger->warning('WB sync already in progress, skipping', [
                'company_id'    => $companyId,
                'connection_id' => $connectionId,
            ]);

            return;
        }

        try {
            $this->process($companyId, $connectionId);
        } finally {
            $lock->release();
        }
    }

    private function process(string $companyId, string $connectionId): void
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company) {
            $this->logger->error('Company not found for WB sync', ['company_id' => $companyId]);

            return;
        }

        $connection = $this->em->find(MarketplaceConnection::class, $connectionId);
        if (!$connection) {
            $this->logger->error('MarketplaceConnection not found', ['connection_id' => $connectionId]);

            return;
        }

        if (!$connection->isActive()) {
            $this->logger->info('WB connection is inactive, skipping', ['connection_id' => $connectionId]);

            return;
        }

        $connection->markSyncStarted();
        $this->em->flush();

        try {
            $adapter  = $this->adapterRegistry->get(MarketplaceType::WILDBERRIES);
            $fromDate = new \DateTimeImmutable(sprintf('-%d days', self::SYNC_PERIOD_DAYS));
            $toDate   = new \DateTimeImmutable();

            $rawData = $adapter->fetchRawReport($company, $fromDate, $toDate);

            if (empty($rawData)) {
                $this->logger->info('WB API returned empty report', [
                    'company_id' => $companyId,
                    'period'     => $fromDate->format('Y-m-d') . ' - ' . $toDate->format('Y-m-d'),
                ]);
                $connection->markSyncSuccess();
                $this->em->flush();

                return;
            }

            $rawDoc = new MarketplaceRawDocument(
                Uuid::uuid4()->toString(),
                $company,
                MarketplaceType::WILDBERRIES,
                'sales_report',
            );
            $rawDoc->setPeriodFrom($fromDate);
            $rawDoc->setPeriodTo($toDate);
            $rawDoc->setApiEndpoint($adapter->getApiEndpointName());
            $rawDoc->setRawData($rawData);
            $rawDoc->setRecordsCount(count($rawData));

            $this->em->persist($rawDoc);
            $this->em->flush();

            $this->logger->info('WB raw report saved', [
                'company_id'    => $companyId,
                'connection_id' => $connectionId,
                'raw_doc_id'    => $rawDoc->getId(),
                'records_count' => count($rawData),
                'period'        => $fromDate->format('Y-m-d') . ' - ' . $toDate->format('Y-m-d'),
            ]);

            $connection->markSyncSuccess();
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('WB daily sync failed', [
                'company_id'    => $companyId,
                'connection_id' => $connectionId,
                'error'         => $e->getMessage(),
            ]);

            try {
                $connection = $this->em->find(MarketplaceConnection::class, $connectionId);
                if ($connection) {
                    $connection->markSyncFailed($e->getMessage());
                    $this->em->flush();
                }
            } catch (\Throwable $inner) {
                $this->logger->error('Failed to save WB sync error status', [
                    'error' => $inner->getMessage(),
                ]);
            }
        }
    }
}
