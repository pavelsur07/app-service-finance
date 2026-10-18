<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Migration;

use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryCompanyMapping;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261018120000;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

require_once dirname(__DIR__, 4).'/migrations/Version20261018120000.php';

/**
 * Строки повторяют прод на 18.10.2026 (docs/tasks/ozon-accrual-type-mapping-fix/measurements/before-mappings.txt).
 */
final class OzonAccrualTypeMappingMigrationTest extends IntegrationTestCase
{
    public function testDisablesTypeKeysRetargetsServicesAndRollsBack(): void
    {
        $type29 = $this->mapping('any', 'type:29', 'ozon_logistics', 'Логистика', 'Услуги доставки', TransactionType::LOGISTICS, 400);
        $type29Delivery = $this->mapping('delivery', 'type:29', 'ozon_logistics', 'Логистика', 'Услуги доставки', TransactionType::LOGISTICS, 400);
        $type77 = $this->mapping('non_item', 'type:77', 'ozon_cargo_place_item_processing', 'Обработка товара в составе грузоместа', 'Услуги FBO', TransactionType::ACCEPTANCE, 820);
        $sellerReturns = $this->mapping('any', 'code:sellerreturns', 'ozon_partner_return_processing', 'Обработка возвратов, отмен и невыкупов партнёрами', 'Услуги партнёров', TransactionType::FEE, 500);
        $placementsName = $this->mapping('any', 'name:placements', 'ozon_partner_placement', 'Размещение товара партнёрами', 'Услуги партнёров', TransactionType::STORAGE, 550);
        $itemPacking = $this->mapping('any', 'code:itempacking', 'ozon_partner_packaging', 'Упаковка товара партнёрами', 'Услуги партнёров', TransactionType::FEE, 520);
        $temporaryItem = $this->mapping('item', 'code:temporaryplacement', 'ozon_temporary_partner_storage', 'Временное размещение товара партнёрами', 'Услуги партнёров', TransactionType::STORAGE, 540);
        // Контроль: верные ключи и строка с неожиданными значениями не меняются.
        $logistic = $this->mapping('any', 'code:logistic', 'ozon_logistics', 'Логистика', 'Услуги доставки', TransactionType::LOGISTICS, 400);
        $partnerPlacementLabel = $this->mapping('any', 'name:размещение товара партнерами', 'ozon_partner_placement', 'Размещение товара партнёрами', 'Услуги партнёров', TransactionType::STORAGE, 550);
        $placementsCustom = $this->mapping('non_item', 'code:placements', 'ozon_other_services', 'Другие услуги', 'Другие услуги и штрафы', TransactionType::FEE, 1090);
        $this->em->flush();

        $this->applyMigration(static fn (Version20261018120000 $m) => $m->up(new Schema()));

        self::assertSame('disabled', $this->row($type29)['status']);
        self::assertSame('disabled', $this->row($type29Delivery)['status']);
        self::assertSame('disabled', $this->row($type77)['status']);
        self::assertSame(['ozon_warehouse_export', 'Вывоз товара со склада силами Ozon', 'Услуги FBO', 'storage', 700, 'active'], array_values($this->row($sellerReturns)));
        self::assertSame(['ozon_warehouse_placement', 'Размещение товаров на складах', 'Услуги FBO', 'storage', 710, 'active'], array_values($this->row($placementsName)));
        self::assertSame(['ozon_warehouse_additional_packaging', 'Дополнительная упаковка на складе Ozon', 'Услуги FBO', 'fee', 730, 'active'], array_values($this->row($itemPacking)));
        self::assertSame(['ozon_fbs_return_short_placement', 'Краткосрочное размещение возврата FBS', 'Услуги FBO', 'storage', 740, 'active'], array_values($this->row($temporaryItem)));
        self::assertSame(['ozon_logistics', 'Логистика', 'Услуги доставки', 'logistics', 400, 'active'], array_values($this->row($logistic)));
        self::assertSame('ozon_partner_placement', $this->row($partnerPlacementLabel)['canonical_code']);
        self::assertSame('ozon_other_services', $this->row($placementsCustom)['canonical_code']);
        self::assertSame(7, $this->auditCount());

        $this->em->clear();
        self::assertSame('ozon_delivery_to_pickup_partners', $this->categoryCodeFor(29, 'LastMileCourier'));
        self::assertSame('ozon_warehouse_export', $this->categoryCodeFor(71, 'SellerReturns'));
        self::assertSame('ozon_logistics', $this->categoryCodeFor(32, 'Logistic'));

        $this->applyMigration(static fn (Version20261018120000 $m) => $m->down(new Schema()));

        self::assertSame(['ozon_logistics', 'Логистика', 'Услуги доставки', 'logistics', 400, 'active'], array_values($this->row($type29)));
        self::assertSame('active', $this->row($type77)['status']);
        self::assertSame(['ozon_partner_return_processing', 'Обработка возвратов, отмен и невыкупов партнёрами', 'Услуги партнёров', 'fee', 500, 'active'], array_values($this->row($sellerReturns)));
        self::assertSame(['ozon_temporary_partner_storage', 'Временное размещение товара партнёрами', 'Услуги партнёров', 'storage', 540, 'active'], array_values($this->row($temporaryItem)));
        self::assertSame('ozon_other_services', $this->row($placementsCustom)['canonical_code']);
        self::assertSame(0, $this->auditCount());
    }

    public function testManualEditOfAffectedKeyStopsMigration(): void
    {
        $this->mapping('any', 'code:sellerreturns', 'ozon_cpc', 'Оплата за клик', 'Продвижение и реклама', TransactionType::ADVERTISING, 900, updatedBy: Uuid::uuid7()->toString());
        $this->em->flush();

        $this->expectException(AbortMigration::class);

        $this->applyMigration(static fn (Version20261018120000 $m) => $m->up(new Schema()));
    }

    /**
     * Строка, которая уже вела в верную статью до миграции (или которую позже
     * создал исправленный seed), не откатывается в старую статью партнёров:
     * down() трогает только строки со своим аудитом.
     */
    public function testRollbackKeepsMappingThatWasAlreadyCorrect(): void
    {
        $alreadyCorrect = $this->mapping('non_item', 'code:sellerreturns', 'ozon_warehouse_export', 'Вывоз товара со склада силами Ozon', 'Услуги FBO', TransactionType::STORAGE, 700);
        $this->em->flush();

        $this->applyMigration(static fn (Version20261018120000 $m) => $m->up(new Schema()));
        $this->applyMigration(static fn (Version20261018120000 $m) => $m->down(new Schema()));

        self::assertSame(['ozon_warehouse_export', 'Вывоз товара со склада силами Ozon', 'Услуги FBO', 'storage', 700, 'active'], array_values($this->row($alreadyCorrect)));
    }

    public function testCompanyOverrideOfAffectedKeyStopsMigration(): void
    {
        $mapping = $this->mapping('any', 'code:placements', 'ozon_partner_placement', 'Размещение товара партнёрами', 'Услуги партнёров', TransactionType::STORAGE, 550);
        $this->em->persist(new ExternalCategoryCompanyMapping(
            externalCategory: $mapping->getExternalCategory(),
            companyId: Uuid::uuid7()->toString(),
            canonicalCode: 'ozon_partner_placement',
            canonicalLabel: 'Размещение товара партнёрами',
            canonicalGroup: 'Услуги партнёров',
            transactionType: TransactionType::STORAGE,
            sortOrder: 550,
            status: ExternalCategoryMappingStatus::ACTIVE,
        ));
        $this->em->flush();

        $this->expectException(AbortMigration::class);

        $this->applyMigration(static fn (Version20261018120000 $m) => $m->up(new Schema()));
    }

    public function testReportsSkippedRowsWithUnexpectedValues(): void
    {
        $this->mapping('non_item', 'code:placements', 'ozon_other_services', 'Другие услуги', 'Другие услуги и штрафы', TransactionType::FEE, 1090);
        $this->em->flush();

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        (new Version20261018120000($this->connection, $logger))->up(new Schema());

        self::assertStringContainsString('Skipped 1 Ozon mapping(s) for "placements"', implode("\n", $logger->messages));
    }

    /**
     * @param callable(Version20261018120000): void $step
     */
    private function applyMigration(callable $step): void
    {
        $migration = new Version20261018120000($this->connection, new NullLogger());
        $step($migration);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function mapping(
        string $scope,
        string $normalizedKey,
        string $code,
        string $label,
        string $group,
        TransactionType $type,
        int $sortOrder,
        ?string $updatedBy = null,
    ): ExternalCategoryMapping {
        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: $scope,
            normalizedKey: $normalizedKey,
            status: ExternalCategoryStatus::MAPPED,
        );
        $mapping = new ExternalCategoryMapping(
            externalCategory: $category,
            canonicalCode: $code,
            canonicalLabel: $label,
            canonicalGroup: $group,
            transactionType: $type,
            sortOrder: $sortOrder,
            status: ExternalCategoryMappingStatus::ACTIVE,
            updatedBy: $updatedBy,
        );
        $this->em->persist($category);
        $this->em->persist($mapping);

        return $mapping;
    }

    /**
     * @return array{canonical_code: string, canonical_label: string, canonical_group: string, transaction_type: string, sort_order: int, status: string}
     */
    private function row(ExternalCategoryMapping $mapping): array
    {
        /** @var array{canonical_code: string, canonical_label: string, canonical_group: string, transaction_type: string, sort_order: int|string, status: string} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT canonical_code, canonical_label, canonical_group, transaction_type, sort_order, status FROM ingest_external_category_mappings WHERE id = :id',
            ['id' => $mapping->getId()],
        );
        $row['sort_order'] = (int) $row['sort_order'];

        return $row;
    }

    private function auditCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_external_category_mapping_audit WHERE new_values->>'reason' = 'ozon-accrual-type-mapping-fix'",
        );
    }

    private function categoryCodeFor(int $typeId, string $name): string
    {
        /** @var OzonAccrualByDayMapper $mapper */
        $mapper = self::getContainer()->get(OzonAccrualByDayMapper::class);
        $transactions = $mapper->mapForCategoryMetadataRefresh($this->rawRecord(), [[
            'accrual_id' => 818000 + $typeId,
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

        $code = $transactions[0]->sourceData['_ozon_category_code'];
        self::assertIsString($code);

        return $code;
    }

    private function rawRecord(): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: Uuid::uuid7()->toString(),
            shopRef: Uuid::uuid7()->toString(),
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: 'accrual-by-day:2026-06-15:2026-06-15',
            storagePath: 'raw.ndjson.gz',
            hash: str_repeat('b', 64),
            byteSize: 100,
            fetchedAt: new \DateTimeImmutable('2026-06-20 20:35:35'),
            syncJobId: Uuid::uuid7()->toString(),
        );
    }
}
