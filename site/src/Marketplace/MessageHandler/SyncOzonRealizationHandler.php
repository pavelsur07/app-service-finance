<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\SyncOzonRealizationMessage;
use App\Marketplace\Service\Integration\OzonRealizationFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncOzonRealizationHandler
{
    private const LOCK_TTL_SECONDS = 300;
    private const DOCUMENT_TYPE = 'realization';
    private const API_ENDPOINT = 'ozon::v2/finance/realization';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OzonRealizationFetcher $fetcher,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncOzonRealizationMessage $message): void
    {
        $lockKey = sprintf(
            'ozon_realization_%s_%d_%02d',
            $message->companyId,
            $message->year,
            $message->month,
        );

        $lock = $this->lockFactory->createLock($lockKey, self::LOCK_TTL_SECONDS);

        if (!$lock->acquire()) {
            $this->logger->warning('Ozon realization sync already in progress, skipping', [
                'company_id' => $message->companyId,
                'year'       => $message->year,
                'month'      => $message->month,
            ]);

            return;
        }

        try {
            $this->process($message);
        } finally {
            $lock->release();
        }
    }

    private function process(SyncOzonRealizationMessage $message): void
    {
        $company = $this->em->find(Company::class, $message->companyId);
        if (!$company) {
            $this->logger->error('Company not found for Ozon realization sync', [
                'company_id' => $message->companyId,
            ]);

            return;
        }

        $connection = $this->em->find(MarketplaceConnection::class, $message->connectionId);
        if (!$connection) {
            $this->logger->error('MarketplaceConnection not found', [
                'connection_id' => $message->connectionId,
            ]);

            return;
        }

        if (!$connection->isActive()) {
            $this->logger->info('Connection is inactive, skipping', [
                'connection_id' => $message->connectionId,
            ]);

            return;
        }

        $year  = $message->year;
        $month = $message->month;

        $this->logger->info('Ozon realization sync started', [
            'company_id' => $message->companyId,
            'year'       => $year,
            'month'      => $month,
        ]);

        try {
            $rawData = $this->fetcher->fetch($connection, $year, $month);

            $periodFrom = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
            $periodTo   = $periodFrom->modify('last day of this month');

            // Проверяем — не загружали ли уже realization за этот месяц
            $existing = $this->em->getRepository(MarketplaceRawDocument::class)->findOneBy([
                'company'      => $company,
                'marketplace'  => MarketplaceType::OZON,
                'documentType' => self::DOCUMENT_TYPE,
                'periodFrom'   => $periodFrom,
            ]);

            if ($existing !== null) {
                $this->logger->info('Ozon realization already loaded for this period, overwriting', [
                    'company_id'    => $message->companyId,
                    'year'          => $year,
                    'month'         => $month,
                    'existing_id'   => $existing->getId(),
                ]);

                // Обновляем существующий документ — данные могли измениться
                $existing->setRawData($rawData);
                $existing->setRecordsCount(count($rawData['result']['rows'] ?? []));
                $existing->setSyncNotes(null);
                $this->em->flush();

                $this->logger->info('Ozon realization raw document updated', [
                    'raw_doc_id'    => $existing->getId(),
                    'rows_count'    => count($rawData['result']['rows'] ?? []),
                ]);

                return;
            }

            $rows = $rawData['result']['rows'] ?? [];

            $rawDoc = new MarketplaceRawDocument(
                Uuid::uuid4()->toString(),
                $company,
                MarketplaceType::OZON,
                self::DOCUMENT_TYPE,
            );
            $rawDoc->setPeriodFrom($periodFrom);
            $rawDoc->setPeriodTo($periodTo);
            $rawDoc->setApiEndpoint(self::API_ENDPOINT);
            $rawDoc->setRawData($rawData);
            $rawDoc->setRecordsCount(count($rows));

            $this->em->persist($rawDoc);
            $this->em->flush();

            $this->logger->info('Ozon realization raw document saved', [
                'company_id'    => $message->companyId,
                'raw_doc_id'    => $rawDoc->getId(),
                'year'          => $year,
                'month'         => $month,
                'rows_count'    => count($rows),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Ozon realization sync failed', [
                'company_id'    => $message->companyId,
                'connection_id' => $message->connectionId,
                'year'          => $year,
                'month'         => $month,
                'error'         => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
