<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Infrastructure\Query\Reconciliation;

use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Ozon\Application\Service\OzonAccrualServiceCategoryResolver;
use App\Marketplace\Ozon\Domain\OzonCostCategory;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonLedgerOperationsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonLedgerTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRawAccrualTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRealizationTotalsQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Источники сверки с Ozon: «Реализация», сырой by-day и учёт. Суммы в тестах посчитаны вручную.
 */
final class OzonReconciliationQueriesTest extends IntegrationTestCase
{
    private const DAY = '2026-06-10';

    private string $companyId;
    private string $otherCompanyId;
    private string $listingId;
    private string $byDayDocId;

    private \DateTimeImmutable $from;
    private \DateTimeImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->from = new \DateTimeImmutable('2026-06-01');
        $this->to = new \DateTimeImmutable('2026-06-30');

        $this->companyId = $this->seedCompany(1);
        $this->otherCompanyId = $this->seedCompany(2);
        $this->listingId = $this->seedListing($this->companyId);
        $this->byDayDocId = $this->seedByDayDocument($this->companyId, self::DAY, $this->accruals());
    }

    public function testRawFlowsSumBothBasesAndSkipGarbage(): void
    {
        $flows = $this->rawQuery()->flows($this->companyId, $this->from, $this->to);

        self::assertSame(299900, $flows->salesSellerBase->amountMinor());
        self::assertSame(116857, $flows->salesBuyerBase->amountMinor());
        self::assertSame(1, $flows->salesCount);
        self::assertSame(264700, $flows->returnsSellerBase->amountMinor());
        self::assertSame(147743, $flows->returnsBuyerBase->amountMinor());
        self::assertSame(1, $flows->returnsCount);
    }

    public function testRawFlowsIgnoreAccrualsOutsidePeriodAndOtherCompany(): void
    {
        $other = $this->rawQuery()->flows($this->otherCompanyId, $this->from, $this->to);
        self::assertSame(0, $other->salesSellerBase->amountMinor());
        self::assertSame(0, $other->salesCount);

        // Начисление 2026-07-01 лежит в том же документе, но вне периода.
        $july = $this->rawQuery()->flows($this->companyId, new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'));
        self::assertSame(0, $july->salesCount);
    }

    public function testRawCostsAreNetOfStornoAndGroupedByCategory(): void
    {
        $costs = $this->rawQuery()->costsByCategory($this->companyId, $this->from, $this->to);

        // Комиссия: списание 1379.54 минус возврат комиссии 1217.62.
        self::assertSame(16192, $costs['ozon_sale_commission']->net->amountMinor());
        self::assertSame(2, $costs['ozon_sale_commission']->count);
        self::assertSame(11800, $costs[$this->codeOf('Logistic')]->net->amountMinor());
        self::assertSame(535, $costs[$this->codeOf('LastMileCourier')]->net->amountMinor());
        // NON_ITEM, ITEM и неизвестная услуга (type_id 99 нет в справочнике).
        self::assertSame(2499000, $costs[$this->codeOf('PremiumSubscription')]->net->amountMinor());
        self::assertSame(10000, $costs[$this->codeOf('TemporaryPlacement')]->net->amountMinor());
        self::assertSame(700, $costs['ozon_unknown_99']->net->amountMinor());
        self::assertCount(6, $costs);
    }

    public function testPresentDaysListDocumentsOfPeriodOnly(): void
    {
        $this->seedByDayDocument($this->companyId, '2026-06-11', []);
        $this->seedByDayDocument($this->companyId, '2026-07-02', []);
        $this->seedByDayDocument($this->otherCompanyId, '2026-06-12', []);

        self::assertSame(['2026-06-10', '2026-06-11'], $this->rawQuery()->presentDays($this->companyId, $this->from, $this->to));
    }

    public function testLedgerCountsOnlyRowsFromByDayDocuments(): void
    {
        $legacyDocId = $this->seedDocument($this->companyId, 'ozon::v3/finance/transaction/list', '2026-06-05', []);
        $categoryId = $this->seedCategory($this->companyId, 'ozon_logistic_direct');

        $this->insertSale('ozon-accrual-A-product-0', 299900, self::DAY, $this->byDayDocId);
        $this->insertSale('legacy-posting', 100000, '2026-06-05', $legacyDocId);
        $this->insertSale('manual', 5000, '2026-06-06', null);
        $this->insertReturn(264700, self::DAY, $this->byDayDocId);
        $this->insertCost(11800, 'charge', self::DAY, $categoryId, $this->byDayDocId);
        $this->insertCost(1800, 'storno', self::DAY, $categoryId, $this->byDayDocId);
        $this->insertCost(777, 'charge', '2026-06-05', $categoryId, $legacyDocId);
        $this->insertCost(300, 'charge', '2026-06-06', null, null);

        $flows = $this->ledgerQuery()->flows($this->companyId, $this->from, $this->to);
        self::assertSame(299900, $flows->sales->amountMinor());
        self::assertSame(1, $flows->salesCount);
        self::assertSame(264700, $flows->returns->amountMinor());

        $costs = $this->ledgerQuery()->costsByCategory($this->companyId, $this->from, $this->to);
        self::assertSame(['ozon_logistic_direct'], array_keys($costs));
        self::assertSame(10000, $costs['ozon_logistic_direct']->net->amountMinor());
        self::assertSame(2, $costs['ozon_logistic_direct']->count);

        $outside = $this->ledgerQuery()->costsOutsideRaw($this->companyId, $this->from, $this->to);
        self::assertSame(1077, $outside->net->amountMinor());
        self::assertSame(2, $outside->count);

        $otherFlows = $this->ledgerQuery()->flows($this->otherCompanyId, $this->from, $this->to);
        self::assertSame(0, $otherFlows->salesCount);
    }

    public function testRealizationIsNullWithoutReportAndSumsWithIt(): void
    {
        self::assertNull($this->realizationQuery()->fetch($this->companyId, $this->from, $this->to));

        $docId = $this->seedDocument($this->companyId, MarketplaceRawFormat::OZON_REALIZATION_V2->value, '2026-06-01', [], 'realization', '2026-06-30');
        $this->insertRealization($docId, 'sku-1', 116857, 1, null, null);
        $this->insertRealization($docId, 'sku-2', 0, 0, 147743, 1);

        $totals = $this->realizationQuery()->fetch($this->companyId, $this->from, $this->to);

        self::assertNotNull($totals);
        self::assertSame(116857, $totals->sales->amountMinor());
        self::assertSame(1, $totals->salesQuantity);
        self::assertSame(147743, $totals->returns->amountMinor());
        self::assertSame(1, $totals->returnsQuantity);

        self::assertNull($this->realizationQuery()->fetch($this->otherCompanyId, $this->from, $this->to));
        self::assertNull($this->realizationQuery()->fetch($this->companyId, new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31')));
    }

    public function testOperationsDrillDownMatchesTotalsAndIsScoped(): void
    {
        $legacyDocId = $this->seedDocument($this->companyId, 'ozon::v3/finance/transaction/list', '2026-06-05', []);
        $logistics = $this->seedCategory($this->companyId, 'ozon_logistic_direct');
        $commission = $this->seedCategory($this->companyId, 'ozon_sale_commission');
        $unknown = $this->seedCategory($this->companyId, 'ozon_unknown_99');

        $this->insertCost(11800, 'charge', self::DAY, $logistics, $this->byDayDocId);
        $this->insertCost(1800, 'storno', '2026-06-11', $logistics, $this->byDayDocId);
        $this->insertCost(16192, 'charge', self::DAY, $commission, $this->byDayDocId);
        $this->insertCost(700, 'charge', self::DAY, $unknown, $this->byDayDocId);
        $this->insertCost(300, 'charge', self::DAY, null, $this->byDayDocId);
        $this->insertCost(777, 'charge', '2026-06-05', $logistics, $legacyDocId);
        $this->insertSale('ozon-accrual-A-product-0', 299900, self::DAY, $this->byDayDocId);
        $this->insertSale('legacy', 100, '2026-06-05', $legacyDocId);

        $ops = new OzonLedgerOperationsQuery($this->connection);

        $byCategory = $ops->createCostsQueryBuilder($this->companyId, $this->from, $this->to, OzonReconciliationBlock::LOGISTICS, 'ozon_logistic_direct')->executeQuery()->fetchAllAssociative();
        self::assertCount(2, $byCategory);
        self::assertSame('2026-06-11', $byCategory[0]['operation_date']);

        $byBlock = $ops->createCostsQueryBuilder($this->companyId, $this->from, $this->to, OzonReconciliationBlock::LOGISTICS)->executeQuery()->fetchAllAssociative();
        self::assertCount(2, $byBlock);

        $unrecognized = $ops->createCostsQueryBuilder($this->companyId, $this->from, $this->to, OzonReconciliationBlock::UNRECOGNIZED)->executeQuery()->fetchAllAssociative();
        self::assertCount(2, $unrecognized, 'неизвестная категория и затрата без категории');

        $all = $ops->createCostsQueryBuilder($this->companyId, $this->from, $this->to)->executeQuery()->fetchAllAssociative();
        self::assertCount(5, $all, 'легаси-затрата вне by-day не входит');

        self::assertCount(1, $ops->createSalesQueryBuilder($this->companyId, $this->from, $this->to)->executeQuery()->fetchAllAssociative());
        self::assertSame([], $ops->createSalesQueryBuilder($this->otherCompanyId, $this->from, $this->to)->executeQuery()->fetchAllAssociative());
        self::assertSame([], $ops->createCostsQueryBuilder($this->otherCompanyId, $this->from, $this->to)->executeQuery()->fetchAllAssociative());
    }

    public function testDocumentsNotFullyProcessedAreExcludedFromBothSides(): void
    {
        // День 11-го загружен, но обработка не завершена: ни сырьё, ни учёт его не считают — это неполное покрытие, а не расхождение.
        $pendingDoc = $this->seedDocument($this->companyId, MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value, '2026-06-11', [
            'accruals' => [[
                'accrual_id' => 9, 'date' => '2026-06-11', 'posting' => ['products' => [[
                    'sku' => '1', 'commission' => ['sale_amount' => ['amount' => '500'], 'sale_price' => ['amount' => '200']],
                ]]],
            ]],
            'service_types' => [],
        ], 'accrual_by_day', null, 'pending');
        $this->insertSale('ozon-accrual-PENDING-product-0', 50000, '2026-06-11', $pendingDoc);

        self::assertSame(['2026-06-10'], $this->rawQuery()->presentDays($this->companyId, $this->from, $this->to));
        self::assertSame(1, $this->rawQuery()->flows($this->companyId, $this->from, $this->to)->salesCount);
        self::assertSame(0, $this->ledgerQuery()->flows($this->companyId, $this->from, $this->to)->salesCount);
    }

    public function testEntriesAreRoundedPerRowLikeProcessors(): void
    {
        $this->seedByDayDocument($this->companyId, '2026-06-12', [
            ['accrual_id' => 21, 'date' => '2026-06-12', 'non_item_fee' => ['type_id' => 52, 'accrued' => ['amount' => '-1.004']]],
            ['accrual_id' => 22, 'date' => '2026-06-12', 'non_item_fee' => ['type_id' => 52, 'accrued' => ['amount' => '-1.004']]],
        ]);

        $costs = $this->rawQuery()->costsByCategory($this->companyId, new \DateTimeImmutable('2026-06-12'), new \DateTimeImmutable('2026-06-12'));

        // Процессоры пишут каждую строку с двумя знаками: 2 × 1.00, а не округлённые 2.008.
        self::assertSame(200, $costs[$this->codeOf('PremiumSubscription')]->net->amountMinor());
    }

    public function testUncategorizedCostsDrillDownByServiceCode(): void
    {
        $this->insertCost(300, 'charge', self::DAY, null, $this->byDayDocId);

        $rows = (new OzonLedgerOperationsQuery($this->connection))
            ->createCostsQueryBuilder($this->companyId, $this->from, $this->to, OzonReconciliationBlock::UNRECOGNIZED, OzonLedgerTotalsQuery::NO_CATEGORY_CODE)
            ->executeQuery()->fetchAllAssociative();

        self::assertCount(1, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accruals(): array
    {
        return [
            // Продажа с комиссией и двумя услугами доставки.
            [
                'accrual_id' => 1, 'date' => self::DAY, 'unit_number' => 'A', 'accrued_category' => 'POSTING',
                'posting' => ['products' => [[
                    'sku' => '700000000',
                    'delivery' => ['services' => [
                        ['type_id' => 32, 'accrued' => ['amount' => '-118']],
                        ['type_id' => 29, 'accrued' => ['amount' => '-5.35']],
                    ]],
                    'commission' => [
                        'seller_price' => ['amount' => '2999'], 'sale_price' => ['amount' => '1168.57'],
                        'sale_amount' => ['amount' => '2999'], 'commission' => ['amount' => '-1379.54'],
                    ],
                ]]],
            ],
            // Возврат: отрицательные суммы, комиссия возвращается.
            [
                'accrual_id' => 2, 'date' => self::DAY, 'unit_number' => 'B', 'accrued_category' => 'POSTING',
                'posting' => ['products' => [[
                    'sku' => '700000001', 'delivery' => null,
                    'commission' => [
                        'seller_price' => ['amount' => '-2647'], 'sale_price' => ['amount' => '-1477.43'],
                        'sale_amount' => ['amount' => '-2647'], 'commission' => ['amount' => '1217.62'],
                    ],
                ]]],
            ],
            // NON_ITEM: подписка.
            ['accrual_id' => 3, 'date' => self::DAY, 'accrued_category' => 'NON_ITEM', 'non_item_fee' => ['type_id' => 52, 'accrued' => ['amount' => '-24990']]],
            // ITEM: размещение и услуга, которой нет в справочнике.
            [
                'accrual_id' => 4, 'date' => self::DAY, 'accrued_category' => 'ITEM',
                'item_fees' => ['fees' => [['sku' => '700000000', 'fees' => [
                    ['type_id' => 78, 'accrued' => ['amount' => '-100']],
                    ['type_id' => 99, 'accrued' => ['amount' => '-7']],
                ]]]],
            ],
            // Мусор не должен ронять запрос: products — строка, нечисловая сумма, нулевая услуга.
            ['accrual_id' => 5, 'date' => self::DAY, 'posting' => ['products' => 'oops']],
            ['accrual_id' => 6, 'date' => self::DAY, 'posting' => ['products' => [['sku' => '1', 'commission' => ['sale_amount' => ['amount' => 'n/a'], 'commission' => ['amount' => 'x']], 'delivery' => ['services' => [['type_id' => 32, 'accrued' => ['amount' => '0']]]]]]]],
            // Начисление вне периода внутри того же документа.
            [
                'accrual_id' => 7, 'date' => '2026-07-01', 'posting' => ['products' => [[
                    'sku' => '1', 'commission' => ['sale_amount' => ['amount' => '500'], 'sale_price' => ['amount' => '200']],
                ]]],
            ],
        ];
    }

    private function codeOf(string $accrualTypeName): string
    {
        $category = OzonCostCategory::findByAccrualTypeName($accrualTypeName);
        self::assertNotNull($category, $accrualTypeName);

        return $category->code;
    }

    private function rawQuery(): OzonRawAccrualTotalsQuery
    {
        return new OzonRawAccrualTotalsQuery($this->connection, new OzonAccrualServiceCategoryResolver());
    }

    private function ledgerQuery(): OzonLedgerTotalsQuery
    {
        return new OzonLedgerTotalsQuery($this->connection);
    }

    private function realizationQuery(): OzonRealizationTotalsQuery
    {
        return new OzonRealizationTotalsQuery($this->connection);
    }

    private function seedCompany(int $index): string
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return (string) $company->getId();
    }

    private function seedListing(string $companyId): string
    {
        $company = $this->em->find(\App\Company\Entity\Company::class, $companyId);
        self::assertNotNull($company);
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('700000000')->build();
        $this->em->persist($listing);
        $this->em->flush();

        return (string) $listing->getId();
    }

    /**
     * @param list<array<string, mixed>> $accruals
     */
    private function seedByDayDocument(string $companyId, string $day, array $accruals): string
    {
        return $this->seedDocument(
            $companyId,
            MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value,
            $day,
            [
                'accruals' => $accruals,
                'service_types' => ['32' => 'Logistic', '29' => 'LastMileCourier', '52' => 'PremiumSubscription', '78' => 'TemporaryPlacement'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedDocument(string $companyId, string $endpoint, string $day, array $payload, string $type = 'accrual_by_day', ?string $to = null, string $status = 'completed'): string
    {
        $company = $this->em->find(\App\Company\Entity\Company::class, $companyId);
        self::assertNotNull($company);
        $doc = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType($type)
            ->withProcessingStatus($status)
            ->withPeriod(new \DateTimeImmutable($day), new \DateTimeImmutable($to ?? $day))
            ->build();
        $doc->setApiEndpoint($endpoint);
        $doc->setRawData($payload);
        $this->em->persist($doc);
        $this->em->flush();

        return (string) $doc->getId();
    }

    private function seedCategory(string $companyId, string $code): string
    {
        $id = Uuid::uuid4()->toString();
        $this->connection->insert('marketplace_cost_categories', [
            'id' => $id, 'company_id' => $companyId, 'name' => $code, 'code' => $code, 'is_active' => 1, 'is_system' => 0,
            'marketplace' => 'ozon', 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ], ['is_active' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'is_system' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

        return $id;
    }

    private function insertSale(string $externalId, int $minor, string $date, ?string $docId): void
    {
        $this->connection->insert('marketplace_sales', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $this->companyId, 'listing_id' => $this->listingId, 'marketplace' => 'ozon',
            'external_order_id' => $externalId, 'sale_date' => $date, 'quantity' => 1, 'price_per_unit' => $this->dec($minor),
            'total_revenue' => $this->dec($minor), 'raw_document_id' => $docId, 'created_at' => '2026-06-30 00:00:00', 'updated_at' => '2026-06-30 00:00:00',
        ]);
    }

    private function insertReturn(int $minor, string $date, ?string $docId): void
    {
        $this->connection->insert('marketplace_returns', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $this->companyId, 'listing_id' => $this->listingId, 'marketplace' => 'ozon',
            'return_date' => $date, 'quantity' => 1, 'refund_amount' => $this->dec($minor), 'raw_document_id' => $docId,
            'created_at' => '2026-06-30 00:00:00', 'updated_at' => '2026-06-30 00:00:00',
        ]);
    }

    private function insertCost(int $minor, string $operationType, string $date, ?string $categoryId, ?string $docId): void
    {
        $this->connection->insert('marketplace_costs', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $this->companyId, 'marketplace' => 'ozon', 'category_id' => $categoryId,
            'amount' => $this->dec($minor), 'operation_type' => $operationType, 'cost_date' => $date, 'raw_document_id' => $docId,
            'created_at' => '2026-06-30 00:00:00', 'updated_at' => '2026-06-30 00:00:00',
        ]);
    }

    private function insertRealization(string $docId, string $sku, int $salesMinor, int $qty, ?int $returnMinor, ?int $returnQty): void
    {
        $this->connection->insert('marketplace_ozon_realizations', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $this->companyId, 'raw_document_id' => $docId, 'sku' => $sku,
            'seller_price_per_instance' => $this->dec($salesMinor), 'quantity' => $qty, 'total_amount' => $this->dec($salesMinor),
            'return_quantity' => $returnQty, 'return_amount' => null === $returnMinor ? null : $this->dec($returnMinor),
            'period_from' => '2026-06-01', 'period_to' => '2026-06-30', 'created_at' => '2026-07-05 00:00:00',
        ]);
    }

    private function dec(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
