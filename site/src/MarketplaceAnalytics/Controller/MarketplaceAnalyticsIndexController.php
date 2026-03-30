<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class MarketplaceAnalyticsIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
    ) {}

    #[Route(
        '/marketplace-analytics',
        name: 'marketplace_analytics_unit_economics_index',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $marketplaces = array_map(
            static fn (MarketplaceType $t): array => [
                'value' => $t->value,
                'label' => $t->getDisplayName(),
            ],
            MarketplaceType::cases(),
        );

        return $this->render('marketplace_analytics/index.html.twig', [
            'companyId' => $company->getId(),
            'marketplaces' => $marketplaces,
        ]);
    }
}
