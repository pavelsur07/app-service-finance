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
use Doctrine\DBAL\Connection;
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
     * Атомарный маппинг: каждый service name → свой уникальный category code.
     *
     * Группировка для ОПиУ происходит ТОЛЬКО на уровне маппинга PLCategory —
     * пользователь сам решает какие категории объединять в одну строку P&L.
     *
     * Null = нулевой маркер (price всегда 0), пропустить без создания записи.
     *
     * @var array<string, string|null>
     */
    private const SERVICE_CATEGORY_MAP = [
        // === ЛОГИСТИКА ПРЯМАЯ ===
        'MarketplaceServiceItemDirectFlowLogistic'               => 'ozon_logistic_direct',
        'MarketplaceServiceItemDirectFlowLogisticVDC'            => 'ozon_logistic_direct_vdc',
        'MarketplaceServiceItemDirectFlowTrans'                  => 'ozon_logistic_direct_trans',
        'MarketplaceDeliveryCostItem'                            => 'ozon_logistic_delivery',
        'MarketplaceServiceItemDelivToCustomer'                  => 'ozon_logistic_last_mile',
        'MarketplaceServiceItemRedistributionLastMileCourier'    => 'ozon_logistic_last_mile',
        'MarketplaceServiceItemDeliveryKGT'                      => 'ozon_logistic_kgt',

        // === ЛОГИСТИКА ОБРАТНАЯ ===
        'MarketplaceServiceItemReturnFlowLogistic'               => 'ozon_logistic_return',
        'MarketplaceServiceItemReturnFlowTrans'                  => 'ozon_logistic_return_trans',

        // === ЛОГИСТИКА ПОСТАВКИ НА СКЛАД ===
        'ItemAdvertisementForSupplierLogistic'                   => 'ozon_logistic_inbound',
        'ItemAdvertisementForSupplierLogisticSeller'             => 'ozon_logistic_inbound_seller',
        'MarketplaceServiceItemPickup'                           => 'ozon_logistic_pickup',

        // === ОБРАБОТКА ОТПРАВЛЕНИЙ ===
        'MarketplaceServiceItemFulfillment'                      => 'ozon_fulfillment',
        'MarketplaceServiceItemDropoffFF'                        => 'ozon_dropoff_ff',
        'MarketplaceServiceItemDropoffPVZ'                       => 'ozon_dropoff_pvz',
        'MarketplaceServiceItemDropoffSC'                        => 'ozon_dropoff_sc',
        'MarketplaceServiceItemDropoffPPZ'                       => 'ozon_dropoff_ppz',

        // === ОБРАБОТКА ВОЗВРАТОВ ===
        'MarketplaceServiceItemRedistributionReturnsPVZ'         => 'ozon_return_pvz',
        'MarketplaceServiceItemReturnPartGoodsCustomer'          => 'ozon_return_partial',
        'MarketplaceNotDeliveredCostItem'                        => 'ozon_return_not_delivered',
        'MarketplaceReturnAfterDeliveryCostItem'                 => 'ozon_return_after_delivery',
        'MarketplaceReturnStorageServiceAtThePickupPointFbsItem' => 'ozon_return_storage_pvz',
        'MarketplaceReturnStorageServiceInTheWarehouseFbsItem'   => 'ozon_return_storage_wh',

        // === НУЛЕВЫЕ МАРКЕРЫ (пропускать, price = 0) ===
        'MarketplaceServiceItemReturnNotDelivToCustomer'         => null,
        'MarketplaceServiceItemReturnAfterDelivToCustomer'       => null,

        // === УПАКОВКА ===
        'MarketplaceServiceItemPackageMaterialsProvision'        => 'ozon_package_materials',
        'MarketplaceServiceItemPackageRedistribution'            => 'ozon_package_labor',

        // === ХРАНЕНИЕ ===
        'OperationMarketplaceServiceStorage'                     => 'ozon_storage',
        'MarketplaceReturnStorageServiceAtThePickupPointFbsItem' => 'ozon_return_storage_pvz',
        'MarketplaceReturnStorageServiceInTheWarehouseFbsItem'   => 'ozon_return_storage_wh',

        // === КРОСС-ДОКИНГ / ПОСТАВКА НА FBO ===
        'MarketplaceServiceItemCrossdocking'                     => 'ozon_crossdocking',
        'OperationMarketplaceSupplyAdditional'                   => 'ozon_supply_additional',
        'OperationMarketplaceServiceSupplyInboundCargoShortage'  => 'ozon_supply_shortage',
        'OperationMarketplaceServiceSupplyInboundCargoSurplus'   => 'ozon_supply_surplus',

        // === ЭКВАЙРИНГ ===
        'MarketplaceRedistributionOfAcquiringOperation'          => 'ozon_acquiring',

        // === РЕКЛАМА ===
        'OperationMarketplaceCostPerClick'                       => 'ozon_cpc',
        'MarketplaceMarketingActionCostItem'                     => 'ozon_marketing_action',
        'MarketplaceSaleReviewsItem'                             => 'ozon_reviews',

        // === ПРОДВИЖЕНИЕ / PREMIUM ===
        'MarketplaceServicePremiumPromotion'                     => 'ozon_premium_promotion',
        'MarketplaceServicePremiumCashbackIndividualPoints'      => 'ozon_premium_cashback',
        'ItemAgentServiceStarsMembership'                        => 'ozon_stars_membership',

        // === ФИНАНСОВЫЕ УСЛУГИ ===
        'OperationMarketplaceServiceEarlyPaymentAccrual'         => 'ozon_early_payment',
        'MarketplaceServiceItemFlexiblePaymentSchedule'          => 'ozon_flexible_payment',
        'MarketplaceServiceItemInstallment'                      => 'ozon_installment',

        // === ШТРАФЫ / УДЕРЖАНИЯ ===
        'OperationMarketplaceWithHoldingForUndeliverableGoods'   => 'ozon_penalty_undeliverable',

        // === ПРОЧЕЕ ===
        'MarketplaceServiceItemMarkingItems'                     => 'ozon_marking',
        'MarketplaceServiceItemReturnFromStock'                  => 'ozon_return_from_stock',
        'OperationMarketplaceAgencyFeeAggregator3PLGlobal'       => 'ozon_agency_fee',
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
        private readonly Connection $connection,
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

        // Удаляем ранее созданные затраты по этому raw-документу.
        // Обеспечивает корректную переобработку: при изменении SERVICE_CATEGORY_MAP
        // или исправлении логики старые записи удаляются и создаются заново.
        // Удаляем только затраты без document_id (не закрытые в ОПиУ).
        $deleted = $this->connection->executeStatement(
            'DELETE FROM marketplace_costs WHERE raw_document_id = :rawDocId AND document_id IS NULL',
            ['rawDocId' => $rawDocId],
        );

        if ($deleted > 0) {
            $this->logger->info('[Ozon] Deleted existing costs for reprocessing', [
                'raw_doc_id' => $rawDocId,
                'deleted'    => $deleted,
            ]);
        }

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
                    $cost->setRawDocumentId($rawDocId);
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
            // Комиссия / доставка (из полей операции)
            'ozon_sale_commission'       => 'Комиссия Ozon за продажу',
            'ozon_delivery'              => 'Доставка Ozon',
            'ozon_return_delivery'       => 'Обратная доставка Ozon',
            // Логистика прямая
            'ozon_logistic_direct'       => 'Логистика к покупателю Ozon',
            'ozon_logistic_direct_vdc'   => 'Логистика к покупателю (вРЦ) Ozon',
            'ozon_logistic_direct_trans' => 'Магистраль к покупателю Ozon',
            'ozon_logistic_delivery'     => 'Доставка до покупателя Ozon',
            'ozon_logistic_last_mile'    => 'Last mile Ozon',
            'ozon_logistic_kgt'          => 'Доставка КГТ Ozon',
            // Логистика обратная
            'ozon_logistic_return'       => 'Обратная логистика Ozon',
            'ozon_logistic_return_trans' => 'Обратная магистраль Ozon',
            // Логистика поставки
            'ozon_logistic_inbound'      => 'Кросс-докинг (поставка) Ozon',
            'ozon_logistic_inbound_seller' => 'ТЭУ (поставка продавцом) Ozon',
            'ozon_logistic_pickup'       => 'Выезд за товаром (Pick-up) Ozon',
            // Обработка отправлений
            'ozon_fulfillment'           => 'Сборка заказа Ozon',
            'ozon_dropoff_ff'            => 'Обработка отправления FF Ozon',
            'ozon_dropoff_pvz'           => 'Обработка отправления ПВЗ Ozon',
            'ozon_dropoff_sc'            => 'Обработка отправления СЦ Ozon',
            'ozon_dropoff_ppz'           => 'Обработка отправления ППЗ Ozon',
            // Обработка возвратов
            'ozon_return_pvz'            => 'Перевыставление возврата ПВЗ Ozon',
            'ozon_return_partial'        => 'Обработка частичного возврата Ozon',
            'ozon_return_not_delivered'  => 'Возврат невостребованного товара Ozon',
            'ozon_return_after_delivery' => 'Возврат после доставки Ozon',
            'ozon_return_storage_pvz'    => 'Краткосрочное хранение возврата ПВЗ Ozon',
            'ozon_return_storage_wh'     => 'Долгосрочное хранение возврата склад Ozon',
            // Упаковка
            'ozon_package_materials'     => 'Упаковочные материалы Ozon',
            'ozon_package_labor'         => 'Упаковка партнёрами Ozon',
            // Хранение
            'ozon_storage'               => 'Хранение на складе Ozon',
            // Поставка FBO
            'ozon_crossdocking'          => 'Кросс-докинг Ozon',
            'ozon_supply_additional'     => 'Обработка товара в грузоместе FBO Ozon',
            'ozon_supply_shortage'       => 'Бронирование места (неполный состав) Ozon',
            'ozon_supply_surplus'        => 'Обработка излишков поставки Ozon',
            // Эквайринг
            'ozon_acquiring'             => 'Эквайринг Ozon',
            // Реклама
            'ozon_cpc'                   => 'Оплата за клик Ozon',
            'ozon_marketing_action'      => 'Маркетинговые акции Ozon',
            'ozon_reviews'               => 'Приобретение отзывов Ozon',
            // Продвижение Premium
            'ozon_premium_promotion'     => 'Продвижение Premium Ozon',
            'ozon_premium_cashback'      => 'Бонусы продавца Premium Ozon',
            'ozon_stars_membership'      => 'Звёздные товары Ozon',
            // Финансовые услуги
            'ozon_early_payment'         => 'Досрочная выплата Ozon',
            'ozon_flexible_payment'      => 'Гибкий график выплат Ozon',
            'ozon_installment'           => 'Продажа в рассрочку Ozon',
            // Штрафы
            'ozon_penalty_undeliverable' => 'Удержание за недовложение Ozon',
            // Прочее
            'ozon_marking'               => 'Обязательная маркировка Ozon',
            'ozon_return_from_stock'     => 'Комплектация для вывоза продавцом Ozon',
            'ozon_agency_fee'            => 'Агентская услуга 3PL Global Ozon',
            default                      => 'Прочие услуги Ozon',
        };
    }
}
