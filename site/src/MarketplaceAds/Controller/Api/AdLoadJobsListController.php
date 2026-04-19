<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\MarketplaceAds\Repository\AdLoadJobRepository;
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
    ) {}

    public function __invoke(): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $jobs = $this->adLoadJobRepository->findRecentByCompany($companyId, self::LIMIT);

        $items = array_map(
            static fn ($job): array => [
                'id' => $job->getId(),
                'status' => $job->getStatus()->value,
                'dateFrom' => $job->getDateFrom()->format('Y-m-d'),
                'dateTo' => $job->getDateTo()->format('Y-m-d'),
                'chunksTotal' => $job->getChunksTotal(),
                'createdAt' => $job->getCreatedAt()->format('d.m.Y H:i'),
                'finishedAt' => $job->getFinishedAt()?->format('d.m.Y H:i'),
                'lastError' => $job->getFailureReason(),
            ],
            $jobs,
        );

        return $this->json(['items' => $items]);
    }
}
