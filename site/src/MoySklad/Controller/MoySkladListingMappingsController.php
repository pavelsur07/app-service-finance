<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery;
use App\MoySklad\Infrastructure\Query\MoySkladListingMappingsQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class MoySkladListingMappingsController extends AbstractController
{
    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}', name: 'moysklad_listing_mappings', requirements: ['connectionId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['GET'])]
    public function __invoke(string $connectionId, string $marketplace, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections, MarketplaceFacade $listings, MoySkladListingMappingsQuery $mappings, MoySkladCatalogTargetsQuery $targets): Response
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connection = $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $raw = $request->query->all()['q'] ?? '';
        $q = is_string($raw) ? mb_substr(trim($raw), 0, 100) : '';
        $page = $request->query->getInt('page', 1);
        if ($page < 1) {
            throw new UnprocessableEntityHttpException('Неверный номер страницы.');
        }
        $result = $listings->paginateListingsForExternalMapping($companyId, $marketplace, $q, $page);
        if ($page > 1 && [] === $result->items && $result->totalItems > 0) {
            throw new UnprocessableEntityHttpException('Страница не существует.');
        }
        $states = $mappings->currentForListings($companyId, $connectionId, array_column($result->items, 'id'));

        return $this->render('moy_sklad/mappings/index.html.twig', [
            'connection' => $connection, 'mappingConnectionId' => $connectionId, 'mappingMarketplace' => $marketplace,
            'providerName' => MarketplaceType::from($marketplace)->getDisplayName(),
            'listingsPage' => $result, 'states' => $states, 'q' => $q, 'catalogReady' => $targets->isReady($companyId, $connectionId),
        ]);
    }
}
