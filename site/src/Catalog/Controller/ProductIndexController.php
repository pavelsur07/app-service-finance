<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Application\ListProductsAction;
use App\Catalog\DTO\ProductListFilter;
use App\Catalog\Entity\Product;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

final class ProductIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceListingRepository $marketplaceListingRepository,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route('/catalog/products', name: 'catalog_products_index', methods: ['GET'])]
    public function __invoke(Request $request, ListProductsAction $listProductsAction): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, min(100, (int) $request->query->get('perPage', 20)));

        $filter = ProductListFilter::fromRequest($request)->withCompanyId($company->getId());
        $pager = $listProductsAction($filter, $page, $perPage);
        $products = iterator_to_array($pager->getCurrentPageResults());

        $marketplacesByProductId = $this->marketplaceListingRepository
            ->findMarketplaceNamesByProductIds($company->getId(), array_map(
                static fn (Product $product): string => $product->getId(),
                $products,
            ));

        return $this->render('catalog/product/index.html.twig', [
            'pager' => $pager,
            'products' => $products,
            'marketplacesByProductId' => $marketplacesByProductId,
            'filters' => [
                'q' => $request->query->get('q'),
                'status' => $request->query->get('status'),
                'page' => $page,
                'perPage' => $perPage,
            ],
            'canCreateProduct' => null !== $this->router->getRouteCollection()->get('catalog_products_new'),
        ]);
    }
}
