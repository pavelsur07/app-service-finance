<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Message\LoadOzonAdStatisticsRangeMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Оркестратор пакетной загрузки рекламной статистики Ozon Performance.
 *
 * На входе — только jobId. Handler:
 *  1) читает AdLoadJob (find() без company-guard: Message пришёл из нашего же
 *     Action'а, ID сгенерирован внутри системы — это trusted-контекст);
 *  2) если PENDING → markRunning + flush; если уже терминальный — no-op;
 *     если RUNNING — продолжаем (это retry Messenger'а после сбоя на этапе
 *     рассылки, idempotent dispatch покроет повторный заход);
 *  3) бьёт диапазон dateFrom..dateTo на чанки ≤ 62 дня (лимит Ozon API,
 *     см. {@see \App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient::fetchAdStatisticsRange});
 *  4) при первом проходе (chunksTotal == 0) устанавливает chunksTotal + flush;
 *     на retry — skip (chunksTotal уже выставлен);
 *  5) диспатчит N × FetchOzonAdStatisticsMessage по одному на чанк.
 *
 * Финализация job'а (markCompleted) — ответственность ProcessAdRawDocumentHandler
 * (Коммит 5): как только chunksCompleted == chunksTotal И все документы
 * обработаны, он проверяет failed_days и помечает job completed / failed.
 */
#[AsMessageHandler]
final class LoadOzonAdStatisticsRangeHandler
{
    // Лимит Ozon Performance API: диапазон запроса статистики не превышает
    // 62 дня включительно. См. OzonAdClient::MAX_DAYS_PER_REQUEST.
    private const CHUNK_MAX_DAYS = 62;

    public function __construct(
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly AppLogger $logger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(LoadOzonAdStatisticsRangeMessage $message): void
    {
        $job = $this->adLoadJobRepository->find($message->jobId);

        if (null === $job) {
            // Запись удалена / не существует — не ретраим (повторная попытка ничего не исправит).
            $this->logger->warning('AdLoadJob not found for LoadOzonAdStatisticsRangeMessage', [
                'jobId' => $message->jobId,
            ]);

            return;
        }

        if ($job->getStatus()->isTerminal()) {
            $this->logger->info('AdLoadJob already finished, range dispatch skipped', [
                'jobId' => $job->getId(),
                'companyId' => $job->getCompanyId(),
                'status' => $job->getStatus()->value,
            ]);

            return;
        }

        // markRunning только из PENDING; RUNNING — идемпотентный retry после сбоя
        // рассылки (например, падение воркера между dispatch'ами).
        $needsFlush = false;
        if (AdLoadJobStatus::PENDING === $job->getStatus()) {
            $job->markRunning();
            $needsFlush = true;
        }

        $chunks = $this->splitIntoChunks($job->getDateFrom(), $job->getDateTo());

        // chunksTotal выставляется один раз на задание. На retry после сбоя
        // сразу после dispatch'а части чанков — value уже положительный,
        // повторный set был бы бессмысленным (и пустым) UPDATE.
        if (0 === $job->getChunksTotal()) {
            $job->setChunksTotal(count($chunks));
            $needsFlush = true;
        }

        // Один flush на markRunning + setChunksTotal — меньше round-trip'ов.
        if ($needsFlush) {
            $this->entityManager->flush();
        }

        // TODO(commit 5): защита от дубль-dispatch при retry оркестратора.
        // AdRawDocument.UniqueConstraint(company_id, marketplace, report_date)
        // уже защищает документы и loaded_days (created=0 на retry → инкремент=0).
        // chunks_completed защитим в FetchOzonAdStatisticsHandler детекцией
        // повтора: created=0 && updated>0 → не инкрементировать (это retry-fetch).
        foreach ($chunks as $chunk) {
            $this->messageBus->dispatch(new FetchOzonAdStatisticsMessage(
                jobId: $job->getId(),
                companyId: $job->getCompanyId(),
                dateFrom: $chunk['from']->format('Y-m-d'),
                dateTo: $chunk['to']->format('Y-m-d'),
            ));
        }

        $this->logger->info('Ozon ad statistics range dispatched', [
            'jobId' => $job->getId(),
            'companyId' => $job->getCompanyId(),
            'dateFrom' => $job->getDateFrom()->format('Y-m-d'),
            'dateTo' => $job->getDateTo()->format('Y-m-d'),
            'totalDays' => $job->getTotalDays(),
            'chunksCount' => count($chunks),
        ]);

        // TODO(commit 5): ProcessAdRawDocumentHandler finalizes job by chunksCompleted.
    }

    /**
     * Разбивает диапазон на чанки не более CHUNK_MAX_DAYS дней включительно.
     *
     * 62 — МАКСИМУМ ВКЛЮЧИТЕЛЬНО, поэтому шаг курсора = +61 день:
     *   [cursor, cursor+61] = ровно 62 дня с учётом обеих границ.
     *
     * @return list<array{from: \DateTimeImmutable, to: \DateTimeImmutable}>
     */
    private function splitIntoChunks(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $chunks = [];
        $cursor = $dateFrom;
        $stepDays = self::CHUNK_MAX_DAYS - 1;

        while ($cursor <= $dateTo) {
            $chunkEnd = $cursor->modify(sprintf('+%d days', $stepDays));
            if ($chunkEnd > $dateTo) {
                $chunkEnd = $dateTo;
            }

            $chunks[] = [
                'from' => $cursor,
                'to' => $chunkEnd,
            ];

            $cursor = $chunkEnd->modify('+1 day');
        }

        return $chunks;
    }
}
