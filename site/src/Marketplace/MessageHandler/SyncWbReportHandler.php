<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncWbReportMessage;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Загружает сырые данные WB за предыдущий день и сохраняет MarketplaceRawDocument.
 * После успешной загрузки диспатчит ProcessDayReportMessage для автозапуска pipeline.
 */
#[AsMessageHandler]
final class SyncWbReportHandler
{
    private const LOCK_TTL_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
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
            $adapter   = $this->adapterRegistry->get(MarketplaceType::WILDBERRIES);
            $msk       = new \DateTimeZone('Europe/Moscow');
            $yesterday = new \DateTimeImmutable('yesterday', $msk);
            $fromDate  = $yesterday->setTime(0, 0, 0);
            $toDate    = $yesterday->setTime(23, 59, 59);

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

            $this->messageBus->dispatch(new ProcessDayReportMessage(
                companyId: $companyId,
                marketplace: MarketplaceType::WILDBERRIES->value,
                date: $yesterday->format('Y-m-d'),
            ));

            $this->logger->info('Dispatched auto-processing for WB day report', [
                'company_id' => $companyId,
                'date'       => $yesterday->format('Y-m-d'),
            ]);
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
