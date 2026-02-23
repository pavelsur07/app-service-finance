<?php

namespace App\Catalog\Controller\Api;

use App\Catalog\Infrastructure\Query\ProductQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API для получения списка продуктов (для UI)
 *
 * ВАЖНО: Использует Query, НЕ прямой DBAL!
 */
#[Route('/api/products')]
#[IsGranted('ROLE_USER')]
class ProductsListController extends AbstractController
{
    public function __construct(
        private readonly ProductQuery $productsQuery,              // ✅ Query!
        private readonly ActiveCompanyService $activeCompanyService // ✅ Только в Controller
    ) {
    }

    /**
     * Получить список продуктов компании (для select)
     *
     * GET /api/products
     * Response: [{"id": "uuid", "sku": "...", "name": "..."}]
     */
    #[Route('', name: 'api_products_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        // ✅ Через Query с companyId!
        $products = $this->productsQuery->fetchAllForCompany($company->getId());

        return new JsonResponse($products);
    }
}
