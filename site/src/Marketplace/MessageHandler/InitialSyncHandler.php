<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
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
    private const RATE_LIMIT_FALLBACK_DELAY_SECONDS = 90;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly LockFactory $lockFactory,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly MarketplaceWeekPartitionService $partitionService,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(InitialSyncMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            sprintf('marketplace_initial_sync:%s:%s:%s', $message->companyId, $message->connectionId, $message->marketplace),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            $this->logger->warning('InitialSync: lock not acquired, skipping batch', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'marketplace' => $message->marketplace,
                'date_from' => $message->dateFrom,
                'date_to' => $message->dateTo,
            ]);

            // В текущей модели initial sync повторная обработка одного и того же batch
            // не защищена от дублей MarketplaceRawDocument (нет уникального ключа/guard'а
            // по company+marketplace+document_type+period). Поэтому lock-miss завершаем
            // как "skip + ack", а не retry: это осознанная защита от потенциального
            // повторного сохранения raw-документов до внедрения idempotency guard.
            return;
        }

        try {
            $this->process($message);
        } finally {
            $lock->release();
        }
    }

    private function process(InitialSyncMessage $message): void
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
        $toDate      = new \DateTimeImmutable($message->dateTo);

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
                // Граница первичной синхронизации — вчера: за сегодня данные
                // ещё неполные, их загрузит ежедневный cron завтра.
                $yesterday = $this->clock->now()->modify('-1 day')->setTime(0, 0, 0);

                // Используем nextDateTo как есть — он уже корректно рассчитан buildPartitions
                // (учитывает границы месяца и недели), поэтому пересчёт через
                // modify('sunday this week') ломает split-партиции.
                $nextTo = new \DateTimeImmutable($message->nextDateTo);
                if ($nextTo > $yesterday) {
                    $nextTo = $yesterday;
                }

                // Оставшиеся партиции считаем от конца следующей партии
                $afterStart      = $nextTo->modify('+1 day')->setTime(0, 0, 0);
                $hasAfter        = $afterStart <= $yesterday;
                $afterPartitions = $hasAfter
                    ? $this->partitionService->buildPartitions($afterStart, $yesterday)
                    : [];

                $afterFromStr = !empty($afterPartitions) ? $afterPartitions[0]['from'] : null;
                $afterToStr   = !empty($afterPartitions) ? $afterPartitions[0]['to']   : null;

                // Используем клампнутый $nextTo — если ограничение по today сработало,
                // следующая задача не должна тянуть данные за будущий период.
                $nextToStr = $nextTo->format('Y-m-d H:i:s');

                $this->messageBus->dispatch(new InitialSyncMessage(
                    companyId:    $message->companyId,
                    connectionId: $message->connectionId,
                    marketplace:  $message->marketplace,
                    dateFrom:     $message->nextDateFrom,
                    dateTo:       $nextToStr,
                    nextDateFrom: $afterFromStr,
                    nextDateTo:   $afterToStr,
                ));

                $this->logger->info('InitialSync: dispatched next batch', [
                    'company_id'  => $message->companyId,
                    'marketplace' => $message->marketplace,
                    'date_from'   => $message->nextDateFrom,
                    'date_to'     => $nextToStr,
                ]);
            } else {
                // Последняя партия — обновляем статус подключения
                $connection = $this->em->find(MarketplaceConnection::class, $message->connectionId);
                if ($connection) {
                    $connection->markSyncSuccess();
                    $this->em->flush();
                }

                $this->logger->info('InitialSync: all batches completed', [
                    'company_id'  => $message->companyId,
                    'marketplace' => $message->marketplace,
                ]);
            }
        } catch (MarketplaceRateLimitException $e) {
            $retryAfterSeconds = $e->getRetryAfter() ?? self::RATE_LIMIT_FALLBACK_DELAY_SECONDS;
            $retryAfterMs = max(1, $retryAfterSeconds) * 1000;

            $this->logger->warning('InitialSync: rate limit, batch retry scheduled', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'marketplace' => $message->marketplace,
                'date_from' => $message->dateFrom,
                'date_to' => $message->dateTo,
                'retry_after' => $e->getRetryAfter(),
                'retry_after_fallback' => $e->getRetryAfter() === null,
                'retry_delay_ms' => $retryAfterMs,
                'error' => $e->getMessage(),
            ]);

            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e, $retryAfterMs);
        } catch (MarketplaceAuthException $e) {
            $this->logger->error('InitialSync: auth error, stopping sync chain', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'marketplace' => $message->marketplace,
                'date_from' => $message->dateFrom,
                'date_to' => $message->dateTo,
                'error' => $e->getMessage(),
            ]);

            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();
        } catch (MarketplaceTemporaryApiException $e) {
            $this->logger->warning('InitialSync: temporary API error, retry scheduled', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'marketplace' => $message->marketplace,
                'date_from' => $message->dateFrom,
                'date_to' => $message->dateTo,
                'error' => $e->getMessage(),
            ]);

            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
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
