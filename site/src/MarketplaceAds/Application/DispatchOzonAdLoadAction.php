<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Application\Service\AdBatchPlanner;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Создаёт AdLoadJob и планирует батчи для него в cron-driven pipeline
 * (Task-11.9a).
 *
 * Переход с event-driven Messenger-цепочки на cron: вместо диспатча
 * `LoadOzonAdStatisticsRangeMessage` мы синхронно вызываем
 * {@see AdBatchPlanner::planBatchesForJob()} — он быстр (несколько секунд
 * на 260 кампаний), делает один `GET /api/client/campaign` и N INSERT'ов
 * `AdScheduledBatch` в state=PLANNED. Дальше работу ведут три cron'а
 * (scheduler → poller → finalizer, Task-11.5/6/7), включённые в
 * `docker/cron/app.cron` этим же релизом.
 *
 * Старый `LoadOzonAdStatisticsRangeMessage` и Messenger-handler'ы не
 * удаляются — они обслуживают job'ы, дошедшие до `AdLoadJob(status=pending)`
 * ДО деплоя. Новые загрузки идут через Planner. Удаление — Task-11.9b.
 */
final class DispatchOzonAdLoadAction
{
    /**
     * Ozon Performance API лимит — максимум 62 дня в POST `/statistics`
     * (см. {@see \App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient::assertValidRange()}).
     *
     * Planner не разбивает период на чанки по датам (в отличие от старого
     * `LoadOzonAdStatisticsRangeHandler`), поэтому reject'им период >62 дней
     * сразу с понятной ошибкой. Multi-chunk поддержка — отдельная follow-up
     * задача; пока пользователь обязан разбить вручную.
     */
    private const MAX_DAYS_PER_LOAD = 62;

    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdBatchPlanner $adBatchPlanner,
    ) {
    }

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): AdLoadJob {
        $credentials = $this->marketplaceFacade->getConnectionCredentials(
            $companyId,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );

        if (null === $credentials) {
            throw new \DomainException('Ozon Performance connection not configured');
        }

        $dateFromNorm = $dateFrom->setTime(0, 0);
        $dateToNorm = $dateTo->setTime(0, 0);

        if ($dateFromNorm > $dateToNorm) {
            throw new \DomainException('Дата начала не может быть позже даты окончания.');
        }

        $yesterday = (new \DateTimeImmutable('yesterday'))->setTime(0, 0);
        if ($dateToNorm > $yesterday) {
            throw new \DomainException('Нельзя загружать данные за будущие даты. Дата окончания должна быть не позже вчерашнего дня.');
        }

        $days = (int) $dateFromNorm->diff($dateToNorm)->days + 1;
        if ($days > self::MAX_DAYS_PER_LOAD) {
            throw new \DomainException(sprintf(
                'Период %d дней превышает лимит Ozon Performance API (%d дней). Разбейте период на несколько загрузок.',
                $days,
                self::MAX_DAYS_PER_LOAD,
            ));
        }

        $activeJob = $this->adLoadJobRepository->findLatestActiveJobByCompanyAndMarketplace(
            $companyId,
            MarketplaceType::OZON,
        );

        if (null !== $activeJob) {
            throw new \DomainException('Load already in progress');
        }

        $job = new AdLoadJob($companyId, MarketplaceType::OZON, $dateFrom, $dateTo);
        $this->adLoadJobRepository->save($job);
        // Первый flush: job должен существовать в БД ДО вызова Planner'а —
        // batch'и ссылаются на job_id через FK `fk_asb_job`.
        $this->entityManager->flush();

        try {
            $this->adBatchPlanner->planBatchesForJob(
                $job->getId(),
                $companyId,
                $dateFromNorm,
                $dateToNorm,
            );
        } catch (\Throwable $e) {
            // Ошибка планирования (например, Ozon вернул пустой список кампаний —
            // `RuntimeException('No SKU campaigns found…')`) — фиксируем job как
            // FAILED, чтобы пользователь увидел внятное сообщение в «История загрузок».
            $job->markFailed('Planning error: '.$e->getMessage());
            $this->entityManager->flush();

            throw new \RuntimeException('Failed to plan ad load job: '.$e->getMessage(), 0, $e);
        }

        // Переводим job в RUNNING — Finalizer (Task-11.7) сканирует именно
        // RUNNING-jobs через `AdLoadJobRepository::findAllRunning()`. Без
        // этого перехода job завис бы в PENDING навсегда, даже когда все
        // его batch'и стали терминальными.
        $job->markRunning();
        $this->entityManager->flush();

        return $job;
    }
}
