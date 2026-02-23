<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Infrastructure\Query\ProductPurchasePriceQuery;
use App\Catalog\Infrastructure\Query\ProductQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

final class ProductPurchasePriceHistoryController extends AbstractController
{
    #[Route('/catalog/products/{id}/purchase-price/history', name: 'catalog_products_purchase_price_history', methods: ['GET'])]
    public function __invoke(
        string $id,
        ActiveCompanyService $activeCompanyService,
        ProductQuery $productQuery,
        ProductPurchasePriceQuery $purchasePriceQuery,
    ): Response {
        Assert::uuid($id);

        // CompanyId определяем только в контроллере и передаём в read-запросы явно.
        $companyId = $activeCompanyService->getActiveCompany()->getId();

        // Проверяем принадлежность продукта активной компании через scoped load.
        $product = $productQuery->findOneForCompany($companyId, $id);
        if (null === $product) {
            throw new NotFoundHttpException();
        }

        $history = $purchasePriceQuery->fetchHistory($companyId, $id, 500);

        return $this->render('catalog/product/purchase_price_history.html.twig', [
            'product' => $product,
            'history' => $history,
        ]);
    }
}
