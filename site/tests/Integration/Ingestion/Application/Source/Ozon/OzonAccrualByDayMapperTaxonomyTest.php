<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Action\SeedExternalCategoryMappingsAction;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryCompanyMapping;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class OzonAccrualByDayMapperTaxonomyTest extends IntegrationTestCase
{
    public function testMappedCategoryKeepsStaticParentLabel(): void
    {
        /** @var SeedExternalCategoryMappingsAction $seedAction */
        $seedAction = self::getContainer()->get(SeedExternalCategoryMappingsAction::class);
        $seedAction(IngestSource::OZON);

        $companyId = Uuid::uuid7()->toString();
        $rawRecord = $this->rawRecord($companyId);

        /** @var OzonAccrualByDayMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayMapper::class);
        $transactions = $mapper->mapForCategoryMetadataRefresh($rawRecord, [[
            'accrual_id' => 777000,
            'date' => '2026-06-15',
            'unit_number' => 'unit-1',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => 999,
                'name' => 'CrossDock',
                'accrued' => ['amount' => '-10.00', 'currency' => 'RUB'],
            ],
        ]], recordUnknownCategories: false);

        self::assertCount(1, $transactions);
        self::assertSame('ozon_cross_docking', $transactions[0]->sourceData['_ozon_category_code']);
        self::assertSame('Доставка до склада', $transactions[0]->sourceData['_ozon_category_parent']);
    }

    /**
     * Регрессия: статья выбирается по имени услуги из справочника Ozon, а не по
     * `type_id`. Ожидания сверены с `description` из `/v1/finance/accrual/types`
     * (фикстура `tests/Fixtures/Marketplace/Ozon/accrual_types.json`). До
     * исправления 29 уходил в «Логистику», 45 — в «Доставку силами Ozon», а
     * четыре алиаса вели в статьи партнёров.
     */
    #[DataProvider('ozonServiceTypes')]
    public function testSeededServiceResolvesByOzonNameNotByTypeId(
        int $typeId,
        string $name,
        string $expectedCode,
        string $expectedGroup,
        TransactionType $expectedType,
    ): void {
        /** @var SeedExternalCategoryMappingsAction $seedAction */
        $seedAction = self::getContainer()->get(SeedExternalCategoryMappingsAction::class);
        $seedAction(IngestSource::OZON);

        /** @var OzonAccrualByDayMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayMapper::class);
        $transactions = $mapper->mapForCategoryMetadataRefresh($this->rawRecord(Uuid::uuid7()->toString()), [[
            'accrual_id' => 777100 + $typeId,
            'date' => '2026-06-15',
            'unit_number' => 'unit-1',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => $typeId,
                'name' => $name,
                'accrued' => ['amount' => '-10.00', 'currency' => 'RUB'],
            ],
        ]], recordUnknownCategories: false);

        self::assertCount(1, $transactions);
        self::assertSame($expectedCode, $transactions[0]->sourceData['_ozon_category_code']);
        self::assertSame($expectedGroup, $transactions[0]->sourceData['_ozon_category_group']);
        self::assertTrue($transactions[0]->sourceData['_ozon_category_known']);
        self::assertSame($expectedType, $transactions[0]->type);
    }

    /**
     * @return iterable<string, array{int, string, string, string, TransactionType}>
     */
    public static function ozonServiceTypes(): iterable
    {
        yield '29 LastMileCourier' => [29, 'LastMileCourier', 'ozon_delivery_to_pickup_partners', 'Услуги партнёров', TransactionType::LAST_MILE];
        yield '45 PickUpPointReturnAcceptance' => [45, 'PickUpPointReturnAcceptance', 'ozon_partner_return_processing', 'Услуги партнёров', TransactionType::FEE];
        yield '71 SellerReturns' => [71, 'SellerReturns', 'ozon_warehouse_export', 'Услуги FBO', TransactionType::STORAGE];
        yield '46 Placements' => [46, 'Placements', 'ozon_warehouse_placement', 'Услуги FBO', TransactionType::STORAGE];
        yield '84 ItemPacking' => [84, 'ItemPacking', 'ozon_warehouse_additional_packaging', 'Услуги FBO', TransactionType::FEE];
        yield '78 TemporaryPlacement' => [78, 'TemporaryPlacement', 'ozon_fbs_return_short_placement', 'Услуги FBO', TransactionType::STORAGE];
        yield '77 SupplyInbound' => [77, 'SupplyInbound', 'ozon_cargo_place_item_processing', 'Услуги FBO', TransactionType::ACCEPTANCE];
        yield '76 StockInsurance' => [76, 'StockInsurance', 'ozon_stock_insurance', 'Другие услуги и штрафы', TransactionType::FEE];
        yield '32 Logistic' => [32, 'Logistic', 'ozon_logistics', 'Услуги доставки', TransactionType::LOGISTICS];
    }

    public function testSeedCreatesNoTypeIdKeys(): void
    {
        /** @var SeedExternalCategoryMappingsAction $seedAction */
        $seedAction = self::getContainer()->get(SeedExternalCategoryMappingsAction::class);
        $seedAction(IngestSource::OZON);

        $typeKeys = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM ingest_external_categories WHERE source = 'ozon' AND normalized_key LIKE 'type:%'",
        );

        self::assertSame(0, $typeKeys);
    }

    public function testServiceWithoutKnownNameStaysUnknownInsteadOfMatchingByTypeId(): void
    {
        $transactions = $this->mapNonItemFee(29, null);

        self::assertCount(1, $transactions);
        self::assertFalse($transactions[0]->sourceData['_ozon_category_known']);
        self::assertSame('ozon_unknown_29', $transactions[0]->sourceData['_ozon_category_code']);
    }

    public function testMetadataRefreshCanMapUnknownFeeWithoutRecordingCategory(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rawRecord = $this->rawRecord($companyId);

        /** @var OzonAccrualByDayMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayMapper::class);
        $transactions = $mapper->mapForCategoryMetadataRefresh($rawRecord, [[
            'accrual_id' => 777001,
            'date' => '2026-06-15',
            'unit_number' => 'unit-1',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => 777,
                'name' => 'NewUnknownFee',
                'accrued' => ['amount' => '-10.00', 'currency' => 'RUB'],
            ],
        ]], recordUnknownCategories: false);

        self::assertCount(1, $transactions);
        $this->em->flush();

        self::assertNull($this->findExternalCategory(
            OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            'type:777',
        ));
    }

    public function testSemanticOzonCodeTakesPrecedenceOverTypeId(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rawRecord = $this->rawRecord($companyId);

        /** @var OzonAccrualByDayMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayMapper::class);
        $transactions = $mapper->mapForCategoryMetadataRefresh($rawRecord, [[
            'accrual_id' => 777003,
            'date' => '2026-06-15',
            'unit_number' => 'unit-1',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => 77,
                'name' => 'ozon_warehouse_export',
                'accrued' => ['amount' => '-10.00', 'currency' => 'RUB'],
            ],
        ]], recordUnknownCategories: false);

        self::assertCount(1, $transactions);
        self::assertSame('ozon_warehouse_export', $transactions[0]->sourceData['_ingestion_external_code']);
        self::assertNull($transactions[0]->sourceData['_ingestion_provider_label']);
        self::assertSame('ozon_warehouse_export', $transactions[0]->sourceData['_ozon_category_code']);
        self::assertSame('Вывоз товара со склада силами Ozon', $transactions[0]->sourceData['_ozon_category_label']);
        self::assertTrue($transactions[0]->sourceData['_ozon_category_known']);
    }

    public function testCompanyOverrideTakesPrecedenceOverDefaultMapping(): void
    {
        $normalizedKey = OzonAccrualCategoryTaxonomyResolver::codeKey('CustomOzonFee');
        self::assertNotNull($normalizedKey);

        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            normalizedKey: $normalizedKey,
            externalCode: 'CustomOzonFee',
            externalName: 'CustomOzonFee',
            providerLabel: 'Custom Ozon fee',
            displayLabel: 'Custom Ozon fee',
            status: ExternalCategoryStatus::MAPPED,
        );
        $companyId = Uuid::uuid7()->toString();
        $otherCompanyId = Uuid::uuid7()->toString();

        $this->em->persist($category);
        $this->em->persist(new ExternalCategoryMapping(
            externalCategory: $category,
            canonicalCode: 'ozon_other_services',
            canonicalLabel: 'Другие услуги',
            canonicalGroup: 'Другие услуги и штрафы',
            transactionType: TransactionType::FEE,
            sortOrder: 1090,
            status: ExternalCategoryMappingStatus::ACTIVE,
        ));
        $this->em->persist(new ExternalCategoryCompanyMapping(
            externalCategory: $category,
            companyId: $companyId,
            canonicalCode: 'ozon_cpc',
            canonicalLabel: 'Оплата за клик',
            canonicalGroup: 'Продвижение и реклама',
            transactionType: TransactionType::ADVERTISING,
            sortOrder: 900,
            status: ExternalCategoryMappingStatus::ACTIVE,
        ));
        $this->em->flush();

        /** @var OzonAccrualByDayMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayMapper::class);

        $companyTransactions = $mapper->mapForCategoryMetadataRefresh($this->rawRecord($companyId), [$this->customFeeRow()], recordUnknownCategories: false);
        $otherCompanyTransactions = $mapper->mapForCategoryMetadataRefresh($this->rawRecord($otherCompanyId), [$this->customFeeRow()], recordUnknownCategories: false);

        self::assertCount(1, $companyTransactions);
        self::assertCount(1, $otherCompanyTransactions);
        self::assertSame('ozon_cpc', $companyTransactions[0]->sourceData['_ozon_category_code']);
        self::assertSame('Продвижение и реклама', $companyTransactions[0]->sourceData['_ozon_category_group']);
        self::assertSame('ozon_other_services', $otherCompanyTransactions[0]->sourceData['_ozon_category_code']);
        self::assertSame('Другие услуги и штрафы', $otherCompanyTransactions[0]->sourceData['_ozon_category_group']);
    }

    public function testCompanyAnyOverrideTakesPrecedenceOverScopedDefaultMapping(): void
    {
        $normalizedKey = OzonAccrualCategoryTaxonomyResolver::codeKey('ScopedCustomOzonFee');
        self::assertNotNull($normalizedKey);

        $scopedCategory = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            normalizedKey: $normalizedKey,
            externalCode: 'ScopedCustomOzonFee',
            externalName: 'ScopedCustomOzonFee',
            providerLabel: 'Scoped custom Ozon fee',
            displayLabel: 'Scoped custom Ozon fee',
            status: ExternalCategoryStatus::MAPPED,
        );
        $anyCategory = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
            normalizedKey: $normalizedKey,
            externalCode: 'ScopedCustomOzonFee',
            externalName: 'ScopedCustomOzonFee',
            providerLabel: 'Scoped custom Ozon fee',
            displayLabel: 'Scoped custom Ozon fee',
            status: ExternalCategoryStatus::MAPPED,
        );
        $companyId = Uuid::uuid7()->toString();
        $otherCompanyId = Uuid::uuid7()->toString();

        $this->em->persist($scopedCategory);
        $this->em->persist($anyCategory);
        $this->em->persist(new ExternalCategoryMapping(
            externalCategory: $scopedCategory,
            canonicalCode: 'ozon_other_services',
            canonicalLabel: 'Другие услуги',
            canonicalGroup: 'Другие услуги и штрафы',
            transactionType: TransactionType::FEE,
            sortOrder: 1090,
            status: ExternalCategoryMappingStatus::ACTIVE,
        ));
        $this->em->persist(new ExternalCategoryCompanyMapping(
            externalCategory: $anyCategory,
            companyId: $companyId,
            canonicalCode: 'ozon_cpc',
            canonicalLabel: 'Оплата за клик',
            canonicalGroup: 'Продвижение и реклама',
            transactionType: TransactionType::ADVERTISING,
            sortOrder: 900,
            status: ExternalCategoryMappingStatus::ACTIVE,
        ));
        $this->em->flush();

        /** @var OzonAccrualByDayMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayMapper::class);

        $companyTransactions = $mapper->mapForCategoryMetadataRefresh($this->rawRecord($companyId), [$this->customFeeRow('ScopedCustomOzonFee')], recordUnknownCategories: false);
        $otherCompanyTransactions = $mapper->mapForCategoryMetadataRefresh($this->rawRecord($otherCompanyId), [$this->customFeeRow('ScopedCustomOzonFee')], recordUnknownCategories: false);

        self::assertCount(1, $companyTransactions);
        self::assertCount(1, $otherCompanyTransactions);
        self::assertSame('ozon_cpc', $companyTransactions[0]->sourceData['_ozon_category_code']);
        self::assertSame('Продвижение и реклама', $companyTransactions[0]->sourceData['_ozon_category_group']);
        self::assertSame('ozon_other_services', $otherCompanyTransactions[0]->sourceData['_ozon_category_code']);
        self::assertSame('Другие услуги и штрафы', $otherCompanyTransactions[0]->sourceData['_ozon_category_group']);
    }

    public function testZeroAmountTypedFeeDoesNotRecordUnknownCategory(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rawRecord = $this->rawRecord($companyId);

        /** @var OzonAccrualByDayMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayMapper::class);
        $transactions = $mapper->map($rawRecord, [[
            'accrual_id' => 777002,
            'date' => '2026-06-15',
            'unit_number' => 'unit-1',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => 778,
                'name' => 'ZeroUnknownFee',
                'accrued' => ['amount' => '0.00', 'currency' => 'RUB'],
            ],
        ]]);

        self::assertCount(0, $transactions);
        $this->em->flush();

        self::assertNull($this->findExternalCategory(
            OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            'type:778',
        ));
    }

    private function findExternalCategory(string $scope, string $normalizedKey): ?ExternalCategory
    {
        /** @var ExternalCategoryRepository $categoryRepository */
        $categoryRepository = self::getContainer()->get(ExternalCategoryRepository::class);

        return $categoryRepository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            $scope,
            $normalizedKey,
        );
    }

    private function rawRecord(string $companyId): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: $companyId,
            connectionRef: Uuid::uuid7()->toString(),
            shopRef: Uuid::uuid7()->toString(),
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: 'accrual-by-day:2026-06-15:2026-06-15',
            storagePath: 'raw.ndjson.gz',
            hash: str_repeat('a', 64),
            byteSize: 100,
            fetchedAt: new \DateTimeImmutable('2026-06-20 20:35:35'),
            syncJobId: Uuid::uuid7()->toString(),
        );
    }

    /**
     * @return list<MappedTransaction>
     */
    private function mapNonItemFee(int $typeId, ?string $name): array
    {
        $fee = ['type_id' => $typeId, 'accrued' => ['amount' => '-10.00', 'currency' => 'RUB']];
        if (null !== $name) {
            $fee['name'] = $name;
        }

        /** @var OzonAccrualByDayMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayMapper::class);

        return $mapper->mapForCategoryMetadataRefresh($this->rawRecord(Uuid::uuid7()->toString()), [[
            'accrual_id' => 777200 + $typeId,
            'date' => '2026-06-15',
            'unit_number' => 'unit-1',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => $fee,
        ]], recordUnknownCategories: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function customFeeRow(string $code = 'CustomOzonFee'): array
    {
        return [
            'accrual_id' => 777004,
            'date' => '2026-06-15',
            'unit_number' => 'unit-1',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => 999001,
                'code' => $code,
                'name' => $code,
                'accrued' => ['amount' => '-10.00', 'currency' => 'RUB'],
            ],
        ];
    }
}
