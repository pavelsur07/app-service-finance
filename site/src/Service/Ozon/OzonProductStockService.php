<?php

namespace App\Service\Ozon;

use App\Api\Ozon\OzonApiClient;
use App\Entity\Company;
use App\Entity\Ozon\OzonProduct;
use App\Entity\Ozon\OzonProductStock;
use App\Repository\Ozon\OzonProductRepository;
use App\Repository\Ozon\OzonProductStockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

readonly class OzonProductStockService
{
    public function __construct(
        private OzonApiClient $client,
        private EntityManagerInterface $em,
        private OzonProductRepository $productRepository,
        private OzonProductStockRepository $stockRepository,
    ) {
    }

    public function updateStocks(Company $company): void
    {
        $stocks = $this->client->getStocks(
            $company->getOzonSellerId(),
            $company->getOzonApiKey()
        );

        foreach ($stocks as $item) {
            $offerId = $item['offer_id'] ?? null;
            if (!$offerId) {
                continue;
            }
            $product = $this->productRepository->findOneBy([
                'manufacturerSku' => $offerId,
                'company' => $company,
            ]);

            if (!$product instanceof OzonProduct) {
                continue;
            }

            $today = new \DateTimeImmutable('today');
            $stock = $this->stockRepository->findOneForDate($product, $company, $today);

            if (!$stock) {
                $stock = new OzonProductStock(Uuid::uuid4()->toString(), $product, $company);
                $this->em->persist($stock);
            }

            $stock->setQty((int) ($item['stock'] ?? 0));
            $stock->setUpdatedAt(new \DateTimeImmutable());
        }

        $this->em->flush();
    }
}
