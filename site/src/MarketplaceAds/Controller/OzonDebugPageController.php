<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller;

use App\Company\Facade\CompanyFacade;
use App\MarketplaceAds\Infrastructure\Query\ActiveOzonPerformanceConnectionsQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace-ads/admin/debug', name: 'marketplace_ads_admin_debug', methods: ['GET'])]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class OzonDebugPageController extends AbstractController
{
    public function __construct(
        private readonly ActiveOzonPerformanceConnectionsQuery $connectionsQuery,
        private readonly CompanyFacade $companyFacade,
    ) {
    }

    public function __invoke(): Response
    {
        $companyIds = $this->connectionsQuery->getCompanyIds();

        $companies = [] === $companyIds
            ? []
            : $this->companyFacade->getCompaniesByIds($companyIds);

        return $this->render('marketplace_ads/admin_debug.html.twig', [
            'companies' => $companies,
        ]);
    }
}
