<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Repository\AdChunkProgressRepository;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/marketplace-ads/ozon/load-jobs/{jobId}/status', name: 'marketplace_ads_ozon_load_job_status', methods: ['GET'])]
#[IsGranted('ROLE_COMPANY_USER')]
final class OzonAdLoadJobStatusController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly AdChunkProgressRepository $chunkProgressRepository,
        private readonly AdRawDocumentRepository $rawDocumentRepository,
    ) {}

    public function __invoke(string $jobId): JsonResponse
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = $company->getId();

        $job = $this->adLoadJobRepository->findByIdAndCompany($jobId, $companyId);

        if (null === $job) {
            return $this->json(['message' => 'Задание не найдено.'], 404);
        }

        $completedChunks = $this->chunkProgressRepository->countCompletedChunks($jobId, $companyId);

        $marketplace = $job->getMarketplace()->value;
        $dateFrom = $job->getDateFrom();
        $dateTo = $job->getDateTo();

        $totalDocs = $this->rawDocumentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId, $marketplace, $dateFrom, $dateTo,
        );
        $processedDocs = $this->rawDocumentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId, $marketplace, $dateFrom, $dateTo, AdRawDocumentStatus::PROCESSED,
        );
        $failedDocs = $this->rawDocumentRepository->countByCompanyMarketplaceAndDateRange(
            $companyId, $marketplace, $dateFrom, $dateTo, AdRawDocumentStatus::FAILED,
        );

        $chunksTotal = $job->getChunksTotal();
        $progress = $chunksTotal > 0
            ? min(100, (int) floor(($completedChunks / $chunksTotal) * 100))
            : 0;

        return $this->json([
            'jobId' => $job->getId(),
            'status' => $job->getStatus()->value,
            'dateFrom' => $job->getDateFrom()->format('d.m.Y H:i'),
            'dateTo' => $job->getDateTo()->format('d.m.Y H:i'),
            'totalDays' => $job->getTotalDays(),
            'chunksTotal' => $chunksTotal,
            'completedChunks' => $completedChunks,
            'totalDocs' => $totalDocs,
            'processedDocs' => $processedDocs,
            'failedDocs' => $failedDocs,
            'progress' => $progress,
            'lastError' => $job->getFailureReason(),
            'startedAt' => $job->getStartedAt()?->format('d.m.Y H:i'),
            'finishedAt' => $job->getFinishedAt()?->format('d.m.Y H:i'),
        ]);
    }
}
