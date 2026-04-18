<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncOzonReportMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Загружает сырые данные Ozon за последние 3 дня и сохраняет MarketplaceRawDocument.
 * После успешной загрузки диспатчит ProcessDayReportMessage для автозапуска pipeline.
 */
#[AsMessageHandler]
final class SyncOzonReportHandler
{
    private const SYNC_PERIOD_DAYS = 3;
    private const LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
    ) {
    }

    public function __invoke(SyncOzonReportMessage $message): void
    {
        $companyId    = $message->companyId;
        $connectionId = $message->connectionId;
        $dateKey      = $message->date ?? (new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');

        $lock = $this->lockFactory->createLock(
            'marketplace_sync_' . $companyId . '_ozon_' . $dateKey,
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            $this->logger->warning('Ozon sync already in progress, skipping', [
                'company_id'    => $companyId,
                'connection_id' => $connectionId,
            ]);

            return;
        }

        try {
            $this->process($companyId, $connectionId, $message->date);
        } finally {
            $lock->release();
        }
    }

    private function process(string $companyId, string $connectionId, ?string $date = null): void
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company) {
            $this->logger->error('Company not found for Ozon sync', ['company_id' => $companyId]);

            return;
        }

        $connection = $this->em->find(MarketplaceConnection::class, $connectionId);
        if (!$connection) {
            $this->logger->error('MarketplaceConnection not found', ['connection_id' => $connectionId]);

            return;
        }

        if (!$connection->isActive()) {
            $this->logger->info('Ozon connection is inactive, skipping', ['connection_id' => $connectionId]);

            return;
        }

        $timezone = new \DateTimeZone('Europe/Moscow');

        if ($date !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                $this->logger->error('Invalid date in SyncOzonReportMessage, skipping', [
                    'company_id' => $companyId,
                    'date'       => $date,
                ]);

                return;
            }
            $fromDate = $parsed;
            $toDate   = $parsed;
        } else {
            $toDate   = new \DateTimeImmutable('yesterday', $timezone);
            $fromDate = $toDate;
        }

        // Idempotency: skip если за эту дату уже есть raw_document в любом НЕ-FAILED статусе
        // (null / pending / running / completed). Закрывает гонку между двумя
        // SyncOzonReportMessage, когда первый прогон ещё in-flight (raw_document
        // создан, pipeline не успел проставить completed) — без этого второй воркер
        // создавал дубль. FAILED-документы в выборку не попадают — для них retry
        // должен создать новый, что сохраняет существующую retry-семантику.
        $existingDoc = $this->rawDocumentRepository->findExistingDayDocument(
            $company,
            MarketplaceType::OZON,
            'sales_report',
            $fromDate,
        );

        if ($existingDoc !== null) {
            $this->logger->info('Skipping Ozon sync: raw document already exists for this day', [
                'company_id'       => $companyId,
                'connection_id'    => $connectionId,
                'date'             => $fromDate->format('Y-m-d'),
                'existing_doc_id'  => $existingDoc->getId(),
                'existing_status'  => $existingDoc->getProcessingStatus()?->value,
            ]);

            return;
        }

        $connection->markSyncStarted();
        $this->em->flush();

        $rawDocId = null;

        try {
            $adapter = $this->adapterRegistry->get(MarketplaceType::OZON);

            $rawData = $adapter->fetchRawReport($company, $fromDate, $toDate);

            if (empty($rawData)) {
                $this->logger->info('Ozon API returned empty report', [
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
                MarketplaceType::OZON,
                'sales_report',
            );
            $rawDoc->setPeriodFrom($fromDate);
            $rawDoc->setPeriodTo($toDate);
            $rawDoc->setApiEndpoint($adapter->getApiEndpointName());
            $rawDoc->setRawData($rawData);
            $rawDoc->setRecordsCount(count($rawData));

            $this->em->persist($rawDoc);
            $this->em->flush();

            $rawDocId = $rawDoc->getId();

            $this->logger->info('Ozon raw report saved', [
                'company_id'    => $companyId,
                'connection_id' => $connectionId,
                'raw_doc_id'    => $rawDocId,
                'records_count' => count($rawData),
                'period'        => $fromDate->format('Y-m-d') . ' - ' . $toDate->format('Y-m-d'),
            ]);

            $connection = $this->em->find(MarketplaceConnection::class, $connectionId);
            $connection->markSyncSuccess();
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Ozon daily sync failed', [
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
                $this->logger->error('Failed to save Ozon sync error status', [
                    'error' => $inner->getMessage(),
                ]);
            }

            return;
        }

        try {
            $this->messageBus->dispatch(new ProcessDayReportMessage(
                companyId: $companyId,
                rawDocumentId: $rawDocId,
            ));

            $this->logger->info('Dispatched auto-processing for Ozon day report', [
                'company_id'      => $companyId,
                'raw_document_id' => $rawDocId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dispatch auto-processing for Ozon', [
                'company_id'      => $companyId,
                'raw_document_id' => $rawDocId,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
