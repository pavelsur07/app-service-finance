<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace-analytics/unit-extended',
    name: 'marketplace_analytics_api_unit_extended',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class UnitExtendedController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly UnitExtendedQuery $unitExtendedQuery,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplace = $request->query->get('marketplace');
        if ($marketplace === null || $marketplace === '') {
            $marketplace = null;
        } else {
            $validValues = array_map(
                static fn (MarketplaceType $t): string => $t->value,
                MarketplaceType::cases(),
            );
            if (!in_array($marketplace, $validValues, true)) {
                return $this->json([
                    'error' => 'Invalid marketplace. Allowed: ' . implode(', ', $validValues),
                ], 422);
            }
        }

        $periodFrom = $request->query->get('periodFrom', '');
        $periodTo   = $request->query->get('periodTo', '');

        if ($periodFrom === '' || $periodTo === '') {
            return $this->json(['error' => 'periodFrom and periodTo are required'], 422);
        }

        if ($periodFrom > $periodTo) {
            return $this->json(['error' => 'periodFrom must be <= periodTo'], 422);
        }

        $result = $this->unitExtendedQuery->execute($companyId, $marketplace, $periodFrom, $periodTo);

        return $this->json($result);
    }
}
