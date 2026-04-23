<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
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
            // Для каждого job'а подтягиваем raw-документы в его периоде и
            // оставляем только те, у которых заполнен storage_path — т.е. есть
            // файл для скачивания. UI рендерит по одной кнопке «Открыть» на
            // каждую такую дату.
            $documents = $this->adRawDocumentRepository->findByCompanyMarketplaceAndDateRange(
                $companyId,
                MarketplaceType::OZON->value,
                $job->getDateFrom(),
                $job->getDateTo(),
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
                ];
            }

            $items[] = [
                'id' => $job->getId(),
                'status' => $job->getStatus()->value,
                'dateFrom' => $job->getDateFrom()->format('Y-m-d'),
                'dateTo' => $job->getDateTo()->format('Y-m-d'),
                'chunksTotal' => $job->getChunksTotal(),
                'createdAt' => $job->getCreatedAt()->format('d.m.Y H:i'),
                'finishedAt' => $job->getFinishedAt()?->format('d.m.Y H:i'),
                'lastError' => $job->getFailureReason(),
                'files' => $files,
            ];
        }

        return $this->json(['items' => $items]);
    }
}
