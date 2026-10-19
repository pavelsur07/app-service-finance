<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\ImportOzonAccrualDetailsAction;
use App\Ingestion\Application\DTO\OzonAccrualDetailsImportResult;
use App\Ingestion\Application\DTO\OzonAccrualDetailsRow;
use App\Ingestion\Application\DTO\OzonAccrualDetailsTypeResult;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class ImportOzonAccrualDetailsActionTest extends IntegrationTestCase
{
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = Uuid::uuid7()->toString();
    }

    public function testConfirmsGroupByDateSkuAndAmount(): void
    {
        $this->byDay('2026-08-01', '111', 17400, 76);
        $this->byDay('2026-08-02', '111', 5000, 76);
        $this->byDay('2026-08-03', '222', 7000, 76);

        $result = $this->import([
            $this->row('2026-08-01', '111', 17400, 'Услуги партнёров', 'Страхование товара от массовых повреждений'),
            $this->row('2026-08-02', '111', 5000, 'Услуги партнёров', 'Страхование товара от массовых повреждений'),
            $this->row('2026-08-03', '222', 7000, 'Услуги партнёров', 'Страхование товара от массовых повреждений'),
        ]);

        $type = $this->single($result);
        self::assertSame(OzonAccrualDetailsTypeResult::CONFIRMED, $type->outcome);
        self::assertSame(76, $type->typeId);
        self::assertSame('partners', $type->groupCode);
        self::assertSame(['partners', 'confirmed', 3, 3], $this->stored(76));
    }

    public function testAmbiguousRowsDoNotVoteAndOzonGroupLabelMapsToOurCode(): void
    {
        // Две разные услуги с одной суммой в один день: эта строка не голосует.
        $this->byDay('2026-08-01', null, 1000, 10);
        $this->byDay('2026-08-01', null, 1000, 25);
        $this->byDay('2026-08-02', null, 2000, 10);
        $this->byDay('2026-08-03', null, 3000, 10);
        $this->byDay('2026-08-04', null, 4000, 10);

        $result = $this->import([
            $this->row('2026-08-01', null, 1000, 'Компенсации и декомпенсации', 'Компенсация'),
            $this->row('2026-08-02', null, 2000, 'Компенсации и декомпенсации', 'Компенсация'),
            $this->row('2026-08-03', null, 3000, 'Компенсации и декомпенсации', 'Компенсация'),
            $this->row('2026-08-04', null, 4000, 'Компенсации и декомпенсации', 'Компенсация'),
            $this->row('2026-08-05', null, 9999, 'Компенсации и декомпенсации', 'Компенсация'),
        ]);

        $type = $this->single($result);
        self::assertSame(OzonAccrualDetailsTypeResult::CONFIRMED, $type->outcome);
        self::assertSame(10, $type->typeId);
        self::assertSame('compensation', $type->groupCode);
        self::assertSame(3, $type->votes);
        self::assertSame(5, $type->rowsTotal);
    }

    public function testLowShareIsConflictAndDoesNotOverrideEarlierConfirmation(): void
    {
        $this->byDay('2026-08-01', '1', 100, 52);
        $this->byDay('2026-08-02', '1', 200, 52);
        $this->byDay('2026-08-03', '1', 300, 52);
        $this->import([
            $this->row('2026-08-01', '1', 100, 'Продвижение и реклама', 'Подписка Premium'),
            $this->row('2026-08-02', '1', 200, 'Продвижение и реклама', 'Подписка Premium'),
            $this->row('2026-08-03', '1', 300, 'Продвижение и реклама', 'Подписка Premium'),
        ]);

        $result = $this->import([
            $this->row('2026-08-01', '1', 100, 'Другие услуги и штрафы', 'Подписка Premium'),
            $this->row('2026-08-10', '1', 555, 'Другие услуги и штрафы', 'Подписка Premium'),
            $this->row('2026-08-11', '1', 666, 'Другие услуги и штрафы', 'Подписка Premium'),
        ]);

        self::assertSame(OzonAccrualDetailsTypeResult::KEPT, $this->single($result)->outcome);
        self::assertSame(['promotion', 'confirmed', 3, 3], $this->stored(52));
    }

    public function testDifferentGroupForConfirmedTypeIsDisputedNotOverwritten(): void
    {
        $this->byDay('2026-08-01', '1', 100, 74);
        $this->byDay('2026-08-02', '1', 200, 74);
        $this->import([
            $this->row('2026-08-01', '1', 100, 'Продвижение и реклама', 'Звёздные товары'),
            $this->row('2026-08-02', '1', 200, 'Продвижение и реклама', 'Звёздные товары'),
        ]);

        $result = $this->import([
            $this->row('2026-08-01', '1', 100, 'Услуги партнёров', 'Звёздные товары'),
            $this->row('2026-08-02', '1', 200, 'Услуги партнёров', 'Звёздные товары'),
        ]);

        self::assertSame(OzonAccrualDetailsTypeResult::DISPUTED, $this->single($result)->outcome);
        self::assertSame(['promotion', 'confirmed', 2, 2], $this->stored(74));
        self::assertSame('partners', $this->connection->fetchOne('SELECT disputed_group_code FROM ingest_ozon_accrual_type_groups WHERE type_id = 74'));
    }

    /**
     * Повторное подтверждение той же группы обновляет доказательства, но открытый
     * спор не снимает: другой отчёт по-прежнему противоречит, решает админ.
     */
    public function testSameGroupAgainRefreshesEvidenceAndKeepsOpenDispute(): void
    {
        $this->byDay('2026-08-01', '1', 100, 74);
        $this->byDay('2026-08-02', '1', 200, 74);
        $this->byDay('2026-08-03', '1', 300, 74);
        $partners = fn (string $date, int $amount): OzonAccrualDetailsRow => $this->row($date, '1', $amount, 'Услуги партнёров', 'Звёздные товары');
        $this->import([$partners('2026-08-01', 100), $partners('2026-08-02', 200)]);
        $this->import([
            $this->row('2026-08-01', '1', 100, 'Продвижение и реклама', 'Звёздные товары'),
            $this->row('2026-08-02', '1', 200, 'Продвижение и реклама', 'Звёздные товары'),
        ]);

        $this->import([$partners('2026-08-01', 100), $partners('2026-08-02', 200), $partners('2026-08-03', 300)]);

        self::assertSame(['partners', 'confirmed', 3, 3], $this->stored(74));
        self::assertSame('promotion', $this->connection->fetchOne('SELECT disputed_group_code FROM ingest_ozon_accrual_type_groups WHERE type_id = 74'));
    }

    public function testWeakPairDoesNotDowngradeStrongConfirmationOfSameType(): void
    {
        $this->byDay('2026-08-01', '1', 100, 32);
        $this->byDay('2026-08-02', '1', 200, 32);
        $this->byDay('2026-08-03', '1', 300, 32);
        $this->byDay('2026-08-04', '1', 400, 32);

        $result = $this->import([
            $this->row('2026-08-01', '1', 100, 'Услуги доставки', 'Логистика'),
            $this->row('2026-08-02', '1', 200, 'Услуги доставки', 'Логистика'),
            $this->row('2026-08-03', '1', 300, 'Услуги доставки', 'Логистика'),
            $this->row('2026-08-04', '1', 400, 'Услуги FBO', 'Логистика: корректировка'),
        ]);

        $outcomes = array_map(static fn (OzonAccrualDetailsTypeResult $type): string => $type->outcome, $result->types);
        sort($outcomes);
        self::assertSame([OzonAccrualDetailsTypeResult::CONFIRMED, OzonAccrualDetailsTypeResult::WEAK], $outcomes);
        self::assertSame(['delivery', 'confirmed', 3, 3], $this->stored(32));
    }

    /**
     * Продажа без type_id на том же ключе (дата, SKU, сумма), что и услуга: строки
     * продаж из отчёта не должны голосовать за тип услуги.
     */
    public function testUntypedComponentOnSameKeyMakesMatchAmbiguous(): void
    {
        foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $date) {
            $this->byDay($date, '1', 100, 74);
            $this->byDay($date, '1', 100, null);
        }

        $result = $this->import([
            $this->row('2026-08-01', '1', 100, 'Услуги партнёров', 'Продажа'),
            $this->row('2026-08-02', '1', 100, 'Услуги партнёров', 'Продажа'),
            $this->row('2026-08-03', '1', 100, 'Услуги партнёров', 'Продажа'),
        ]);

        self::assertSame(OzonAccrualDetailsTypeResult::UNMATCHED, $this->single($result)->outcome);
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM ingest_ozon_accrual_type_groups WHERE type_id = 74'));
    }

    public function testSingleRowIsNotEnoughToConfirm(): void
    {
        $this->byDay('2026-08-01', '1', 100, 76);

        $result = $this->import([$this->row('2026-08-01', '1', 100, 'Услуги партнёров', 'Страхование')]);

        self::assertSame(OzonAccrualDetailsTypeResult::CONFLICT, $this->single($result)->outcome);
        self::assertSame('conflict', $this->stored(76)[1]);
    }

    public function testRowsWithoutTypeAndUnknownGroupsAreReported(): void
    {
        $this->byDay('2026-08-01', '1', 100, 32);

        $result = $this->import([
            $this->row('2026-08-01', '9', 777, 'Продажи', 'Выручка'),
            $this->row('2026-08-01', '1', 100, 'Новая группа Ozon', 'Логистика'),
        ]);

        self::assertCount(1, $result->withOutcome(OzonAccrualDetailsTypeResult::UNMATCHED));
        self::assertCount(1, $result->withOutcome(OzonAccrualDetailsTypeResult::UNKNOWN_GROUP));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_ozon_accrual_type_groups'));
    }

    public function testDryRunWritesNothing(): void
    {
        $this->byDay('2026-08-01', '1', 100, 76);
        $this->byDay('2026-08-02', '1', 200, 76);

        $result = $this->import([
            $this->row('2026-08-01', '1', 100, 'Услуги партнёров', 'Страхование'),
            $this->row('2026-08-02', '1', 200, 'Услуги партнёров', 'Страхование'),
        ], dryRun: true);

        self::assertSame(OzonAccrualDetailsTypeResult::CONFIRMED, $this->single($result)->outcome);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_ozon_accrual_type_groups'));
    }

    public function testOtherCompanyByDayIsNotUsed(): void
    {
        $other = $this->companyId;
        $this->byDay('2026-08-01', '1', 100, 76);
        $this->companyId = Uuid::uuid7()->toString();

        $result = $this->import([$this->row('2026-08-01', '1', 100, 'Услуги партнёров', 'Страхование')]);

        self::assertNotSame($other, $this->companyId);
        self::assertSame(OzonAccrualDetailsTypeResult::UNMATCHED, $this->single($result)->outcome);
    }

    /**
     * @param list<OzonAccrualDetailsRow> $rows
     */
    private function import(array $rows, bool $dryRun = false): OzonAccrualDetailsImportResult
    {
        /** @var ImportOzonAccrualDetailsAction $action */
        $action = self::getContainer()->get(ImportOzonAccrualDetailsAction::class);
        $result = $action($this->companyId, $rows, $dryRun);
        $this->em->clear();

        return $result;
    }

    private function single(OzonAccrualDetailsImportResult $result): OzonAccrualDetailsTypeResult
    {
        self::assertCount(1, $result->types);

        return $result->types[0];
    }

    private function row(string $date, ?string $sku, int $amountMinor, string $group, string $type): OzonAccrualDetailsRow
    {
        return new OzonAccrualDetailsRow($date, $group, $type, $sku, $amountMinor);
    }

    /**
     * @return array{0: ?string, 1: string, 2: int, 3: int}
     */
    private function stored(int $typeId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT group_code, status, votes, rows_total FROM ingest_ozon_accrual_type_groups WHERE type_id = :typeId',
            ['typeId' => $typeId],
        );
        self::assertIsArray($row);

        return [$row['group_code'], (string) $row['status'], (int) $row['votes'], (int) $row['rows_total']];
    }

    private function byDay(string $date, ?string $sku, int $amountMinor, ?int $typeId): void
    {
        $sourceData = ['_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY];
        if (null !== $typeId) {
            $sourceData['_ingestion_type_id'] = (string) $typeId;
        }
        if (null !== $sku) {
            $sourceData['sku'] = $sku;
        }

        $this->em->persist(new FinancialTransaction(
            companyId: $this->companyId,
            connectionRef: $this->companyId,
            shopRef: $this->companyId,
            source: IngestSource::OZON,
            externalId: sprintf('ozon:accrual-by-day:%s:%s:%d:%s', $date, $sku ?? '-', $amountMinor, $typeId ?? 'sale'),
            externalUpdatedAt: new \DateTimeImmutable('2026-09-01 03:00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(-$amountMinor, 'RUB'),
            occurredAt: new \DateTimeImmutable($date.' 00:00:00'),
            rawRecordId: Uuid::uuid7()->toString(),
            sourceData: $sourceData,
        ));
        $this->em->flush();
    }
}
