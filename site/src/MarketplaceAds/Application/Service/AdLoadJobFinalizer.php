<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\Service;

use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use App\MarketplaceAds\Repository\AdRawDocumentRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Идемпотентная финализация AdLoadJob.
 *
 * Вызывается из нескольких точек:
 *  - ProcessAdRawDocumentHandler после перевода документа в терминальный статус;
 *  - FetchOzonAdStatisticsHandler после markChunkCompleted — это покрывает случай,
 *    когда Ozon вернул 0 документов за чанк и per-document handler никогда не
 *    запускался (иначе job навечно завис бы в RUNNING).
 *
 * Решение принимается по COUNT(*) в БД, а не по локальным счётчикам — это
 * устойчиво к параллельным воркерам. markCompleted/markFailed имеют SQL-guard
 * `status IN (pending, running)` и обновят строку только один раз, поэтому
 * одновременный вызов из разных воркеров безопасен.
 */
final readonly class AdLoadJobFinalizer
{
    public function __construct(
        private AdLoadJobRepositoryInterface $jobRepository,
        private AdRawDocumentRepositoryInterface $documentRepository,
        private AdChunkProgressRepositoryInterface $chunkProgressRepository,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private LoggerInterface $marketplaceAdsLogger,
    ) {
    }

    public function tryFinalize(string $jobId, string $companyId): void
    {
        $job = $this->jobRepository->findByIdAndCompany($jobId, $companyId);
        if (null === $job) {
            return;
        }

        if (AdLoadJobStatus::RUNNING !== $job->getStatus()) {
            return;
        }

        $completedChunks = $this->chunkProgressRepository->countCompletedChunks($jobId, $companyId);
        if ($completedChunks < $job->getChunksTotal()) {
            return;
        }

        $marketplaceValue = $job->getMarketplace()->value;
        $dateFrom = $job->getDateFrom();
        $dateTo = $job->getDateTo();

        $totalDocs = $this->documentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId,
            $marketplaceValue,
            $dateFrom,
            $dateTo,
        );
        $processedDocs = $this->documentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId,
            $marketplaceValue,
            $dateFrom,
            $dateTo,
            AdRawDocumentStatus::PROCESSED,
        );
        $failedDocs = $this->documentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId,
            $marketplaceValue,
            $dateFrom,
            $dateTo,
            AdRawDocumentStatus::FAILED,
        );

        // Остались DRAFT-документы — финализацию делать рано: их добьёт
        // следующий вызов после обработки оставшихся документов.
        if ($processedDocs + $failedDocs < $totalDocs) {
            return;
        }

        if (0 === $failedDocs) {
            $affected = $this->jobRepository->markCompleted($jobId, $companyId);
            if ($affected > 0) {
                $this->marketplaceAdsLogger->info('AdLoadJob completed', [
                    'jobId' => $jobId,
                    'companyId' => $companyId,
                    'totalDocs' => $totalDocs,
                ]);
            }

            return;
        }

        $reason = sprintf('Partial failure: %d of %d documents failed', $failedDocs, $totalDocs);
        $affected = $this->jobRepository->markFailed($jobId, $companyId, $reason);
        if ($affected > 0) {
            $this->marketplaceAdsLogger->warning('AdLoadJob finalized with failures', [
                'jobId' => $jobId,
                'companyId' => $companyId,
                'totalDocs' => $totalDocs,
                'failedDocs' => $failedDocs,
                'processedDocs' => $processedDocs,
            ]);
        }
    }
}
