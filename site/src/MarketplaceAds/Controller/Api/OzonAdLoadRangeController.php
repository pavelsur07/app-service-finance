<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\MarketplaceAds\Application\DispatchOzonAdLoadAction;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/marketplace-ads/ozon/load-range', name: 'marketplace_ads_ozon_load_range', methods: ['POST'])]
#[IsGranted('ROLE_COMPANY_OWNER')]
final class OzonAdLoadRangeController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly DispatchOzonAdLoadAction $dispatchAction,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = $company->getId();

        $body = json_decode($request->getContent(), true) ?? [];
        $dateFromStr = (string) ($body['dateFrom'] ?? '');
        $dateToStr = (string) ($body['dateTo'] ?? '');

        $dateFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateFromStr);
        $dateTo = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateToStr);

        if (false === $dateFrom || false === $dateTo) {
            return $this->json(['message' => 'Неверный формат даты. Ожидается YYYY-MM-DD.'], 400);
        }

        try {
            $job = ($this->dispatchAction)($companyId, $dateFrom, $dateTo);

            return $this->json([
                'jobId' => $job->getId(),
                'statusUrl' => $this->generateUrl(
                    'marketplace_ads_ozon_load_job_status',
                    ['jobId' => $job->getId()],
                ),
            ]);
        } catch (\DomainException | \InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }
    }
}
