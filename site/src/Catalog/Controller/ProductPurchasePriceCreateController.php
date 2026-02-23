<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Application\SetPurchasePriceAction;
use App\Catalog\DTO\SetPurchasePriceCommand;
use App\Catalog\Form\ProductPurchasePriceType;
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

final class ProductPurchasePriceCreateController extends AbstractController
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    #[Route('/catalog/products/{id}/purchase-price', name: 'catalog_products_purchase_price_create', methods: ['POST'])]
    public function __invoke(
        string $id,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        ProductQuery $productQuery,
        ProductPurchasePriceQuery $purchasePriceQuery,
        SetPurchasePriceAction $setPurchasePriceAction,
    ): Response {
        Assert::uuid($id);

        // Определяем активную компанию только в контроллере и передаём id в команду.
        $companyId = $activeCompanyService->getActiveCompany()->getId();
        $product = $productQuery->findOneForCompany($companyId, $id);
        if (null === $product) {
            throw new NotFoundHttpException();
        }

        $form = $this->createForm(ProductPurchasePriceType::class, null, [
            'action' => $this->generateUrl('catalog_products_purchase_price_create', ['id' => $id]),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{effectiveFrom:\DateTimeInterface, priceAmount:int, currency:string, note?:?string} $data */
            $data = $form->getData();

            $command = new SetPurchasePriceCommand();
            $command->companyId = $companyId;
            $command->productId = $id;
            $command->effectiveFrom = \DateTimeImmutable::createFromInterface($data['effectiveFrom']);
            $command->priceAmount = (int) $data['priceAmount'];
            $command->currency = (string) $data['currency'];
            $command->note = isset($data['note']) && '' !== trim((string) $data['note']) ? (string) $data['note'] : null;

            // userId в текущем сценарии не требуется, поэтому оставляем null.
            $setPurchasePriceAction($command);

            $this->addFlash('success', 'Закупочная цена успешно добавлена.');

            return $this->redirectToRoute('catalog_products_show', ['id' => $id, '_fragment' => 'purchase-price']);
        }

        $today = new \DateTimeImmutable('today');

        return $this->render('catalog/product/show.html.twig', [
            'product' => $product,
            'todayPurchasePrice' => $purchasePriceQuery->findPriceAtDate($companyId, $id, $today),
            'priceAtDate' => null,
            'priceAtPurchasePrice' => null,
            'purchasePriceForm' => $form->createView(),
            'canEditProduct' => null !== $this->router->getRouteCollection()->get('catalog_products_edit'),
        ]);
    }
}
