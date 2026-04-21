<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller;

use App\Marketplace\Enum\MarketplaceType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class AdEfficiencyIndexController extends AbstractController
{
    #[Route('/marketplace-ads/efficiency', name: 'marketplace_ads_efficiency_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('marketplace_ads/efficiency/index.html.twig', [
            'activeTab' => 'efficiency',
            'marketplaces' => array_map(
                static fn (MarketplaceType $m): array => [
                    'value' => $m->value,
                    'label' => $m->getDisplayName(),
                ],
                MarketplaceType::cases(),
            ),
        ]);
    }
}
