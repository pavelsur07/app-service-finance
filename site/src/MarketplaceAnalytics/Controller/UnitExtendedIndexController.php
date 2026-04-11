<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class UnitExtendedIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    #[Route(
        '/marketplace-analytics/unit-extended',
        name: 'marketplace_analytics_unit_extended_index',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        $this->activeCompanyService->getActiveCompany();

        $marketplaces = [
            ['value' => '', 'label' => 'Все'],
            ...array_map(
                static fn (MarketplaceType $t): array => [
                    'value' => $t->value,
                    'label' => $t->getDisplayName(),
                ],
                MarketplaceType::cases(),
            ),
        ];

        return $this->render('marketplace_analytics/unit_extended/index.html.twig', [
            'marketplaces' => $marketplaces,
        ]);
    }
}
