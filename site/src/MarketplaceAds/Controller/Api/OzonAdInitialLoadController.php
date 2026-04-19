<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\MarketplaceAds\Application\DispatchOzonAdLoadAction;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/marketplace-ads/ozon/initial-load', name: 'marketplace_ads_ozon_initial_load', methods: ['POST'])]
#[IsGranted('ROLE_COMPANY_USER')]
final class OzonAdInitialLoadController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly DispatchOzonAdLoadAction $dispatchAction,
    ) {}

    public function __invoke(): JsonResponse
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = $company->getId();

        $now = new \DateTimeImmutable();
        $dateFrom = new \DateTimeImmutable($now->format('Y') . '-01-01');
        $dateTo = (new \DateTimeImmutable('yesterday'))->setTime(0, 0);

        try {
            $job = ($this->dispatchAction)($companyId, $dateFrom, $dateTo);

            return $this->json([
                'jobId' => $job->getId(),
                'statusUrl' => $this->generateUrl(
                    'marketplace_ads_ozon_load_job_status',
                    ['jobId' => $job->getId()],
                ),
            ]);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }
    }
}
