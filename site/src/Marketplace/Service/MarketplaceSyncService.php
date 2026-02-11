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

    public function syncSales(
        Company $company,
        MarketplaceAdapterInterface $adapter,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): int {
        // Создаём RawDocument для хранения сырых данных
        $rawDoc = new \App\Marketplace\Entity\MarketplaceRawDocument(
            Uuid::uuid4()->toString(),
            $company,
            \App\Marketplace\Enum\MarketplaceType::from($adapter->getMarketplaceType()),
            'sales_report'
        );
        $rawDoc->setPeriodFrom(\DateTimeImmutable::createFromInterface($fromDate));
        $rawDoc->setPeriodTo(\DateTimeImmutable::createFromInterface($toDate));
        $rawDoc->setApiEndpoint($adapter->getMarketplaceType() . '::fetchSales');

        $salesData = $adapter->fetchSales($company, $fromDate, $toDate);

        // Сохраняем полный ответ
        $rawDoc->setRawData(['sales' => array_map(fn($s) => (array)$s, $salesData)]);
        $rawDoc->setRecordsCount(count($salesData));

        $this->em->persist($rawDoc);
        $this->em->flush(); // Flush чтобы получить ID для связи

        $synced = 0;

        foreach ($salesData as $saleData) {
            try {
                // Проверка дубликата
                $existing = $this->saleRepository->findByMarketplaceOrder(
                    $company,
                    $saleData->marketplace,
                    $saleData->externalOrderId
                );

                if ($existing) {
                    $rawDoc->incrementRecordsSkipped();
                    continue; // Уже есть
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
                $sale->setRawDocumentId($rawDoc->getId()); // Ссылка на источник
                $sale->setRawData($saleData->rawData); // Только эта строка

                $this->em->persist($sale);
                $synced++;
                $rawDoc->incrementRecordsCreated();

                // Flush каждые 100 записей
                if ($synced % 100 === 0) {
                    $this->em->flush();
                }

            } catch (\Exception $e) {
                $this->logger->error('Failed to sync sale', [
                    'company_id' => $company->getId(),
                    'order_id' => $saleData->externalOrderId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->em->flush();

        $this->logger->info('Sales synced', [
            'company_id' => $company->getId(),
            'marketplace' => $adapter->getMarketplaceType(),
            'count' => $synced
        ]);

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
            try {
                // Найти категорию затрат
                $category = $this->costCategoryRepository->findByCode($company, $costData->categoryCode);

                if (!$category) {
                    $this->logger->warning('Cost category not found', [
                        'code' => $costData->categoryCode,
                        'company_id' => $company->getId()
                    ]);
                    continue;
                }

                // Найти продукт если есть SKU
                $product = null;
                if ($costData->marketplaceSku) {
                    $listing = $this->listingRepository->findByMarketplaceSku(
                        $company,
                        $costData->marketplace,
                        $costData->marketplaceSku
                    );
                    $product = $listing?->getProduct();
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
                $cost->setRawData($costData->rawData);

                $this->em->persist($cost);
                $synced++;

                if ($synced % 100 === 0) {
                    $this->em->flush();
                }

            } catch (\Exception $e) {
                $this->logger->error('Failed to sync cost', [
                    'company_id' => $company->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->em->flush();

        $this->logger->info('Costs synced', [
            'company_id' => $company->getId(),
            'marketplace' => $adapter->getMarketplaceType(),
            'count' => $synced
        ]);

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
            try {
                $listing = $this->listingRepository->findByMarketplaceSku(
                    $company,
                    $returnData->marketplace,
                    $returnData->marketplaceSku
                );

                if (!$listing) {
                    $this->logger->warning('Listing not found for return', [
                        'sku' => $returnData->marketplaceSku
                    ]);
                    continue;
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
                $return->setRawData($returnData->rawData);

                $this->em->persist($return);
                $synced++;

                if ($synced % 100 === 0) {
                    $this->em->flush();
                }

            } catch (\Exception $e) {
                $this->logger->error('Failed to sync return', [
                    'company_id' => $company->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->em->flush();

        $this->logger->info('Returns synced', [
            'company_id' => $company->getId(),
            'marketplace' => $adapter->getMarketplaceType(),
            'count' => $synced
        ]);

        return $synced;
    }

    private function findOrCreateListing(Company $company, $saleData): MarketplaceListing
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
            // Создаём новый Product (требует ручного уточнения)
            $product = new Product(Uuid::uuid4()->toString(), $company);
            $product->setSku($saleData->marketplaceSku);
            $product->setName('Auto-created from ' . $saleData->marketplace->value);
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
