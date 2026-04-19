<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class AdsIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly AdLoadJobRepository $adLoadJobRepository,
    ) {}

    #[Route('/marketplace-ads', name: 'marketplace_ads_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $credentials = $this->marketplaceFacade->getConnectionCredentials(
            $companyId,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );

        $activeAdLoadJob = $this->adLoadJobRepository->findLatestActiveJobByCompanyAndMarketplace(
            $companyId,
            MarketplaceType::OZON,
        );

        return $this->render('marketplace_ads/index.html.twig', [
            'hasPerformanceConnection' => null !== $credentials,
            'activeAdLoadJob' => $activeAdLoadJob,
        ]);
    }
}
