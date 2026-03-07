<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class ProcessOzonCostsAction
{
    private iterable $costCalculators;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceCostExistingExternalIdsQuery $costExistingExternalIdsQuery,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly LoggerInterface $logger,
        iterable $costCalculators,
    ) {
        $this->costCalculators = $costCalculators;
    }

    public function __invoke(string $companyId, string $rawDocId): int
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            throw new \RuntimeException('Raw document not found: ' . $rawDocId);
        }

        $rawData = $rawDoc->getRawData();
        $companyId = (string) $company->getId();
        $synced = 0;
        $batchSize = 100;

        $this->logger->info('[Ozon] Starting costs processing', ['total_records' => count($rawData)]);

        $allSkus = [];
        foreach ($rawData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }

        $listingsCache = [];
        if (!empty($allSkus)) {
            $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
                $company,
                MarketplaceType::OZON,
                array_keys($allSkus),
            );

            $this->logger->info('[Ozon] Loaded listings for costs', [
                'count' => count($listingsCache),
            ]);
        }

        $newListingsCreated = 0;
        foreach ($rawData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku === '' || isset($listingsCache[$sku])) {
                    continue;
                }

                $price = abs((float) ($op['amount'] ?? 0));
                $listing = new MarketplaceListing(Uuid::uuid4()->toString(), $company, null, MarketplaceType::OZON);
                $listing->setMarketplaceSku($sku);
                $listing->setPrice((string) $price);
                $listing->setName($item['name'] ?? null);
                $this->em->persist($listing);

                $listingsCache[$sku] = $listing;
                $newListingsCreated++;
            }
        }

        if ($newListingsCreated > 0) {
            $this->em->flush();
            $this->logger->info('[Ozon] Created missing listings for costs', ['count' => $newListingsCreated]);
        }

        $this->categoryResolver->preload($company, MarketplaceType::OZON);

        $counter = 0;
        $lastFlushedCounter = 0;
        $knownExternalIdsMap = [];
        $pending = [];
        $pendingIds = [];
        $dedupBatchSize = $batchSize;

        $processPendingBatch = function () use (
            &$pending,
            &$pendingIds,
            &$knownExternalIdsMap,
            &$counter,
            &$synced,
            &$lastFlushedCounter,
            &$listingsCache,
            &$company,
            $companyId,
            $batchSize,
        ): void {
            if (empty($pendingIds)) {
                return;
            }

            $dbExistingMap = $this->costExistingExternalIdsQuery->execute($companyId, $pendingIds);
            $knownExternalIdsMap += $dbExistingMap;

            foreach ($pending as $pendingItem) {
                try {
                    $entry = $pendingItem['entry'];
                    $externalId = $entry['external_id'];

                    if (isset($knownExternalIdsMap[$externalId])) {
                        continue;
                    }

                    $listing = $pendingItem['listing'];
                    $categoryCode = $entry['category_code'];
                    $category = $this->categoryResolver->resolve(
                        $company,
                        MarketplaceType::OZON,
                        $categoryCode,
                        $entry['category_name'],
                    );

                    $cost = new MarketplaceCost(
                        Uuid::uuid4()->toString(),
                        $company,
                        MarketplaceType::OZON,
                        $category,
                    );

                    $cost->setExternalId($externalId);
                    $cost->setCostDate($entry['cost_date']);
                    $cost->setAmount($entry['amount']);
                    $cost->setDescription($entry['description']);

                    if ($listing) {
                        $cost->setListing($listing);
                    }

                    $this->em->persist($cost);
                    $knownExternalIdsMap[$externalId] = true;
                    $synced++;
                    $counter++;
                } catch (\Exception $e) {
                    $this->logger->error('[Ozon] Failed to process cost', [
                        'external_id' => $pendingItem['entry']['external_id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            if (($counter - $lastFlushedCounter) >= $batchSize) {
                $this->em->flush();
                $this->em->clear();
                $lastFlushedCounter = $counter;

                $company = $this->em->find(Company::class, $companyId);
                $this->categoryResolver->resetCache();
                foreach ($listingsCache as $k => $cachedListing) {
                    $listingsCache[$k] = $this->em->getReference(
                        MarketplaceListing::class,
                        $cachedListing->getId(),
                    );
                }

                gc_collect_cycles();
                $this->logger->info('[Ozon] Costs batch', ['processed' => $counter, 'synced' => $synced]);
            }

            $pending = [];
            $pendingIds = [];
        };

        foreach ($rawData as $op) {
            try {
                $operationId = (string) ($op['operation_id'] ?? '');
                $operationDate = new \DateTimeImmutable($op['operation_date']);

                $listing = null;
                $firstItem = ($op['items'] ?? [])[0] ?? null;
                if ($firstItem) {
                    $sku = (string) ($firstItem['sku'] ?? '');
                    $listing = $listingsCache[$sku] ?? null;
                }

                $costEntries = $this->extractOzonCostEntries($op, $operationId, $operationDate);

                foreach ($costEntries as $entry) {
                    $externalId = $entry['external_id'];

                    if (isset($knownExternalIdsMap[$externalId])) {
                        continue;
                    }

                    $pending[] = [
                        'entry' => $entry,
                        'listing' => $listing,
                    ];
                    $pendingIds[] = $externalId;

                    if (count($pendingIds) >= $dedupBatchSize) {
                        $processPendingBatch();
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('[Ozon] Failed to process operation for costs', [
                    'operation_id' => $op['operation_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (!empty($pendingIds)) {
            $processPendingBatch();
        }

        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        $this->logger->info('[Ozon] Costs processing completed', [
            'total_synced' => $synced,
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
        ]);

        return $synced;
    }

    private function extractOzonCostEntries(array $op, string $operationId, \DateTimeImmutable $operationDate): array
    {
        $entries = [];

        $commission = abs((float) ($op['sale_commission'] ?? 0));
        if ($commission > 0) {
            $entries[] = [
                'external_id' => $operationId . '_commission',
                'category_code' => 'ozon_sale_commission',
                'category_name' => 'Комиссия Ozon за продажу',
                'amount' => (string) $commission,
                'cost_date' => $operationDate,
                'description' => 'Комиссия за продажу',
            ];
        }

        $delivery = abs((float) ($op['delivery_charge'] ?? 0));
        if ($delivery > 0) {
            $entries[] = [
                'external_id' => $operationId . '_delivery',
                'category_code' => 'ozon_delivery',
                'category_name' => 'Доставка Ozon',
                'amount' => (string) $delivery,
                'cost_date' => $operationDate,
                'description' => 'Стоимость доставки',
            ];
        }

        $returnDelivery = abs((float) ($op['return_delivery_charge'] ?? 0));
        if ($returnDelivery > 0) {
            $entries[] = [
                'external_id' => $operationId . '_return_delivery',
                'category_code' => 'ozon_return_delivery',
                'category_name' => 'Обратная доставка Ozon',
                'amount' => (string) $returnDelivery,
                'cost_date' => $operationDate,
                'description' => 'Обратная доставка',
            ];
        }

        $services = $op['services'] ?? [];
        foreach ($services as $idx => $service) {
            $serviceAmount = abs((float) ($service['price'] ?? 0));
            if ($serviceAmount <= 0) {
                continue;
            }

            $serviceName = $service['name'] ?? 'Неизвестная услуга';
            $categoryCode = $this->resolveOzonServiceCategoryCode($serviceName);

            $entries[] = [
                'external_id' => $operationId . '_svc_' . $idx,
                'category_code' => $categoryCode,
                'category_name' => $this->resolveOzonServiceCategoryName($categoryCode),
                'amount' => (string) $serviceAmount,
                'cost_date' => $operationDate,
                'description' => $serviceName,
            ];
        }

        return $entries;
    }

    private function resolveOzonServiceCategoryCode(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        if (str_contains($lower, 'логистик') || str_contains($lower, 'logistic')
            || str_contains($lower, 'магистраль') || str_contains($lower, 'last mile')) {
            return 'ozon_logistics';
        }

        if (str_contains($lower, 'обработк') || str_contains($lower, 'processing')
            || str_contains($lower, 'сборк')) {
            return 'ozon_processing';
        }

        if (str_contains($lower, 'хранени') || str_contains($lower, 'storage')
            || str_contains($lower, 'размещени')) {
            return 'ozon_storage';
        }

        if (str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')
            || str_contains($lower, 'приём платеж')) {
            return 'ozon_acquiring';
        }

        if (str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'трафик')) {
            return 'ozon_promotion';
        }

        if (str_contains($lower, 'подписк') || str_contains($lower, 'premium')
            || str_contains($lower, 'subscription')) {
            return 'ozon_subscription';
        }

        if (str_contains($lower, 'штраф') || str_contains($lower, 'penalty')
            || str_contains($lower, 'неустойк')) {
            return 'ozon_penalty';
        }

        if (str_contains($lower, 'компенсац') || str_contains($lower, 'compensation')
            || str_contains($lower, 'возмещени')) {
            return 'ozon_compensation';
        }

        return 'ozon_other_service';
    }

    private function resolveOzonServiceCategoryName(string $categoryCode): string
    {
        return match ($categoryCode) {
            'ozon_sale_commission' => 'Комиссия Ozon за продажу',
            'ozon_delivery' => 'Доставка Ozon',
            'ozon_return_delivery' => 'Обратная доставка Ozon',
            'ozon_logistics' => 'Логистика Ozon',
            'ozon_processing' => 'Обработка отправления Ozon',
            'ozon_storage' => 'Хранение на складе Ozon',
            'ozon_acquiring' => 'Эквайринг Ozon',
            'ozon_promotion' => 'Продвижение / реклама Ozon',
            'ozon_subscription' => 'Подписка Ozon',
            'ozon_penalty' => 'Штрафы Ozon',
            'ozon_compensation' => 'Компенсации Ozon',
            default => 'Прочие услуги Ozon',
        };
    }
}
