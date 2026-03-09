<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceListingsQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/listings')]
#[IsGranted('ROLE_USER')]
class MarketplaceListingsController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly MarketplaceListingsQuery $listingsQuery,
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    #[Route('', name: 'marketplace_listings_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $filter      = $request->query->get('filter', 'all');
        $page        = max(1, (int) $request->query->get('page', 1));
        $marketplace = $request->query->get('marketplace');

        // Валидируем marketplace из enum
        $marketplaceType = null;
        if ($marketplace !== null) {
            $marketplaceType = MarketplaceType::tryFrom($marketplace);
            if ($marketplaceType === null) {
                $marketplace = null;
            }
        }

        $mapped = match ($filter) {
            'mapped'   => true,
            'unmapped' => false,
            default    => null,
        };

        $pager         = $this->listingsQuery->paginate($companyId, $mapped, $page, self::PER_PAGE, $marketplaceType);
        $countAll      = $this->listingsQuery->countAll($companyId);
        $countMapped   = $this->listingsQuery->countMapped($companyId);
        $countUnmapped = $this->listingsQuery->countUnmapped($companyId);

        return $this->render('marketplace/listings/index.html.twig', [
            'pager'                  => $pager,
            'filter'                 => $filter,
            'marketplace'            => $marketplace,
            'available_marketplaces' => MarketplaceType::cases(),
            'count_all'              => $countAll,
            'count_mapped'           => $countMapped,
            'count_unmapped'         => $countUnmapped,
            'active_tab'             => 'listings',
        ]);
    }
}
