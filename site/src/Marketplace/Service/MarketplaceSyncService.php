<?php

namespace App\Marketplace\Service;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class MarketplaceSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $productRepository,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceCostCategoryRepository $costCategoryRepository,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function processSalesFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $synced = 0;

        foreach ($rawData as $item) {
            // Фильтрация: только продажи
            if (!isset($item['doc_type_name']) || $item['doc_type_name'] !== 'Продажа') {
                continue;
            }

            $retailAmount = (float)($item['retail_amount'] ?? 0);
            if ($retailAmount <= 0) {
                continue;
            }

            // ИСПОЛЬЗУЕМ srid - это уникальный ID строки
            $externalOrderId = (string)$item['srid'];
            $marketplaceSku = $item['sa_name'];

            // Проверка дубликата по srid
            $existing = $this->saleRepository->findOneBy([
                'company' => $company,
                'externalOrderId' => $externalOrderId
            ]);

            if ($existing) {
                continue;
            }

            // Создаём SaleData с информацией о товаре из WB
            $saleData = new \App\Marketplace\DTO\SaleData(
                marketplace: \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                externalOrderId: $externalOrderId,
                saleDate: new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']),
                marketplaceSku: $marketplaceSku,
                quantity: abs((int)$item['quantity']),
                pricePerUnit: (string)$item['retail_price'],
                totalRevenue: (string)abs($retailAmount),
                rawData: null
            );

            // Информация о товаре из WB для создания Product
            $productInfo = [
                'brand' => $item['brand_name'] ?? '',
                'subject' => $item['subject_name'] ?? '',
                'sku' => $item['sa_name'] ?? '',
                'barcode' => $item['barcode'] ?? '',
                'nm_id' => $item['nm_id'] ?? null,
            ];

            // Найти или создать Product и Listing
            $listing = $this->findOrCreateListing($company, $saleData, $productInfo);

            // Создать Sale
            $sale = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                $listing->getProduct(),
                $saleData->marketplace
            );

            $sale->setExternalOrderId($saleData->externalOrderId);
            $sale->setSaleDate($saleData->saleDate);
            $sale->setQuantity($saleData->quantity);
            $sale->setPricePerUnit($saleData->pricePerUnit);
            $sale->setTotalRevenue($saleData->totalRevenue);
            $sale->setRawDocumentId($rawDoc->getId());

            $this->em->persist($sale);
            $synced++;
        }

        $this->em->flush();
        return $synced;
    }

    public function syncSales(
        Company $company,
        MarketplaceAdapterInterface $adapter,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): int {
        // Получаем данные от API
        $salesData = $adapter->fetchSales($company, $fromDate, $toDate);

        // Создаём RawDocument
        $rawDoc = new \App\Marketplace\Entity\MarketplaceRawDocument(
            Uuid::uuid4()->toString(),
            $company,
            \App\Marketplace\Enum\MarketplaceType::from($adapter->getMarketplaceType()),
            'sales_report'
        );
        $rawDoc->setPeriodFrom(\DateTimeImmutable::createFromInterface($fromDate));
        $rawDoc->setPeriodTo(\DateTimeImmutable::createFromInterface($toDate));
        $rawDoc->setApiEndpoint($adapter->getMarketplaceType() . '::fetchSales');
        $rawDoc->setRawData(array_map(function($s) {
            return [
                'order_id' => $s->externalOrderId,
                'date' => $s->saleDate->format('Y-m-d'),
                'sku' => $s->marketplaceSku,
                'qty' => $s->quantity,
                'price' => $s->pricePerUnit,
                'revenue' => $s->totalRevenue,
            ];
        }, $salesData));
        $rawDoc->setRecordsCount(count($salesData));

        $this->em->persist($rawDoc);
        $this->em->flush();

        $rawDocId = $rawDoc->getId();
        $synced = 0;

        // Обрабатываем по одной
        foreach ($salesData as $saleData) {
            // Проверка дубликата
            $existing = $this->saleRepository->findByMarketplaceOrder(
                $company,
                $saleData->marketplace,
                $saleData->externalOrderId
            );

            if ($existing) {
                continue;
            }

            // Найти или создать Product и Listing
            $listing = $this->findOrCreateListing($company, $saleData);

            // Создать Sale
            $sale = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                $listing->getProduct(),
                $saleData->marketplace
            );

            $sale->setExternalOrderId($saleData->externalOrderId);
            $sale->setSaleDate($saleData->saleDate);
            $sale->setQuantity($saleData->quantity);
            $sale->setPricePerUnit($saleData->pricePerUnit);
            $sale->setTotalRevenue($saleData->totalRevenue);
            $sale->setRawDocumentId($rawDocId);

            $this->em->persist($sale);
            $synced++;
        }

        // Один flush в конце
        $this->em->flush();

        return $synced;
    }

    public function syncCosts(
        Company $company,
        MarketplaceAdapterInterface $adapter,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): int {
        $costsData = $adapter->fetchCosts($company, $fromDate, $toDate);
        $synced = 0;

        foreach ($costsData as $costData) {
            $category = $this->costCategoryRepository->findByCode($company, $costData->categoryCode);

            if (!$category) {
                continue;
            }

            $product = null;
            if ($costData->marketplaceSku) {
                $listing = $this->listingRepository->findByMarketplaceSku(
                    $company,
                    $costData->marketplace,
                    $costData->marketplaceSku
                );
                $product = $listing?->getProduct();
            }

            if ($costData->externalId) {
                $existing = $this->em->getRepository(MarketplaceCost::class)
                    ->findOneBy(['company' => $company, 'externalId' => $costData->externalId]);

                if ($existing) {
                    continue;
                }
            }

            $cost = new MarketplaceCost(
                Uuid::uuid4()->toString(),
                $company,
                $costData->marketplace,
                $category
            );

            $cost->setProduct($product);
            $cost->setAmount($costData->amount);
            $cost->setCostDate($costData->costDate);
            $cost->setDescription($costData->description);
            $cost->setExternalId($costData->externalId);

            $this->em->persist($cost);
            $synced++;
        }

        $this->em->flush();
        return $synced;
    }

    public function syncReturns(
        Company $company,
        MarketplaceAdapterInterface $adapter,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): int {
        $returnsData = $adapter->fetchReturns($company, $fromDate, $toDate);
        $synced = 0;

        foreach ($returnsData as $returnData) {
            $listing = $this->listingRepository->findByMarketplaceSku(
                $company,
                $returnData->marketplace,
                $returnData->marketplaceSku
            );

            if (!$listing) {
                continue;
            }

            if ($returnData->externalReturnId) {
                $existing = $this->returnRepository->findOneBy([
                    'company' => $company,
                    'externalReturnId' => $returnData->externalReturnId
                ]);

                if ($existing) {
                    continue;
                }
            }

            $return = new MarketplaceReturn(
                Uuid::uuid4()->toString(),
                $company,
                $listing->getProduct(),
                $returnData->marketplace
            );

            $return->setExternalReturnId($returnData->externalReturnId);
            $return->setReturnDate($returnData->returnDate);
            $return->setQuantity($returnData->quantity);
            $return->setRefundAmount($returnData->refundAmount);
            $return->setReturnReason($returnData->returnReason);
            $return->setReturnLogisticsCost($returnData->returnLogisticsCost);

            $this->em->persist($return);
            $synced++;
        }

        $this->em->flush();
        return $synced;
    }

    private function findOrCreateListing(Company $company, $saleData, array $productInfo = []): MarketplaceListing
    {
        $listing = $this->listingRepository->findByMarketplaceSku(
            $company,
            $saleData->marketplace,
            $saleData->marketplaceSku
        );

        if ($listing) {
            return $listing;
        }

        // Попытка найти Product по SKU
        $product = $this->productRepository->findBySku($company, $saleData->marketplaceSku);

        if (!$product) {
            // Создаём новый Product с данными из WB
            $product = new Product(Uuid::uuid4()->toString(), $company);
            $product->setSku($saleData->marketplaceSku);

            // Формируем название из данных WB
            if (!empty($productInfo)) {
                $brand = $productInfo['brand'] ?? '';
                $subject = $productInfo['subject'] ?? '';
                $sku = $productInfo['sku'] ?? $saleData->marketplaceSku;

                // Название: "{brand} {subject} ({sku})"
                $name = trim(sprintf('%s %s (%s)', $brand, $subject, $sku));
                $product->setName($name ?: 'Auto-created from ' . $saleData->marketplace->value);
            } else {
                $product->setName('Auto-created from ' . $saleData->marketplace->value);
            }

            $product->setPurchasePrice('0.00'); // Требует заполнения
            $this->em->persist($product);
        }

        // Создаём Listing
        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $company,
            $product,
            $saleData->marketplace
        );
        $listing->setMarketplaceSku($saleData->marketplaceSku);
        $listing->setPrice($saleData->pricePerUnit);

        $this->em->persist($listing);

        return $listing;
    }
}
