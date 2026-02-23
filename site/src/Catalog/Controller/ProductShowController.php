<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Infrastructure\Query\ProductPurchasePriceQuery;
use App\Catalog\Infrastructure\Query\ProductQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Webmozart\Assert\Assert;

final class ProductShowController extends AbstractController
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    #[Route('/catalog/products/{id}', name: 'catalog_products_show', methods: ['GET'])]
    public function __invoke(
        string $id,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        ProductQuery $productQuery,
        ProductPurchasePriceQuery $purchasePriceQuery,
    ): Response
    {
        Assert::uuid($id);

        // Компания для read-запросов определяется только на уровне контроллера.
        $companyId = $activeCompanyService->getActiveCompany()->getId();
        $product = $productQuery->findOneForCompany($companyId, $id);
        if (null === $product) {
            throw new NotFoundHttpException();
        }

        $today = new \DateTimeImmutable('today');
        $todayPurchasePrice = $purchasePriceQuery->findPriceAtDate($companyId, $id, $today);

        // Параметр даты принимаем только в формате YYYY-MM-DD.
        $priceAtRaw = $request->query->get('price_at');
        $priceAtDate = null;
        $priceAtPurchasePrice = null;
        if (\is_string($priceAtRaw) && '' !== $priceAtRaw) {
            $candidate = \DateTimeImmutable::createFromFormat('Y-m-d', $priceAtRaw);
            if ($candidate instanceof \DateTimeImmutable && $candidate->format('Y-m-d') === $priceAtRaw) {
                $priceAtDate = $candidate;
                $priceAtPurchasePrice = $purchasePriceQuery->findPriceAtDate($companyId, $id, $candidate);
            }
        }

        return $this->render('catalog/product/show.html.twig', [
            'product' => $product,
            'todayPurchasePrice' => $todayPurchasePrice,
            'priceAtDate' => $priceAtDate,
            'priceAtPurchasePrice' => $priceAtPurchasePrice,
            'canEditProduct' => null !== $this->router->getRouteCollection()->get('catalog_products_edit'),
        ]);
    }
}
