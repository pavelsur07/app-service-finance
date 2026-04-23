<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/marketplace-ads/load-jobs', name: 'marketplace_ads_load_jobs_list', methods: ['GET'])]
#[IsGranted('ROLE_COMPANY_USER')]
final class AdLoadJobsListController extends AbstractController
{
    private const LIMIT = 20;

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly AdRawDocumentRepository $adRawDocumentRepository,
        private readonly AdScheduledBatchRepository $adScheduledBatchRepository,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $jobs = $this->adLoadJobRepository->findRecentByCompanyAndMarketplace(
            $companyId,
            MarketplaceType::OZON,
            self::LIMIT,
        );

        $items = [];
        foreach ($jobs as $job) {
            // Batch-агрегат для нового cron-driven pipeline (Task-11.3+).
            // Для jobs старого Messenger-pipeline'а countStatesForJob вернёт [] —
            // UI отрисует старый «Чанки: N» путь через hasBatches=false.
            $stats = $this->adScheduledBatchRepository->countStatesForJob($job->getId(), $companyId);
            $ok = $stats['OK'] ?? 0;
            $failedLike = ($stats['FAILED'] ?? 0) + ($stats['ABANDONED'] ?? 0);
            $pending = ($stats['PLANNED'] ?? 0) + ($stats['IN_FLIGHT'] ?? 0);
            $totalBatches = $ok + $failedLike + $pending;
            $hasBatches = $totalBatches > 0;

            // Источник файлов для скачивания зависит от pipeline'а:
            //  - старый Messenger: AdRawDocument.storage_path (одна запись на день);
            //  - новый cron: AdScheduledBatch.storage_path (одна запись на batch).
            // Держим оба пути — старые job'ы остаются функциональными.
            $files = $hasBatches
                ? $this->collectBatchFiles($job->getId(), $companyId)
                : $this->collectRawDocumentFiles($companyId, $job->getDateFrom(), $job->getDateTo());

            $items[] = [
                'id' => $job->getId(),
                'status' => $job->getStatus()->value,
                'dateFrom' => $job->getDateFrom()->format('Y-m-d'),
                'dateTo' => $job->getDateTo()->format('Y-m-d'),
                'chunksTotal' => $job->getChunksTotal(),
                'createdAt' => $job->getCreatedAt()->format('d.m.Y H:i'),
                'finishedAt' => $job->getFinishedAt()?->format('d.m.Y H:i'),
                'lastError' => $job->getFailureReason(),
                'batchStats' => [
                    'total' => $totalBatches,
                    'ok' => $ok,
                    'failed' => $failedLike,
                    'pending' => $pending,
                    'hasBatches' => $hasBatches,
                ],
                'files' => $files,
            ];
        }

        return $this->json(['items' => $items]);
    }

    /**
     * @return list<array{id: string, reportDate: string, kind: 'raw'}>
     */
    private function collectRawDocumentFiles(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): array {
        $documents = $this->adRawDocumentRepository->findByCompanyMarketplaceAndDateRange(
            $companyId,
            MarketplaceType::OZON->value,
            $dateFrom,
            $dateTo,
        );

        $files = [];
        foreach ($documents as $doc) {
            if (!$doc instanceof AdRawDocument) {
                continue;
            }
            if (null === $doc->getStoragePath()) {
                continue;
            }
            $files[] = [
                'id' => $doc->getId(),
                'reportDate' => $doc->getReportDate()->format('Y-m-d'),
                'kind' => 'raw',
            ];
        }

        return $files;
    }

    /**
     * @return list<array{id: string, batchIndex: int, dateFrom: string, dateTo: string, campaignCount: int, fileSize: ?int, kind: 'batch'}>
     */
    private function collectBatchFiles(string $jobId, string $companyId): array
    {
        $downloadable = $this->adScheduledBatchRepository->findDownloadableByJobId($jobId, $companyId);

        $files = [];
        foreach ($downloadable as $batch) {
            $files[] = [
                'id' => $batch->getId(),
                'batchIndex' => $batch->getBatchIndex(),
                'dateFrom' => $batch->getDateFrom()->format('Y-m-d'),
                'dateTo' => $batch->getDateTo()->format('Y-m-d'),
                'campaignCount' => count($batch->getCampaignIds()),
                'fileSize' => $batch->getFileSize(),
                'kind' => 'batch',
            ];
        }

        return $files;
    }
}
