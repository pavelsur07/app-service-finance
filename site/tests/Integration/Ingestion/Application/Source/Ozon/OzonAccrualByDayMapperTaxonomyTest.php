<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Action\SeedExternalCategoryMappingsAction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
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
}
