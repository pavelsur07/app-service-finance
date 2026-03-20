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

/**
 * Обрабатывает затраты из sales_report Ozon (documentType = 'sales_report', kind = 'costs').
 *
 * Источники затрат из одной записи sales_report:
 *
 * 1. sale_commission       — комиссия Ozon (из поля op['sale_commission'])
 * 2. delivery_charge       — доставка (из поля op['delivery_charge'])
 * 3. return_delivery_charge — обратная доставка (из поля op['return_delivery_charge'])
 * 4. services[]            — детальные услуги Ozon (логистика, эквайринг, упаковка и т.д.)
 *
 * Особые случаи:
 *
 * - OperationItemReturn (type=returns) — это затраты на логистику возврата, не финансовый
 *   возврат покупателю. Обрабатываются так же как type=services через services[].
 *
 * - MarketplaceRedistributionOfAcquiringOperation с несколькими SKU — сумма делится
 *   поровну по количеству items. Каждый item получает свой cost с суффиксом _svc_{idx}_{itemIdx}.
 *
 * - Нулевые маркеры (ReturnNotDelivToCustomer, ReturnAfterDelivToCustomer) — пропускаются,
 *   т.к. price = 0.
 *
 * - Неизвестные service names — логируются как warning с уровнем 'ozon_unknown_service_name'
 *   и сохраняются в категорию 'ozon_other_service'. Можно переобработать после обновления маппинга.
 */
final class ProcessOzonCostsAction
{
    /**
     * Точный маппинг service name → category code.
     * Null = нулевой маркер, пропустить.
     *
     * @var array<string, string|null>
     */
    private const SERVICE_CATEGORY_MAP = [
        // === ЛОГИСТИКА ===
        'MarketplaceServiceItemDirectFlowLogistic'               => 'ozon_logistics',
        'MarketplaceServiceItemReturnFlowLogistic'               => 'ozon_logistics',
        'MarketplaceServiceItemDirectFlowLogisticVDC'            => 'ozon_logistics',
        'MarketplaceServiceItemDelivToCustomer'                  => 'ozon_logistics',
        'MarketplaceServiceItemRedistributionLastMileCourier'    => 'ozon_logistics',
        'MarketplaceServiceItemDirectFlowTrans'                  => 'ozon_logistics',
        'MarketplaceServiceItemReturnFlowTrans'                  => 'ozon_logistics',
        'MarketplaceServiceItemDeliveryKGT'                      => 'ozon_logistics',
        'ItemAdvertisementForSupplierLogistic'                   => 'ozon_logistics',
        'ItemAdvertisementForSupplierLogisticSeller'             => 'ozon_logistics',
        'MarketplaceDeliveryCostItem'                            => 'ozon_logistics',

        // === ОБРАБОТКА ОТПРАВЛЕНИЙ ===
        'MarketplaceServiceItemFulfillment'                      => 'ozon_processing',
        'MarketplaceServiceItemDropoffFF'                        => 'ozon_processing',
        'MarketplaceServiceItemDropoffPVZ'                       => 'ozon_processing',
        'MarketplaceServiceItemDropoffSC'                        => 'ozon_processing',
        'MarketplaceServiceItemDropoffPPZ'                       => 'ozon_processing',
        'MarketplaceServiceItemReturnPartGoodsCustomer'          => 'ozon_processing',
        'MarketplaceNotDeliveredCostItem'                        => 'ozon_processing',
        'MarketplaceReturnAfterDeliveryCostItem'                 => 'ozon_processing',
        'MarketplaceServiceItemRedistributionReturnsPVZ'         => 'ozon_processing',
        'MarketplaceServiceItemPickup'                           => 'ozon_processing',

        // === НУЛЕВЫЕ МАРКЕРЫ (пропускать) ===
        'MarketplaceServiceItemReturnNotDelivToCustomer'         => null,
        'MarketplaceServiceItemReturnAfterDelivToCustomer'       => null,

        // === УПАКОВКА ===
        'MarketplaceServiceItemPackageMaterialsProvision'        => 'ozon_packaging',
        'MarketplaceServiceItemPackageRedistribution'            => 'ozon_packaging',

        // === ХРАНЕНИЕ ===
        'OperationMarketplaceServiceStorage'                     => 'ozon_storage',
        'MarketplaceReturnStorageServiceAtThePickupPointFbsItem' => 'ozon_storage',
        'MarketplaceReturnStorageServiceInTheWarehouseFbsItem'   => 'ozon_storage',

        // === КРОСС-ДОКИНГ / ПОСТАВКА ===
        'MarketplaceServiceItemCrossdocking'                     => 'ozon_crossdocking',
        'OperationMarketplaceSupplyAdditional'                   => 'ozon_supply',
        'OperationMarketplaceServiceSupplyInboundCargoShortage'  => 'ozon_supply',
        'OperationMarketplaceServiceSupplyInboundCargoSurplus'   => 'ozon_supply',

        // === ЭКВАЙРИНГ ===
        'MarketplaceRedistributionOfAcquiringOperation'          => 'ozon_acquiring',

        // === РЕКЛАМА / ПРОДВИЖЕНИЕ ===
        'OperationMarketplaceCostPerClick'                       => 'ozon_promotion',
        'MarketplaceMarketingActionCostItem'                     => 'ozon_promotion',
        'MarketplaceServicePremiumPromotion'                     => 'ozon_promotion',
        'MarketplaceServicePremiumCashbackIndividualPoints'      => 'ozon_promotion',
        'ItemAgentServiceStarsMembership'                        => 'ozon_promotion',
        'MarketplaceSaleReviewsItem'                             => 'ozon_promotion',

        // === ФИНАНСОВЫЕ УСЛУГИ ===
        'OperationMarketplaceServiceEarlyPaymentAccrual'         => 'ozon_finance',
        'MarketplaceServiceItemFlexiblePaymentSchedule'          => 'ozon_finance',
        'MarketplaceServiceItemInstallment'                      => 'ozon_finance',

        // === ШТРАФЫ / УДЕРЖАНИЯ ===
        'OperationMarketplaceWithHoldingForUndeliverableGoods'   => 'ozon_penalty',

        // === ПРОЧЕЕ ===
        'MarketplaceServiceItemMarkingItems'                     => 'ozon_other_service',
        'MarketplaceServiceItemReturnFromStock'                  => 'ozon_other_service',
        'OperationMarketplaceAgencyFeeAggregator3PLGlobal'       => 'ozon_other_service',
    ];

    /**
     * Типы операций, из которых извлекаются затраты через services[].
     * Включает OperationItemReturn (type=returns) — это логистика возврата, не финансовый возврат.
     */
    private const COST_OPERATION_TYPES = [
        'services',
        'other',    // эквайринг
        'returns',  // OperationItemReturn — логистика возврата
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceCostExistingExternalIdsQuery $costExistingExternalIdsQuery,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly LoggerInterface $logger,
    ) {
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

        $rawData   = $rawDoc->getRawData();
        $companyId = (string) $company->getId();
        $batchSize = 100;
        $synced    = 0;

        $this->logger->info('[Ozon] Starting costs processing', ['total_records' => count($rawData)]);

        // === Preload listings ===
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
        }

        // Auto-create missing listings
        $newListingsCreated = 0;
        foreach ($rawData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku === '' || isset($listingsCache[$sku])) {
                    continue;
                }

                $listing = new MarketplaceListing(Uuid::uuid4()->toString(), $company, null, MarketplaceType::OZON);
                $listing->setMarketplaceSku($sku);
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

        // === Batch processing with dedup ===
        $counter            = 0;
        $lastFlushedCounter = 0;
        $knownExternalIdsMap = [];
        $pending            = [];
        $pendingIds         = [];

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

            $dbExistingMap        = $this->costExistingExternalIdsQuery->execute($companyId, $pendingIds);
            $knownExternalIdsMap += $dbExistingMap;

            foreach ($pending as $pendingItem) {
                try {
                    $entry      = $pendingItem['entry'];
                    $externalId = $entry['external_id'];

                    if (isset($knownExternalIdsMap[$externalId])) {
                        continue;
                    }

                    $listing = $pendingItem['listing'];
                    $category = $this->categoryResolver->resolve(
                        $company,
                        MarketplaceType::OZON,
                        $entry['category_code'],
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

                    if ($listing instanceof MarketplaceListing) {
                        $cost->setListing($listing);
                    }

                    $this->em->persist($cost);
                    $knownExternalIdsMap[$externalId] = true;
                    $synced++;
                    $counter++;
                } catch (\Exception $e) {
                    $this->logger->error('[Ozon] Failed to persist cost', [
                        'external_id' => $pendingItem['entry']['external_id'] ?? 'unknown',
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            if (($counter - $lastFlushedCounter) >= $batchSize) {
                $this->em->flush();
                $this->em->clear();
                $lastFlushedCounter = $counter;

                $company = $this->em->find(Company::class, $companyId);
                $this->categoryResolver->resetCache();

                foreach ($listingsCache as $k => $cachedListing) {
                    $listingsCache[$k] = $this->em->getReference(MarketplaceListing::class, $cachedListing->getId());
                }

                gc_collect_cycles();
                $this->logger->info('[Ozon] Costs batch', ['processed' => $counter, 'synced' => $synced]);
            }

            $pending    = [];
            $pendingIds = [];
        };

        foreach ($rawData as $op) {
            try {
                $opType = $op['type'] ?? '';

                // Пропускаем чистые продажи (orders) — их затраты (commission, delivery) обрабатываются ниже
                // Пропускаем ClientReturnAgentOperation (финансовый возврат, не затраты)
                $operationType = $op['operation_type'] ?? '';
                if ($operationType === 'ClientReturnAgentOperation') {
                    continue;
                }

                $operationId   = (string) ($op['operation_id'] ?? '');
                $operationDate = new \DateTimeImmutable($op['operation_date']);

                $costEntries = $this->extractCostEntries($op, $operationId, $operationDate);

                foreach ($costEntries as $entry) {
                    $externalId = $entry['external_id'];

                    if (isset($knownExternalIdsMap[$externalId])) {
                        continue;
                    }

                    // Определяем листинг для этой конкретной записи (с учётом split по items)
                    $listing = null;
                    $itemIdx = $entry['_item_idx'] ?? 0;
                    $items   = $op['items'] ?? [];
                    if (isset($items[$itemIdx])) {
                        $sku     = (string) ($items[$itemIdx]['sku'] ?? '');
                        $listing = $sku !== '' ? ($listingsCache[$sku] ?? null) : null;
                    }

                    $pending[]    = ['entry' => $entry, 'listing' => $listing];
                    $pendingIds[] = $externalId;

                    if (count($pendingIds) >= $batchSize) {
                        $processPendingBatch();
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('[Ozon] Failed to process operation for costs', [
                    'operation_id' => $op['operation_id'] ?? 'unknown',
                    'error'        => $e->getMessage(),
                ]);
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
            'peak_memory'  => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
        ]);

        return $synced;
    }

    /**
     * Извлекает все затратные записи из одной операции.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractCostEntries(array $op, string $operationId, \DateTimeImmutable $operationDate): array
    {
        $entries = [];

        // === 1. Комиссия Ozon (только для заказов) ===
        $commission = abs((float) ($op['sale_commission'] ?? 0));
        if ($commission > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_commission',
                'category_code' => 'ozon_sale_commission',
                'category_name' => $this->getCategoryName('ozon_sale_commission'),
                'amount'        => (string) $commission,
                'cost_date'     => $operationDate,
                'description'   => 'Комиссия Ozon за продажу',
                '_item_idx'     => 0,
            ];
        }

        // === 2. delivery_charge / return_delivery_charge ===
        $delivery = abs((float) ($op['delivery_charge'] ?? 0));
        if ($delivery > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_delivery',
                'category_code' => 'ozon_delivery',
                'category_name' => $this->getCategoryName('ozon_delivery'),
                'amount'        => (string) $delivery,
                'cost_date'     => $operationDate,
                'description'   => 'Стоимость доставки',
                '_item_idx'     => 0,
            ];
        }

        $returnDelivery = abs((float) ($op['return_delivery_charge'] ?? 0));
        if ($returnDelivery > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_return_delivery',
                'category_code' => 'ozon_return_delivery',
                'category_name' => $this->getCategoryName('ozon_return_delivery'),
                'amount'        => (string) $returnDelivery,
                'cost_date'     => $operationDate,
                'description'   => 'Обратная доставка',
                '_item_idx'     => 0,
            ];
        }

        // === 3. services[] — основной источник затрат ===
        $services  = $op['services'] ?? [];
        $items     = $op['items'] ?? [];
        $itemCount = max(1, count($items));

        foreach ($services as $svcIdx => $service) {
            $serviceAmount = abs((float) ($service['price'] ?? 0));
            if ($serviceAmount <= 0.001) {
                // Нулевые маркеры (ReturnNotDelivToCustomer, ReturnAfterDelivToCustomer)
                continue;
            }

            $serviceName = $service['name'] ?? '';

            // Проверяем нулевые маркеры по таблице маппинга
            if (array_key_exists($serviceName, self::SERVICE_CATEGORY_MAP)
                && self::SERVICE_CATEGORY_MAP[$serviceName] === null) {
                continue;
            }

            $categoryCode = $this->resolveServiceCategoryCode($serviceName);

            if ($itemCount === 1) {
                // Обычный случай: один SKU
                $entries[] = [
                    'external_id'   => $operationId . '_svc_' . $svcIdx,
                    'category_code' => $categoryCode,
                    'category_name' => $this->getCategoryName($categoryCode),
                    'amount'        => (string) $serviceAmount,
                    'cost_date'     => $operationDate,
                    'description'   => $serviceName,
                    '_item_idx'     => 0,
                ];
            } else {
                // Multi-SKU: делим поровну по количеству items
                $perItem = round($serviceAmount / $itemCount, 2);

                // Последний item получает остаток (чтобы сумма не расходилась из-за округления)
                $distributed = 0.0;
                foreach ($items as $itemIdx => $item) {
                    $isLast  = ($itemIdx === $itemCount - 1);
                    $amount  = $isLast ? round($serviceAmount - $distributed, 2) : $perItem;
                    $distributed += $perItem;

                    $entries[] = [
                        'external_id'   => $operationId . '_svc_' . $svcIdx . '_item_' . $itemIdx,
                        'category_code' => $categoryCode,
                        'category_name' => $this->getCategoryName($categoryCode),
                        'amount'        => (string) $amount,
                        'cost_date'     => $operationDate,
                        'description'   => $serviceName,
                        '_item_idx'     => $itemIdx,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * Резолвит категорию по точному имени service name.
     *
     * При неизвестном имени:
     * - логирует warning с контекстом (service_name, fallback_code)
     * - возвращает fallback через fuzzy match по operation_type_name
     *
     * Это позволяет:
     * 1. Видеть в логах какие новые service names появились у Ozon
     * 2. Переобработать затраты после добавления имени в SERVICE_CATEGORY_MAP
     * 3. Пока что корректно категоризировать через fuzzy (т.к. operation_type_name — русскоязычное)
     */
    private function resolveServiceCategoryCode(string $serviceName): string
    {
        // Primary: точный маппинг
        if (array_key_exists($serviceName, self::SERVICE_CATEGORY_MAP)) {
            return self::SERVICE_CATEGORY_MAP[$serviceName] ?? 'ozon_other_service';
        }

        // Fallback: fuzzy по имени
        $fallbackCode = $this->fuzzyResolve($serviceName);

        // Логируем неизвестное имя — для последующей переобработки
        $this->logger->warning('ozon_unknown_service_name', [
            'service_name'  => $serviceName,
            'resolved_to'   => $fallbackCode,
        ]);

        return $fallbackCode;
    }

    /**
     * Fuzzy-матч по подстрокам в названии (fallback для неизвестных service names).
     * Работает как с английскими именами из API, так и с русскоязычными operation_type_name.
     */
    private function fuzzyResolve(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        if (str_contains($lower, 'логистик') || str_contains($lower, 'logistic')
            || str_contains($lower, 'магистраль') || str_contains($lower, 'last mile')
            || str_contains($lower, 'доставк')) {
            return 'ozon_logistics';
        }

        if (str_contains($lower, 'обработк') || str_contains($lower, 'processing')
            || str_contains($lower, 'сборк') || str_contains($lower, 'fulfillment')
            || str_contains($lower, 'dropoff')) {
            return 'ozon_processing';
        }

        if (str_contains($lower, 'хранени') || str_contains($lower, 'storage')
            || str_contains($lower, 'размещени')) {
            return 'ozon_storage';
        }

        if (str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')) {
            return 'ozon_acquiring';
        }

        if (str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'трафик')
            || str_contains($lower, 'клик')) {
            return 'ozon_promotion';
        }

        if (str_contains($lower, 'упаковк') || str_contains($lower, 'package')
            || str_contains($lower, 'packaging') || str_contains($lower, 'материал')) {
            return 'ozon_packaging';
        }

        if (str_contains($lower, 'досрочн') || str_contains($lower, 'рассрочк')
            || str_contains($lower, 'выплат')) {
            return 'ozon_finance';
        }

        if (str_contains($lower, 'штраф') || str_contains($lower, 'penalty')
            || str_contains($lower, 'удержани') || str_contains($lower, 'недовложен')) {
            return 'ozon_penalty';
        }

        if (str_contains($lower, 'кросс') || str_contains($lower, 'cross')
            || str_contains($lower, 'поставк') || str_contains($lower, 'supply')) {
            return 'ozon_supply';
        }

        return 'ozon_other_service';
    }

    private function getCategoryName(string $categoryCode): string
    {
        return match ($categoryCode) {
            'ozon_sale_commission'  => 'Комиссия Ozon за продажу',
            'ozon_delivery'         => 'Доставка Ozon',
            'ozon_return_delivery'  => 'Обратная доставка Ozon',
            'ozon_logistics'        => 'Логистика Ozon',
            'ozon_processing'       => 'Обработка отправления Ozon',
            'ozon_packaging'        => 'Упаковка Ozon',
            'ozon_storage'          => 'Хранение на складе Ozon',
            'ozon_crossdocking'     => 'Кросс-докинг Ozon',
            'ozon_supply'           => 'Услуги поставки Ozon',
            'ozon_acquiring'        => 'Эквайринг Ozon',
            'ozon_promotion'        => 'Продвижение / реклама Ozon',
            'ozon_finance'          => 'Финансовые услуги Ozon',
            'ozon_penalty'          => 'Штрафы / удержания Ozon',
            'ozon_compensation'     => 'Компенсации Ozon',
            default                 => 'Прочие услуги Ozon',
        };
    }
}
