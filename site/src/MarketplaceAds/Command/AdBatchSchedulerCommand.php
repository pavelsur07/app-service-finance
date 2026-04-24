<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Exception\OzonRateLimitException;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Scheduler cron для cron-driven Ozon Performance pipeline (Task-11.5).
 *
 * Берёт один PLANNED-батч из `marketplace_ad_scheduled_batches` через
 * {@see AdScheduledBatchRepository::findNextPlanned()} (FOR UPDATE SKIP LOCKED),
 * делает POST `/api/client/statistics` в Ozon, переводит батч в IN_FLIGHT
 * с заполненным `ozon_uuid` и выходит. Одна итерация = один батч.
 *
 * При минутном cron-интервале это естественный rate-limiter под лимит Ozon
 * «1 активная выгрузка на аккаунт»: scheduled_at у планировщика разнесён
 * на 120с, поэтому cron успевает подхватить не более одного due-батча за тик.
 *
 * Обработка ошибок:
 *  - {@see OzonRateLimitException} (HTTP 429) — scheduled_at сдвигается на
 *    +5 минут, state остаётся PLANNED, retry_count++. Commit'им запись,
 *    возвращаем SUCCESS — Ozon ещё держит слот, следующий тик попробует
 *    либо этот батч (если до `scheduled_at` прошло 5 мин), либо другой PLANNED.
 *  - {@see OzonPermanentApiException} (403, отсутствующие credentials) —
 *    батч помечается FAILED с `finishedAt = now()` и последней ошибкой;
 *    другие batch'и job'а продолжают обрабатываться (Finalizer решит судьбу
 *    job'а по агрегату). Commit.
 *  - Любая другая ошибка (5xx, сеть, JSON) — rollback, батч остаётся PLANNED
 *    (state и scheduled_at на диске не изменились), cron попробует снова.
 *    Возвращаем FAILURE, чтобы cron-обвязка / алерты видели факт.
 *
 * Не подключён в cron в рамках Task-11.5 — ждёт Poller (Task-11.6) и
 * Finalizer (Task-11.7), включится одним релизом.
 *
 * Trade-off — HTTP внутри транзакции:
 * POST `/api/client/statistics` выполняется, пока открыт `FOR UPDATE`-lock
 * на взятом PLANNED-батче. Это сознательно: так инвариант «IN_FLIGHT → есть
 * ozon_uuid» гарантируется на уровне БД (Poller Task-11.6 смотрит
 * исключительно на этот инвариант и не должен видеть IN_FLIGHT без uuid).
 * Альтернатива two-phase (IN_FLIGHT без uuid → HTTP → uuid) потребовала бы
 * промежуточного DISPATCHING-состояния и recovery-крона для stuck-батчей —
 * сложность, не оправданная текущим масштабом (один worker, минутный cron,
 * HTTP-таймаут 30с, типичная длительность 1–3с). При росте параллелизма
 * или HTTP-таймаута стоит пересмотреть.
 */
#[AsCommand(
    name: 'app:marketplace-ads:scheduler',
    description: 'Processes one PLANNED ad batch: POST /statistics → IN_FLIGHT',
)]
final class AdBatchSchedulerCommand extends Command
{
    /**
     * При 429 от Ozon перепланируем батч на +5 минут.
     *
     * Эмпирическое значение: типичная готовность отчёта Ozon — 2–5 минут,
     * этого времени хватает backend'у Ozon, чтобы освободить слот активной
     * выгрузки. Меньше 2 минут — риск повторного 429; больше 10 минут —
     * задержка planner-SPACING_SECONDS начинает доминировать над общим
     * временем завершения job'а.
     */
    private const RATE_LIMIT_RESCHEDULE_MINUTES = 5;

    public function __construct(
        private readonly OzonAdClient $ozonClient,
        private readonly AdScheduledBatchRepository $batchRepo,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // findNextPlanned использует FOR UPDATE SKIP LOCKED — обязательно
        // открываем транзакцию, чтобы row-lock держался до commit/rollback
        // и параллельные cron-worker'ы видели блокировку.
        $this->connection->beginTransaction();

        try {
            $batch = $this->batchRepo->findNextPlanned();

            if (null === $batch) {
                $this->connection->commit();
                $output->writeln('<info>No PLANNED batches ready for processing.</info>');

                return self::SUCCESS;
            }

            $this->logger->info('Scheduler: picked PLANNED batch', [
                'batchId' => $batch->getId(),
                'jobId' => $batch->getJobId(),
                'companyId' => $batch->getCompanyId(),
                'batchIndex' => $batch->getBatchIndex(),
                'campaignCount' => count($batch->getCampaignIds()),
            ]);

            try {
                $uuid = $this->ozonClient->postStatistics(
                    $batch->getCompanyId(),
                    $batch->getCampaignIds(),
                    $batch->getDateFrom(),
                    $batch->getDateTo(),
                );

                // UTC — см. ARCHITECTURE.md v1.28 (Postgres NOW() в UTC,
                // без нормализации появлялся 3h лаг scheduled_at).
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $batch->setState(AdScheduledBatchState::IN_FLIGHT);
                $batch->setOzonUuid($uuid);
                $batch->setStartedAt($now);

                $this->logger->info('Scheduler: batch dispatched to Ozon', [
                    'batchId' => $batch->getId(),
                    'ozonUuid' => $uuid,
                ]);
            } catch (OzonRateLimitException $e) {
                // Ozon держит активную выгрузку — batch остаётся PLANNED,
                // scheduled_at сдвигается, retry_count растёт.
                $newScheduledAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(
                    sprintf('+%d minutes', self::RATE_LIMIT_RESCHEDULE_MINUTES),
                );
                $batch->setScheduledAt($newScheduledAt);
                $batch->setRetryCount($batch->getRetryCount() + 1);
                $batch->setLastError('Ozon 429: '.$e->getMessage());

                $this->logger->warning('Scheduler: 429 from Ozon, rescheduling', [
                    'batchId' => $batch->getId(),
                    'newScheduledAt' => $newScheduledAt->format('Y-m-d H:i:s'),
                    'retryCount' => $batch->getRetryCount(),
                ]);

                $output->writeln(sprintf(
                    '<comment>Batch %s rescheduled to %s (429 from Ozon)</comment>',
                    $batch->getId(),
                    $newScheduledAt->format('Y-m-d H:i:s'),
                ));
            } catch (OzonPermanentApiException $e) {
                // 403 / нет credentials — batch FAILED. Другие batch'и job'а
                // продолжат обрабатываться; Finalizer (Task-11.7) примет решение
                // по агрегату.
                $batch->setState(AdScheduledBatchState::FAILED);
                $batch->setLastError('Ozon permanent: '.$e->getMessage());
                $batch->setFinishedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

                $this->logger->error('Scheduler: permanent Ozon failure, marking FAILED', [
                    'batchId' => $batch->getId(),
                    'error' => $e->getMessage(),
                ]);

                $output->writeln(sprintf(
                    '<error>Batch %s FAILED (permanent): %s</error>',
                    $batch->getId(),
                    $e->getMessage(),
                ));
            }

            // Happy-path и обе catch-ветки (429 / permanent) — валидные
            // терминальные состояния итерации. Персистим изменения и коммитим.
            $this->batchRepo->save($batch);
            $this->em->flush();
            $this->connection->commit();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Transient (5xx, сеть, JSON, непредвиденный Throwable) — откатываем
            // транзакцию, batch остаётся PLANNED, cron попробует на следующем тике.
            $this->connection->rollBack();

            $this->logger->error('Scheduler: transient failure, batch stays PLANNED', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            $output->writeln(sprintf(
                '<error>Transient failure: %s (%s). Batch unchanged.</error>',
                $e->getMessage(),
                $e::class,
            ));

            return self::FAILURE;
        }
    }
}
