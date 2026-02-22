<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Application\SetPurchasePriceAction;
use App\Catalog\DTO\SetPurchasePriceCommand;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProductPurchasePriceSetController extends AbstractController
{
    #[Route('/catalog/products/{id}/purchase-prices', name: 'catalog_products_purchase_prices_set', methods: ['POST'])]
    public function __invoke(
        string $id,
        Request $request,
        SetPurchasePriceAction $setPurchasePriceAction,
        ActiveCompanyService $companyService,
    ): JsonResponse
    {
        $payload = $request->toArray();
        $company = $companyService->getActiveCompany();

        $command = new SetPurchasePriceCommand();
        $command->companyId = $company->getId();
        $command->productId = $id;
        $command->effectiveFrom = new \DateTimeImmutable((string) ($payload['effectiveFrom'] ?? 'now'));
        $command->priceAmount = (int) ($payload['priceAmount'] ?? 0);
        $command->currency = (string) ($payload['currency'] ?? 'RUB');
        $command->note = isset($payload['note']) ? (string) $payload['note'] : null;
        $command->userId = isset($payload['userId']) ? (string) $payload['userId'] : null;

        $priceId = $setPurchasePriceAction($command);

        return $this->json(['id' => $priceId]);
    }
}
