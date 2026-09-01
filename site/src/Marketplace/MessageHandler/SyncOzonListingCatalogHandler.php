<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\RefreshOzonListingCatalogAction;
use App\Marketplace\Entity\MarketplaceJobLog;
use App\Marketplace\Enum\JobType;
use App\Marketplace\Exception\OzonCatalogRateLimitException;
use App\Marketplace\Infrastructure\Query\MarketplaceJobLogFailQuery;
use App\Marketplace\Message\SyncOzonListingCatalogMessage;
use App\Marketplace\Repository\MarketplaceJobLogRepository;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncOzonListingCatalogHandler
{
    /**
     * TTL держим коротким и продлеваем по ходу обхода: длинный TTL запирал бы
     * подключение после аварийного завершения воркера, а без продления lease
     * протух бы посреди живого прогона крупного каталога.
     */
    private const LOCK_TTL_SECONDS = 300;

    public function __construct(
        private RefreshOzonListingCatalogAction $refreshOzonListingCatalogAction,
        private MarketplaceJobLogRepository $jobLogRepository,
        private MarketplaceJobLogFailQuery $jobLogFailQuery,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncOzonListingCatalogMessage $message): void
    {
        // Триггеров два — ночной cron и кнопка в UI, — поэтому одновременный
        // обход одного подключения возможен. Второй прогон отступает: без
        // блокировки он удвоил бы запросы к Ozon и перезаписал бы снимок.
        // Ключ по (company, connection), а не по компании: разные подключения
        // независимы.
        $lock = $this->lockFactory->createLock(
            sprintf('marketplace_ozon_listing_catalog_%s_%s', $message->companyId, $message->connectionId),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            $this->logger->info('[OzonListingCatalog] Another run is in progress, skipping.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
            ]);

            return;
        }

        // try начинается СРАЗУ после acquire(): сбой при заведении журнала
        // иначе оставил бы блокировку до истечения TTL, и ближайший retry
        // отступил бы по занятой блокировке — сообщение было бы подтверждено
        // без единой синхронизации.
        try {
            $jobLog = new MarketplaceJobLog(
                Uuid::uuid4()->toString(),
                $message->companyId,
                JobType::LISTING_CATALOG_SYNC_OZON,
            );
            $this->jobLogRepository->save($jobLog);

            $this->logger->info('[OzonListingCatalog] Handler started.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
            ]);

            $result = ($this->refreshOzonListingCatalogAction)(
                $message->companyId,
                $message->connectionId,
                static function () use ($lock): void {
                    $lock->refresh();
                },
            );

            // Терминальный статус пишем ДО освобождения блокировки: сбой
            // release() иначе оставил бы успешный прогон висеть в RUNNING.
            $jobLog->complete([
                'products_fetched' => $result->productsFetched,
                'listings_upserted' => $result->listingsUpserted,
                'raw_records_stored' => $result->rawRecordsStored,
            ]);
            $this->jobLogRepository->save($jobLog);

            $this->logger->info('[OzonListingCatalog] Handler finished.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'products_fetched' => $result->productsFetched,
                'listings_upserted' => $result->listingsUpserted,
                'raw_records_stored' => $result->rawRecordsStored,
            ]);
        } catch (\Throwable $exception) {
            if ($exception instanceof OzonCatalogRateLimitException) {
                // 429 обрабатывается сам ретраем — это не инцидент и будить
                // человека незачем, поэтому warning, а не error.
                $this->logger->warning('[OzonListingCatalog] Rate limited, leaving retry to the transport.', [
                    'company_id' => $message->companyId,
                    'connection_id' => $message->connectionId,
                ]);
            }

            // Через DBAL, а не ORM: сбой внутри чанковой транзакции закрывает
            // EntityManager, и persist() подменил бы исходное исключение
            // техническим, оставив запись в RUNNING.
            //
            // Класс, а не текст: сообщение внешнего API может нести данные
            // продавца. Формат один для всех ошибок, включая 429.
            //
            // Сама запись — best-effort: сбой журналирования не имеет права
            // подменить исходное исключение, иначе меняется retry-семантика и
            // теряется причина. Это ровно та подмена, ради устранения которой
            // и заведён отдельный DBAL-writer.
            if (isset($jobLog)) {
                try {
                    $this->jobLogFailQuery->markFailed($jobLog->getId(), $message->companyId, $exception::class);
                } catch (\Throwable $jobLogFailure) {
                    $this->logger->error('[OzonListingCatalog] Failed to record the failed run in the job log.', [
                        'company_id' => $message->companyId,
                        'connection_id' => $message->connectionId,
                        'exception_class' => $jobLogFailure::class,
                    ]);
                }
            }

            // Исходное исключение пробрасывается как есть. Оборачивать 429 в
            // RecoverableMessageHandlingException нельзя: Symfony считает
            // RecoverableExceptionInterface retryable безусловно, в обход
            // max_retries, и постоянный 429 крутился бы вечно, не доходя до
            // failed-очереди. Обычное исключение оставляет в силе
            // retry_strategy транспорта async_sync.
            throw $exception;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $releaseFailure) {
                // Ключ всё равно истечёт по TTL. Ронять из-за этого уже
                // выполненный прогон нельзя — сообщение ушло бы в retry и
                // повторило бы десятки внешних запросов.
                $this->logger->warning('[OzonListingCatalog] Failed to release the run lock.', [
                    'company_id' => $message->companyId,
                    'connection_id' => $message->connectionId,
                    'exception_class' => $releaseFailure::class,
                ]);
            }
        }
    }
}
