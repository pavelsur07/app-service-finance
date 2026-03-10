<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Загружает одну недельную партию первичной синхронизации.
 * После успеха диспатчит следующую партию (цепочка).
 * Последняя партия: nextDateFrom === null → цепочка завершена.
 */
#[AsMessageHandler]
final class InitialSyncHandler
{
    private const LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(InitialSyncMessage $message): void
    {
        $company = $this->em->find(Company::class, $message->companyId);
        if (!$company) {
            $this->logger->error('InitialSync: company not found', [
                'company_id' => $message->companyId,
            ]);

            return;
        }

        $connection = $this->em->find(MarketplaceConnection::class, $message->connectionId);
        if (!$connection || !$connection->isActive()) {
            $this->logger->warning('InitialSync: connection not found or inactive', [
                'connection_id' => $message->connectionId,
            ]);

            return;
        }

        $marketplace = MarketplaceType::from($message->marketplace);
        $fromDate    = new \DateTimeImmutable($message->dateFrom);
        $toDate      = new \DateTimeImmutable($message->dateTo . ' 23:59:59');

        try {
            $adapter = $this->adapterRegistry->get($marketplace);
            $rawData = $adapter->fetchRawReport($company, $fromDate, $toDate);

            if (!empty($rawData)) {
                $rawDoc = new MarketplaceRawDocument(
                    Uuid::uuid4()->toString(),
                    $company,
                    $marketplace,
                    'sales_report',
                );
                $rawDoc->setPeriodFrom($fromDate);
                $rawDoc->setPeriodTo($toDate);
                $rawDoc->setApiEndpoint($adapter->getApiEndpointName());
                $rawDoc->setRawData($rawData);
                $rawDoc->setRecordsCount(count($rawData));

                $this->em->persist($rawDoc);
                $this->em->flush();

                $this->logger->info('InitialSync: batch saved', [
                    'company_id'    => $message->companyId,
                    'marketplace'   => $message->marketplace,
                    'date_from'     => $message->dateFrom,
                    'date_to'       => $message->dateTo,
                    'records_count' => count($rawData),
                ]);
            } else {
                $this->logger->info('InitialSync: empty batch, skipping', [
                    'company_id'  => $message->companyId,
                    'marketplace' => $message->marketplace,
                    'date_from'   => $message->dateFrom,
                    'date_to'     => $message->dateTo,
                ]);
            }

            // Диспатчим следующую партию если она есть
            if ($message->nextDateFrom !== null && $message->nextDateTo !== null) {
                // Вычисляем партию после следующей чтобы передать её в nextDate
                $nextFrom   = new \DateTimeImmutable($message->nextDateFrom);
                $afterFrom  = $nextFrom->modify('+7 days');
                $afterTo    = $afterFrom->modify('+6 days');
                $today      = new \DateTimeImmutable('today');

                $hasAfter       = $afterFrom <= $today;
                $afterFromStr   = $hasAfter ? $afterFrom->format('Y-m-d') : null;
                $afterToStr     = $hasAfter
                    ? ($afterTo > $today ? $today->format('Y-m-d') : $afterTo->format('Y-m-d'))
                    : null;

                $this->messageBus->dispatch(new InitialSyncMessage(
                    companyId:    $message->companyId,
                    connectionId: $message->connectionId,
                    marketplace:  $message->marketplace,
                    dateFrom:     $message->nextDateFrom,
                    dateTo:       $message->nextDateTo,
                    nextDateFrom: $afterFromStr,
                    nextDateTo:   $afterToStr,
                ));

                $this->logger->info('InitialSync: dispatched next batch', [
                    'company_id'  => $message->companyId,
                    'marketplace' => $message->marketplace,
                    'date_from'   => $message->nextDateFrom,
                    'date_to'     => $message->nextDateTo,
                ]);
            } else {
                $this->logger->info('InitialSync: all batches completed', [
                    'company_id'  => $message->companyId,
                    'marketplace' => $message->marketplace,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('InitialSync: batch failed', [
                'company_id'  => $message->companyId,
                'marketplace' => $message->marketplace,
                'date_from'   => $message->dateFrom,
                'date_to'     => $message->dateTo,
                'error'       => $e->getMessage(),
            ]);

            // Пробрасываем — Messenger сделает retry согласно конфигурации
            throw $e;
        }
    }
}
