<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Обрабатывает затраты Ozon из sales_report через процессорный pipeline.
 *
 * Вызывается из ProcessMarketplaceRawDocumentAction → MarketplaceRawProcessorRegistry
 * при нажатии кнопки «Затраты» или при переобработке периода.
 *
 * Логика категоризации:
 * - Точный маппинг SERVICE_CATEGORY_MAP по service name (атомарные категории)
 * - Fallback fuzzyResolve для неизвестных имён + логирование warning
 * - Нулевые маркеры (price=0) пропускаются
 * - Multi-SKU эквайринг делится поровну по items
 */
final class OzonCostsRawProcessor implements MarketplaceRawProcessorInterface
{
    /**
     * Атомарный маппинг: каждый service name → свой уникальный category code.
     *
     * Группировка для ОПиУ происходит ТОЛЬКО на уровне маппинга PLCategory.
     * Null = нулевой маркер (price всегда 0), пропустить.
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
        'MarketplaceServiceItemDisposalDetailed'                 => 'ozon_disposal',
        'MarketplaceServiceSellerReturnsCargoAssortment'         => 'ozon_return_from_stock',
        'MarketplaceServiceProductMovementFromWarehouse'         => 'ozon_logistic_pickup',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceCostExistingExternalIdsQuery $costExistingIdsQuery,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::COST
                && $marketplace === MarketplaceType::OZON;
        }

        return $type === MarketplaceType::OZON->value && $kind === 'costs';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        // Удаляем старые затраты по raw_document_id (только незакрытые в ОПиУ)
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

        return 0; // processBatch вызывается отдельно
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void
    {
        if (empty($rawRows)) {
            return;
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        // Собираем все SKU
        $allSkus = [];
        foreach ($rawRows as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }

        // Предзагрузка листингов
        $listingsIdCache = [];
        if (!empty($allSkus)) {
            $listings = $this->listingRepository->findListingsBySkusIndexed(
                $company,
                MarketplaceType::OZON,
                array_keys($allSkus),
            );
            foreach ($listings as $sku => $listing) {
                $listingsIdCache[$sku] = $listing->getId();
            }
        }

        // Создаём отсутствующие листинги
        $newListings = 0;
        foreach ($rawRows as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku === '' || isset($listingsIdCache[$sku])) {
                    continue;
                }

                $listing = new MarketplaceListing(Uuid::uuid4()->toString(), $company, null, MarketplaceType::OZON);
                $listing->setMarketplaceSku($sku);
                $listing->setPrice('0.00');
                $listing->setName($item['name'] ?? null);
                $this->em->persist($listing);

                $listingsIdCache[$sku] = $listing->getId();
                $newListings++;
            }
        }

        if ($newListings > 0) {
            $this->em->flush();
        }

        $this->categoryResolver->preload($company, MarketplaceType::OZON);

        // Генерируем cost entries
        $allEntries = [];
        foreach ($rawRows as $op) {
            $operationId = (string) ($op['operation_id'] ?? '');
            if ($operationId === '') {
                continue;
            }

            // Пропускаем финансовый возврат покупателю — не затраты
            if (($op['operation_type'] ?? '') === 'ClientReturnAgentOperation') {
                continue;
            }

            try {
                $operationDate = new \DateTimeImmutable($op['operation_date']);
            } catch (\Throwable) {
                continue;
            }

            foreach ($this->extractCostEntries($op, $operationId, $operationDate) as $entry) {
                $itemIdx   = $entry['_item_idx'] ?? 0;
                $items     = $op['items'] ?? [];
                $sku       = (string) ($items[$itemIdx]['sku'] ?? '');
                $listingId = $sku !== '' ? ($listingsIdCache[$sku] ?? null) : null;

                $allEntries[] = ['entry' => $entry, 'listingId' => $listingId];
            }
        }

        if (empty($allEntries)) {
            return;
        }

        // Дедупликация
        $allExternalIds = array_unique(array_map(
            static fn (array $row): string => $row['entry']['external_id'],
            $allEntries,
        ));
        $existingMap = $this->costExistingIdsQuery->execute($companyId, $allExternalIds);

        foreach ($allEntries as $row) {
            $entry      = $row['entry'];
            $externalId = $entry['external_id'];

            if (isset($existingMap[$externalId])) {
                continue;
            }

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

            if ($row['listingId']) {
                $cost->setListing($this->em->getReference(MarketplaceListing::class, $row['listingId']));
            }

            $this->em->persist($cost);
            $existingMap[$externalId] = true;
        }

        $this->em->flush();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractCostEntries(array $op, string $operationId, \DateTimeImmutable $operationDate): array
    {
        $entries = [];

        // 1. Комиссия
        $commission = abs((float) ($op['sale_commission'] ?? 0));
        if ($commission > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_commission',
                'category_code' => 'ozon_sale_commission',
                'category_name' => 'Комиссия Ozon за продажу',
                'amount'        => (string) $commission,
                'cost_date'     => $operationDate,
                'description'   => 'Комиссия за продажу Ozon',
                '_item_idx'     => 0,
            ];
        }

        // 2. Доставка
        $delivery = abs((float) ($op['delivery_charge'] ?? 0));
        if ($delivery > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_delivery',
                'category_code' => 'ozon_delivery',
                'category_name' => 'Доставка Ozon',
                'amount'        => (string) $delivery,
                'cost_date'     => $operationDate,
                'description'   => 'Доставка Ozon',
                '_item_idx'     => 0,
            ];
        }

        // 3. Обратная доставка
        $returnDelivery = abs((float) ($op['return_delivery_charge'] ?? 0));
        if ($returnDelivery > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_return_delivery',
                'category_code' => 'ozon_return_delivery',
                'category_name' => 'Обратная доставка Ozon',
                'amount'        => (string) $returnDelivery,
                'cost_date'     => $operationDate,
                'description'   => 'Обратная доставка Ozon',
                '_item_idx'     => 0,
            ];
        }

        // 4. services[]
        $services  = $op['services'] ?? [];
        $items     = $op['items'] ?? [];
        $itemCount = max(1, count($items));

        foreach ($services as $svcIdx => $service) {
            $serviceAmount = abs((float) ($service['price'] ?? 0));
            if ($serviceAmount <= 0.001) {
                continue;
            }

            $serviceName = $service['name'] ?? '';

            // Нулевые маркеры по таблице
            if (array_key_exists($serviceName, self::SERVICE_CATEGORY_MAP)
                && self::SERVICE_CATEGORY_MAP[$serviceName] === null) {
                continue;
            }

            $categoryCode = $this->resolveServiceCategoryCode($serviceName);

            if ($itemCount === 1) {
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
                // Multi-SKU: делим поровну
                $perItem     = round($serviceAmount / $itemCount, 2);
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

    private function resolveServiceCategoryCode(string $serviceName): string
    {
        // Primary: точный маппинг
        if (array_key_exists($serviceName, self::SERVICE_CATEGORY_MAP)) {
            return self::SERVICE_CATEGORY_MAP[$serviceName] ?? 'ozon_other_service';
        }

        // Fallback: fuzzy + логируем
        $fallbackCode = $this->fuzzyResolve($serviceName);

        $this->logger->warning('ozon_unknown_service_name', [
            'service_name' => $serviceName,
            'resolved_to'  => $fallbackCode,
        ]);

        return $fallbackCode;
    }

    private function fuzzyResolve(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        if (str_contains($lower, 'логистик') || str_contains($lower, 'logistic')
            || str_contains($lower, 'магистраль') || str_contains($lower, 'доставк')) {
            return 'ozon_logistic_direct';
        }
        if (str_contains($lower, 'обработк') || str_contains($lower, 'сборк')
            || str_contains($lower, 'fulfillment') || str_contains($lower, 'dropoff')) {
            return 'ozon_fulfillment';
        }
        if (str_contains($lower, 'хранени') || str_contains($lower, 'storage')
            || str_contains($lower, 'размещени')) {
            return 'ozon_storage';
        }
        if (str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')) {
            return 'ozon_acquiring';
        }
        if (str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'клик')) {
            return 'ozon_cpc';
        }
        if (str_contains($lower, 'упаковк') || str_contains($lower, 'package')) {
            return 'ozon_package_materials';
        }
        if (str_contains($lower, 'штраф') || str_contains($lower, 'penalty')
            || str_contains($lower, 'удержани')) {
            return 'ozon_penalty_undeliverable';
        }
        if (str_contains($lower, 'кросс') || str_contains($lower, 'поставк')) {
            return 'ozon_crossdocking';
        }

        return 'ozon_other_service';
    }

    private function getCategoryName(string $categoryCode): string
    {
        return match ($categoryCode) {
            'ozon_sale_commission'       => 'Комиссия Ozon за продажу',
            'ozon_delivery'              => 'Доставка Ozon',
            'ozon_return_delivery'       => 'Обратная доставка Ozon',
            'ozon_logistic_direct'       => 'Логистика к покупателю Ozon',
            'ozon_logistic_direct_vdc'   => 'Логистика к покупателю (вРЦ) Ozon',
            'ozon_logistic_direct_trans' => 'Магистраль к покупателю Ozon',
            'ozon_logistic_delivery'     => 'Доставка до покупателя Ozon',
            'ozon_logistic_last_mile'    => 'Last mile Ozon',
            'ozon_logistic_kgt'          => 'Доставка КГТ Ozon',
            'ozon_logistic_return'       => 'Обратная логистика Ozon',
            'ozon_logistic_return_trans' => 'Обратная магистраль Ozon',
            'ozon_logistic_inbound'      => 'Кросс-докинг (поставка) Ozon',
            'ozon_logistic_inbound_seller' => 'ТЭУ (поставка продавцом) Ozon',
            'ozon_logistic_pickup'       => 'Выезд за товаром (Pick-up) Ozon',
            'ozon_fulfillment'           => 'Сборка заказа Ozon',
            'ozon_dropoff_ff'            => 'Обработка отправления FF Ozon',
            'ozon_dropoff_pvz'           => 'Обработка отправления ПВЗ Ozon',
            'ozon_dropoff_sc'            => 'Обработка отправления СЦ Ozon',
            'ozon_dropoff_ppz'           => 'Обработка отправления ППЗ Ozon',
            'ozon_return_pvz'            => 'Перевыставление возврата ПВЗ Ozon',
            'ozon_return_partial'        => 'Обработка частичного возврата Ozon',
            'ozon_return_not_delivered'  => 'Возврат невостребованного товара Ozon',
            'ozon_return_after_delivery' => 'Возврат после доставки Ozon',
            'ozon_return_storage_pvz'    => 'Краткосрочное хранение возврата ПВЗ Ozon',
            'ozon_return_storage_wh'     => 'Долгосрочное хранение возврата склад Ozon',
            'ozon_package_materials'     => 'Упаковочные материалы Ozon',
            'ozon_package_labor'         => 'Упаковка партнёрами Ozon',
            'ozon_storage'               => 'Хранение на складе Ozon',
            'ozon_crossdocking'          => 'Кросс-докинг Ozon',
            'ozon_supply_additional'     => 'Обработка товара в грузоместе FBO Ozon',
            'ozon_supply_shortage'       => 'Бронирование места (неполный состав) Ozon',
            'ozon_supply_surplus'        => 'Обработка излишков поставки Ozon',
            'ozon_acquiring'             => 'Эквайринг Ozon',
            'ozon_cpc'                   => 'Оплата за клик Ozon',
            'ozon_marketing_action'      => 'Маркетинговые акции Ozon',
            'ozon_reviews'               => 'Приобретение отзывов Ozon',
            'ozon_premium_promotion'     => 'Продвижение Premium Ozon',
            'ozon_premium_cashback'      => 'Бонусы продавца Premium Ozon',
            'ozon_stars_membership'      => 'Звёздные товары Ozon',
            'ozon_early_payment'         => 'Досрочная выплата Ozon',
            'ozon_flexible_payment'      => 'Гибкий график выплат Ozon',
            'ozon_installment'           => 'Продажа в рассрочку Ozon',
            'ozon_penalty_undeliverable' => 'Удержание за недовложение Ozon',
            'ozon_marking'               => 'Обязательная маркировка Ozon',
            'ozon_return_from_stock'     => 'Комплектация для вывоза продавцом Ozon',
            'ozon_agency_fee'            => 'Агентская услуга 3PL Global Ozon',
            'ozon_disposal'              => 'Утилизация товара Ozon',
            default                      => 'Прочие услуги Ozon',
        };
    }
}
