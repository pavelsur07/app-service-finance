<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace')]
#[IsGranted('ROLE_USER')]
class MarketplaceConnectionController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly FinanceFacade $financeFacade,
    ) {
    }

    #[Route('/connections', name: 'marketplace_connections_index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->companyService->getActiveCompany();

        $connections = $this->connectionRepository->findByCompany($company);

        return $this->render('marketplace/connections.html.twig', [
            'connections' => $connections,
            'availableMarketplaces' => MarketplaceType::cases(),
            // «Базовое» дерево ОПиУ без статей маркетплейса: затраты и продажи МП некуда разнести.
            'needsPlExtension' => [] !== $connections && $this->financeFacade->needsMarketplacePlExtension((string) $company->getId()),
        ]);
    }
}
