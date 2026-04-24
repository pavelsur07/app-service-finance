<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\Service;

use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Создаёт план загрузки рекламных отчётов Ozon Performance для одного
 * {@see \App\MarketplaceAds\Entity\AdLoadJob}: разбивает список SKU-кампаний
 * компании на батчи по {@see self::BATCH_SIZE} и сохраняет N записей
 * {@see AdScheduledBatch} в state=PLANNED с равномерно разнесёнными
 * `scheduled_at` (шаг {@see self::SPACING_SECONDS}).
 *
 * Планировщик НЕ делает POST'ов в Ozon — только `GET /api/client/campaign`
 * (через {@see OzonAdClient::listAllSkuCampaigns()}) плюс INSERT'ы в
 * `marketplace_ad_scheduled_batches`. Рабочее время — секунды, независимо
 * от количества кампаний (hot-path — батч-INSERT + один flush).
 *
 * Spacing `scheduled_at` нужен, чтобы scheduler-cron (Task-11.5+) не пытался
 * сделать второй POST `/statistics`, пока Ozon ещё готовит первый — лимит
 * Ozon Performance = 1 активная выгрузка на аккаунт.
 *
 * Идемпотентность: если для переданного `jobId` батчи уже спланированы
 * (например, повторный вызов после падения вызывающего кода ДО commit'а),
 * метод возвращает существующее количество и не создаёт новых записей.
 * Это снимает гонку с UNIQUE-индексом `(job_id, batch_index)`.
 *
 * Dead code на момент Task-11.3: подключается Task-11.5+.
 */
final readonly class AdBatchPlanner
{
    /**
     * Ozon Performance лимит — максимум 10 `campaign_id` в одном POST
     * `/api/client/statistics`. Совпадает с {@see OzonAdClient::STATISTICS_BATCH_SIZE},
     * но намеренно захардкожено тут: planner — верхний слой, не должен зависеть
     * от приватных констант клиента.
     */
    private const BATCH_SIZE = 10;

    /**
     * Шаг между `scheduled_at` соседних батчей.
     *
     * 120 секунд выбрано эмпирически: типичное время генерации отчёта Ozon —
     * 30–90 секунд, 2 минуты дают запас с небольшим буфером и оставляют
     * теоретический максимум 26 батчей × 2 мин = ~52 минуты для 260 кампаний.
     */
    private const SPACING_SECONDS = 120;

    public function __construct(
        private OzonAdClient $ozonClient,
        private AdScheduledBatchRepository $batchRepository,
        private EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private LoggerInterface $marketplaceAdsLogger,
    ) {
    }

    /**
     * Планирует батчи для job'а. Идемпотентен.
     *
     * @return int количество запланированных батчей (ново созданных либо уже существующих)
     *
     * @throws \RuntimeException если у компании нет SKU-кампаний (Ozon отдал пустой список)
     */
    public function planBatchesForJob(
        string $jobId,
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): int {
        $existing = $this->batchRepository->findByJobId($jobId, $companyId);
        if ([] !== $existing) {
            $this->marketplaceAdsLogger->info('AdBatchPlanner: batches already planned, skip', [
                'companyId' => $companyId,
                'jobId' => $jobId,
                'existingCount' => count($existing),
            ]);

            return count($existing);
        }

        $campaigns = $this->ozonClient->listAllSkuCampaigns($companyId);

        if ([] === $campaigns) {
            throw new \RuntimeException(sprintf(
                'No SKU campaigns found for company %s',
                $companyId,
            ));
        }

        /** @var list<string> $campaignIds */
        $campaignIds = array_map(
            static fn (array $c): string => (string) $c['id'],
            $campaigns,
        );

        $chunks = array_chunk($campaignIds, self::BATCH_SIZE);
        // UTC: Postgres NOW() возвращает UTC, локальный PHP TZ дал бы лаг
        // при сравнении scheduled_at < NOW() в scheduler-cron. См. ARCHITECTURE v1.28.
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $created = 0;

        foreach ($chunks as $batchIndex => $chunk) {
            $scheduledAt = $now->modify(sprintf('+%d seconds', $batchIndex * self::SPACING_SECONDS));

            $batch = new AdScheduledBatch(
                id: Uuid::uuid7()->toString(),
                jobId: $jobId,
                companyId: $companyId,
                campaignIds: array_values($chunk),
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                batchIndex: $batchIndex,
                scheduledAt: $scheduledAt,
            );

            $this->batchRepository->save($batch);
            ++$created;
        }

        $this->em->flush();

        $this->marketplaceAdsLogger->info('AdBatchPlanner: batches planned', [
            'companyId' => $companyId,
            'jobId' => $jobId,
            'campaignsTotal' => count($campaignIds),
            'batchesCreated' => $created,
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
            'spacingSeconds' => self::SPACING_SECONDS,
        ]);

        return $created;
    }
}
