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
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use App\Marketplace\Service\CostCalculator\CostCalculatorInterface;
use App\Marketplace\Service\CostCalculator\WbAcquiringCalculator;
use App\Marketplace\Service\CostCalculator\WbCommissionCalculator;
use App\Marketplace\Service\CostCalculator\WbLogisticsDeliveryCalculator;
use App\Marketplace\Service\CostCalculator\WbLogisticsReturnCalculator;
use App\Marketplace\Service\CostCalculator\WbPvzProcessingCalculator;
use App\Marketplace\Service\CostCalculator\WbStorageCalculator;
use App\Marketplace\Service\CostCalculator\WbWarehouseLogisticsCalculator;
use App\Marketplace\Service\Integration\MarketplaceAdapterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class MarketplaceSyncService
{
    /** @var CostCalculatorInterface[] */
    private array $costCalculators;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $productRepository,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceCostCategoryRepository $costCategoryRepository,
        private readonly MarketplaceCostRepository $costRepository,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly LoggerInterface $logger
    ) {
        // Регистрируем калькуляторы затрат
        $this->costCalculators = [
            new WbCommissionCalculator(),
            new WbAcquiringCalculator(),
            new WbLogisticsDeliveryCalculator(),
            new WbLogisticsReturnCalculator(),
            new WbStorageCalculator(),
            new WbPvzProcessingCalculator(),
            new WbWarehouseLogisticsCalculator(),
            // Легко добавить новые: new WbPenaltyCalculator(),
        ];
    }

    public function processSalesFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $synced = 0;

        foreach ($rawData as $item) {
            try {
                // Фильтрация: только продажи
                if (!isset($item['doc_type_name']) || $item['doc_type_name'] !== 'Продажа') {
                    continue;
                }

                $retailAmount = (float)($item['retail_amount'] ?? 0);
                if ($retailAmount <= 0) {
                    continue;
                }

                $externalOrderId = (string)$item['srid'];

                // Проверка дубликата по srid
                $existing = $this->saleRepository->findOneBy([
                    'company' => $company,
                    'externalOrderId' => $externalOrderId
                ]);

                if ($existing) {
                    continue;
                }

                // Данные из WB
                $nmId = (string)($item['nm_id'] ?? '');   // Артикул WB (строго string)
                $tsName = trim($item['ts_name'] ?? '');   // Размер (trim!)
                $saName = $item['sa_name'];               // Артикул производителя
                $brandName = $item['brand_name'] ?? '';
                $subjectName = $item['subject_name'] ?? '';

                // Нормализуем: пустая строка → NULL
                $tsName = ($tsName === '') ? null : $tsName;

                // DEBUG: логируем что ищем
                $this->logger->info('Looking for listing', [
                    'nm_id' => $nmId,
                    'ts_name' => $tsName,
                    'company_id' => $company->getId()
                ]);

                // Ищем Listing по nm_id + size
                $listing = $this->listingRepository->findByNmIdAndSize(
                    $company,
                    \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                    $nmId,
                    $tsName
                );

                if ($listing) {
                    $this->logger->info('Listing found', ['listing_id' => $listing->getId()]);
                } else {
                    $this->logger->info('Listing NOT found, creating new');
                }

                if (!$listing) {
                    // Создаём новый Product + Listing
                    try {
                        $listing = $this->createListingFromWbData($company, [
                            'nm_id' => $nmId,
                            'ts_name' => $tsName,
                            'sa_name' => $saName,
                            'brand_name' => $brandName,
                            'subject_name' => $subjectName,
                            'retail_price' => $item['retail_price'],
                        ]);

                        // ВАЖНО: flush сразу, чтобы следующая запись видела listing в БД
                        $this->em->flush();

                        $this->logger->info('Listing created and flushed', [
                            'listing_id' => $listing->getId(),
                            'nm_id' => $nmId,
                            'ts_name' => $tsName
                        ]);

                    } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                        // Товар уже создан (race condition или параллельный запрос)
                        $this->logger->warning('Duplicate listing on creation, searching again', [
                            'nm_id' => $nmId,
                            'ts_name' => $tsName
                        ]);

                        // Очищаем EM и ищем ещё раз
                        $this->em->clear();

                        $listing = $this->listingRepository->findByNmIdAndSize(
                            $company,
                            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                            $nmId,
                            $tsName
                        );

                        if (!$listing) {
                            $this->logger->error('Listing still not found after duplicate error', [
                                'nm_id' => $nmId,
                                'ts_name' => $tsName
                            ]);
                            continue; // Пропускаем эту продажу
                        }
                    }
                }

                // Создать Sale
                $sale = new MarketplaceSale(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    $listing->getProduct(),
                    \App\Marketplace\Enum\MarketplaceType::WILDBERRIES
                );

                $sale->setExternalOrderId($externalOrderId);
                $sale->setSaleDate(new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']));
                $sale->setQuantity(abs((int)$item['quantity']));
                $sale->setPricePerUnit((string)$item['retail_price']);
                $sale->setTotalRevenue((string)abs($retailAmount));
                $sale->setRawDocumentId($rawDoc->getId());

                $this->em->persist($sale);
                $synced++;

            } catch (\Exception $e) {
                // Логируем ошибку и продолжаем
                $this->logger->error('Failed to process sale item', [
                    'srid' => $item['srid'] ?? 'unknown',
                    'nm_id' => $item['nm_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // Финальный flush для Sales (listings уже flush-нуты после создания)
        $this->em->flush();

        $this->logger->info('Sales processing completed', [
            'total_synced' => $synced,
            'total_records' => count($rawData)
        ]);

        return $synced;
    }

    public function processReturnsFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $synced = 0;

        // Подсчёт всех типов документов для диагностики
        $docTypes = [];
        foreach ($rawData as $item) {
            $docType = $item['doc_type_name'] ?? 'NULL';
            $docTypes[$docType] = ($docTypes[$docType] ?? 0) + 1;
        }

        $this->logger->info('Processing returns - doc_type_name distribution', $docTypes);

        foreach ($rawData as $item) {
            try {
                // Логируем что пришло
                if (!isset($item['doc_type_name'])) {
                    $this->logger->warning('Item has no doc_type_name field', [
                        'srid' => $item['srid'] ?? 'unknown'
                    ]);
                }

                // Фильтрация: только возвраты
                $docTypeName = $item['doc_type_name'] ?? '';

                // Попробуем разные варианты
                if ($docTypeName !== 'Возврат' && $docTypeName !== 'возврат' && $docTypeName !== 'Return') {
                    continue;
                }

                $this->logger->info('Found return item', [
                    'doc_type_name' => $docTypeName,
                    'srid' => $item['srid'] ?? 'unknown'
                ]);

                // Для возвратов используем retail_price (цена за единицу)
                $retailPrice = (float)($item['retail_price'] ?? 0);
                if ($retailPrice <= 0) {
                    $this->logger->info('Retail price is zero, skipping', [
                        'retail_price' => $retailPrice
                    ]);
                    continue;
                }

                $externalReturnId = (string)$item['srid'];

                // Проверка дубликата по srid
                $existing = $this->returnRepository->findOneBy([
                    'company' => $company,
                    'externalReturnId' => $externalReturnId
                ]);

                if ($existing) {
                    continue;
                }

                // Данные из WB
                $nmId = (string)($item['nm_id'] ?? '');
                $tsName = trim($item['ts_name'] ?? '');
                $saName = $item['sa_name'];
                $brandName = $item['brand_name'] ?? '';
                $subjectName = $item['subject_name'] ?? '';

                // Нормализуем: пустая строка → NULL
                $tsName = ($tsName === '') ? null : $tsName;

                // Ищем Listing
                $listing = $this->listingRepository->findByNmIdAndSize(
                    $company,
                    \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                    $nmId,
                    $tsName
                );

                if (!$listing) {
                    // Создаём listing (если его ещё нет)
                    try {
                        $listing = $this->createListingFromWbData($company, [
                            'nm_id' => $nmId,
                            'ts_name' => $tsName,
                            'sa_name' => $saName,
                            'brand_name' => $brandName,
                            'subject_name' => $subjectName,
                            'retail_price' => $item['retail_price'] ?? '0',
                        ]);

                        $this->em->flush();

                    } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                        $this->em->clear();

                        $listing = $this->listingRepository->findByNmIdAndSize(
                            $company,
                            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                            $nmId,
                            $tsName
                        );

                        if (!$listing) {
                            $this->logger->error('Listing not found for return', [
                                'nm_id' => $nmId,
                                'ts_name' => $tsName
                            ]);
                            continue;
                        }
                    }
                }

                // Создать Return
                $return = new MarketplaceReturn(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing->getProduct(),
                    \App\Marketplace\Enum\MarketplaceType::WILDBERRIES
                );

                $return->setExternalReturnId($externalReturnId);
                $return->setReturnDate(new \DateTimeImmutable($item['rr_dt']));
                $return->setQuantity(abs((int)$item['quantity']));
                $return->setRefundAmount((string)$retailPrice); // retail_price
                $return->setReturnReason($item['supplier_oper_name'] ?? '');

                $this->em->persist($return);
                $synced++;

                $this->logger->info('Return created', [
                    'srid' => $externalReturnId,
                    'retail_price' => $retailPrice,
                    'quantity' => $item['quantity']
                ]);

            } catch (\Exception $e) {
                $this->logger->error('Failed to process return item', [
                    'srid' => $item['srid'] ?? 'unknown',
                    'nm_id' => $item['nm_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // Финальный flush для Returns
        $this->em->flush();

        $this->logger->info('Returns processing completed', [
            'total_synced' => $synced,
            'total_records' => count($rawData)
        ]);

        return $synced;
    }

    public function processCostsFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $synced = 0;

        foreach ($rawData as $item) {
            try {
                foreach ($this->costCalculators as $calculator) {
                    if (!$calculator->supports($item)) {
                        continue;
                    }

                    // Всегда пытаемся найти listing если есть nm_id
                    $listing = null;
                    $nmId = (string)($item['nm_id'] ?? '');
                    $tsName = trim($item['ts_name'] ?? '');
                    $tsName = ($tsName === '') ? null : $tsName;

                    if ($nmId !== '') {
                        $listing = $this->listingRepository->findByNmIdAndSize(
                            $company,
                            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                            $nmId,
                            $tsName
                        );
                    }

                    // Если калькулятор требует listing — пропускаем при его отсутствии
                    if ($calculator->requiresListing() && !$listing) {
                        $this->logger->warning('Listing not found for cost calculation', [
                            'nm_id' => $nmId,
                            'ts_name' => $tsName,
                            'calculator' => get_class($calculator)
                        ]);
                        continue;
                    }

                    // Калькулятор сам решает что делать с $listing (может быть null)
                    $costsData = $calculator->calculate($item, $listing);

                    foreach ($costsData as $costData) {
                        // Проверка дубликата
                        $existing = $this->costRepository->findOneBy([
                            'company' => $company,
                            'externalId' => $costData['external_id']
                        ]);

                        if ($existing) {
                            continue;
                        }

                        // Найти или создать категорию
                        $category = $this->costCategoryRepository->findByCode(
                            $company,
                            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                            $costData['category_code']
                        );

                        if (!$category) {
                            $category = new \App\Marketplace\Entity\MarketplaceCostCategory(
                                Uuid::uuid4()->toString(),
                                $company,
                                \App\Marketplace\Enum\MarketplaceType::WILDBERRIES
                            );
                            $category->setCode($costData['category_code']);
                            $category->setName($costData['description'] ?? $costData['category_code']);

                            $this->em->persist($category);
                            $this->em->flush();

                            $this->logger->info('Cost category auto-created', [
                                'code' => $costData['category_code'],
                                'marketplace' => 'wildberries'
                            ]);
                        }

                        // Создать Cost
                        $cost = new MarketplaceCost(
                            Uuid::uuid4()->toString(),
                            $company,
                            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                            $category
                        );

                        $cost->setExternalId($costData['external_id']);
                        $cost->setCostDate($costData['cost_date']);
                        $cost->setAmount($costData['amount']);
                        $cost->setDescription($costData['description']);

                        // Product берём из costData (калькулятор сам решил)
                        if (isset($costData['product'])) {
                            $cost->setProduct($costData['product']);
                        }

                        $this->em->persist($cost);
                        $synced++;
                    }
                }

            } catch (\Exception $e) {
                $this->logger->error('Failed to process cost item', [
                    'srid' => $item['srid'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        $this->em->flush();

        $this->logger->info('Costs processing completed', [
            'total_synced' => $synced,
            'total_records' => count($rawData)
        ]);

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
            $category = $this->costCategoryRepository->findByCode(
                $company,
                $costData->marketplace,
                $costData->categoryCode
            );

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

            $this->em->persist($return);
            $synced++;
        }

        $this->em->flush();
        return $synced;
    }

    private function createListingFromWbData(Company $company, array $wbData): MarketplaceListing
    {
        $nmId = $wbData['nm_id'];
        $tsName = $wbData['ts_name'];
        $saName = $wbData['sa_name'];
        $brandName = $wbData['brand_name'];
        $subjectName = $wbData['subject_name'];
        $price = $wbData['retail_price'];

        // Формируем название: {brand} {subject} {sa_name} {ts_name если есть}
        $nameParts = array_filter([
            $brandName,
            $subjectName,
            $saName,
            $tsName
        ]);
        $productName = implode(' ', $nameParts);

        // Создаём Product
        $product = new Product(Uuid::uuid4()->toString(), $company);
        $product->setSku($saName); // Internal SKU = артикул производителя
        $product->setName($productName);
        $product->setPurchasePrice('0.00'); // Требует заполнения вручную
        $this->em->persist($product);

        // Создаём Listing
        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $company,
            $product,
            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES
        );
        $listing->setMarketplaceSku($nmId);           // nm_id
        $listing->setSupplierSku($saName);            // sa_name
        $listing->setSize($tsName);                   // ts_name (может быть null)
        $listing->setPrice((string)$price);

        $this->em->persist($listing);

        return $listing;
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
