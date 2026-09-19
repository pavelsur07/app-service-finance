<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAccrualNormalizeStoredCommandTest extends IntegrationTestCase
{
    public function testDryRunExcludesDoneRecordsUnlessExplicitlyIncluded(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-01:2026-06-07',
            fetchedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'),
            rows: [$this->postingRow()],
        );
        $record->markNormalizationDone();
        $this->em->flush();

        $defaultTester = $this->tester();
        $defaultExit = $defaultTester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--shop-ref' => $connectionRef,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $defaultExit);
        self::assertStringNotContainsString('accrual-by-day:2026-06-01:2026-06-07', $defaultTester->getDisplay());
        self::assertSame(RawNormalizationStatus::DONE, $this->rawStatus($record));

        $includeDoneTester = $this->tester();
        $includeDoneExit = $includeDoneTester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--shop-ref' => $connectionRef,
            '--include-done' => true,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $includeDoneExit);
        self::assertStringContainsString('accrual-by-day:2026-06-01:2026-06-07', $includeDoneTester->getDisplay());
        self::assertSame(RawNormalizationStatus::DONE, $this->rawStatus($record));
    }

    public function testDispatchRejectsDoneReplayBecauseQueueWouldLoseReplayIntent(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => Uuid::uuid7()->toString(),
            '--from' => '2026-06-21',
            '--to' => '2026-06-21',
            '--include-done' => true,
            '--dispatch' => true,
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('--include-done requires --execute-inline', $tester->getDisplay());
    }

    public function testExecuteInlineCanReplayDoneRecordAndInsertMissingBonusWithoutDuplicates(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-01:2026-06-07',
            fetchedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'),
            rows: [$this->postingRow()],
        );
        $operationGroupId = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, '53675409100'))->toString();
        $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $record->getId(),
            operationGroupId: $operationGroupId,
            externalId: 'ozon:accrual-by-day:53675409100:sale:product-0',
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            amountMinor: 10000,
            occurredAt: new \DateTimeImmutable('2026-06-01 00:00:00+03:00'),
        );
        $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $record->getId(),
            operationGroupId: $operationGroupId,
            externalId: 'ozon:accrual-by-day:53675409100:commission:product-0',
            type: TransactionType::COMMISSION,
            direction: TransactionDirection::OUT,
            amountMinor: 3000,
            occurredAt: new \DateTimeImmutable('2026-06-01 00:00:00+03:00'),
        );
        $record->markNormalizationDone();
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--shop-ref' => $connectionRef,
            '--include-done' => true,
            '--execute-inline' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(RawNormalizationStatus::DONE, $this->rawStatusById($companyId, $record->getId()));
        self::assertSame(3, $this->transactionCount($companyId, $record->getId()));
        self::assertSame(1, $this->transactionCountByType($companyId, $record->getId(), TransactionType::SALE));
        self::assertSame(1, $this->transactionCountByType($companyId, $record->getId(), TransactionType::COMMISSION));
        self::assertSame(1, $this->transactionCountByType($companyId, $record->getId(), TransactionType::BONUS));
        self::assertSame(0, $this->duplicateNaturalKeyCount($companyId));
        self::assertSame(0, $this->openIssueCount($companyId, $record->getId()));
        self::assertStringContainsString('Normalized 1 Ozon accrual raw records inline.', $tester->getDisplay());
    }

    public function testExecuteInlineVoidsSameRawComponentWhenCategoryTypeChanges(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-08-01:2026-08-01',
            fetchedAt: new \DateTimeImmutable('2026-09-01 10:00:00+00:00'),
            rows: [[
                'accrual_id' => 53675409201,
                'date' => '2026-08-01',
                'accrued_category' => 'NON_ITEM',
                'non_item_fee' => [
                    'type_id' => 1042,
                    'name' => 'LabelBrandVerified',
                    'accrued' => ['amount' => '-1500.00', 'currency' => 'RUB'],
                ],
            ]],
        );
        $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $record->getId(),
            operationGroupId: Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, '53675409201'))->toString(),
            externalId: 'ozon:accrual-by-day:53675409201:non_item_fee:type-1042',
            type: TransactionType::OTHER,
            direction: TransactionDirection::OUT,
            amountMinor: 150000,
            occurredAt: new \DateTimeImmutable('2026-08-01 00:00:00+03:00'),
        );
        $record->markNormalizationDone();
        $this->em->flush();

        $pendingTransitionListener = new class {
            public int $scheduledPendingTransitions = 0;

            public function onFlush(OnFlushEventArgs $event): void
            {
                foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof IngestRawRecord && RawNormalizationStatus::PENDING === $entity->getNormalizationStatus()) {
                        ++$this->scheduledPendingTransitions;
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $pendingTransitionListener);

        try {
            $tester = $this->tester();
            $exit = $tester->execute([
                '--company-id' => $companyId,
                '--from' => '2026-08-01',
                '--to' => '2026-08-01',
                '--shop-ref' => $connectionRef,
                '--include-done' => true,
                '--execute-inline' => true,
            ]);
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $pendingTransitionListener);
        }

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(0, $pendingTransitionListener->scheduledPendingTransitions);
        self::assertSame(1, $this->transactionCountByType($companyId, $record->getId(), TransactionType::OTHER));
        self::assertSame(1, $this->transactionCountByType($companyId, $record->getId(), TransactionType::FEE));
        self::assertSame(
            '0',
            (string) $this->connection->fetchOne(
                'SELECT amount_minor
                 FROM ingest_financial_transactions
                 WHERE company_id = :companyId
                   AND raw_record_id = :rawRecordId
                   AND type = :type',
                [
                    'companyId' => $companyId,
                    'rawRecordId' => $record->getId(),
                    'type' => TransactionType::OTHER->value,
                ],
            ),
        );
        self::assertSame(0, $this->duplicateNaturalKeyCount($companyId));
        self::assertSame(0, $this->openIssueCount($companyId, $record->getId()));
    }

    public function testFailedDoneReplayReturnsFailureWithoutDiscardingPreviouslyDoneStatus(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-08-01:2026-08-01',
            fetchedAt: new \DateTimeImmutable('2026-09-01 10:00:00+00:00'),
            rows: [[
                'accrual_id' => 53675409201,
                'date' => 'not-a-date',
                'accrued_category' => 'NON_ITEM',
                'non_item_fee' => [
                    'type_id' => 1042,
                    'name' => 'LabelBrandVerified',
                    'accrued' => ['amount' => '-1500.00', 'currency' => 'RUB'],
                ],
            ]],
        );
        $record->markNormalizationDone();
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-08-01',
            '--to' => '2026-08-01',
            '--shop-ref' => $connectionRef,
            '--include-done' => true,
            '--execute-inline' => true,
        ]);

        self::assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        self::assertSame(RawNormalizationStatus::DONE, $this->rawStatus($record));
        self::assertSame(1, $this->openIssueCount($companyId, $record->getId()));
        self::assertStringContainsString('non-done raw records', $tester->getDisplay());
    }

    public function testExecuteInlinePrunesStaleTransactionsFromOlderOverlappingRaw(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $oldRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-22:2026-06-28',
            fetchedAt: new \DateTimeImmutable('2026-07-02 03:00:00+00:00'),
            rows: [$this->postingRow('2026-06-25', 111111111)],
        );
        $oldRecord->markNormalizationDone();
        $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $oldRecord->getId(),
            operationGroupId: Uuid::uuid7()->toString(),
            externalId: 'ozon:accrual-by-day:111111111:sale:product-0',
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            amountMinor: 240200,
            occurredAt: new \DateTimeImmutable('2026-06-25 00:00:00+03:00'),
        );

        $newRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-23:2026-06-29',
            fetchedAt: new \DateTimeImmutable('2026-07-03 03:00:00+00:00'),
            rows: [$this->postingRow('2026-06-25', 222222222)],
        );
        $newRecord->markNormalizationDone();
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-23',
            '--to' => '2026-06-29',
            '--shop-ref' => $connectionRef,
            '--include-done' => true,
            '--execute-inline' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(0, $this->transactionCountByExternalId($companyId, 'ozon:accrual-by-day:111111111:sale:product-0'));
        self::assertSame(3, $this->transactionCount($companyId, $newRecord->getId()));
    }

    /**
     * Снапшот покрывает несколько дней, но авторитетом является лишь для части:
     * на остальные есть более свежий. Реплей обязан записать только «свои» дни,
     * иначе на чужих днях рядом с актуальной строкой появляется вторая — ровно
     * так на проде 18.09.2026 удвоились восемь строк за 25.06.
     */
    public function testExecuteInlineReplaysOnlyDaysTheSnapshotOwns(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();

        $sharedDayRow = [
            'accrual_id' => 222222222,
            'date' => '2026-06-25',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => 1042,
                'name' => 'LabelBrandVerified',
                'accrued' => ['amount' => '-1500.00', 'currency' => 'RUB'],
            ],
        ];

        $oldRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-19:2026-06-25',
            fetchedAt: new \DateTimeImmutable('2026-06-26 03:00:00+00:00'),
            rows: [
                $this->postingRow('2026-06-24', 111111111),
                $sharedDayRow,
            ],
        );
        $oldRecord->markNormalizationDone();

        $newRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-25:2026-07-01',
            fetchedAt: new \DateTimeImmutable('2026-07-02 03:00:00+00:00'),
            rows: [$sharedDayRow],
        );
        $newRecord->markNormalizationDone();

        // Канон общего дня принадлежит свежему снапшоту и типизирован ПРЕЖНИМ
        // маппером — как на проде до 07.09. Текущий маппер на том же сырье даёт
        // FEE, поэтому естественный ключ не совпадёт и upsert создаст ВТОРУЮ
        // строку, если реплей старого снапшота полезет в этот день.
        $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $newRecord->getId(),
            operationGroupId: Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, '222222222'))->toString(),
            externalId: 'ozon:accrual-by-day:222222222:non_item_fee:type-1042',
            type: TransactionType::OTHER,
            direction: TransactionDirection::OUT,
            amountMinor: 150000,
            occurredAt: new \DateTimeImmutable('2026-06-25 00:00:00+03:00'),
        );
        $this->em->flush();

        // Реплей старого снапшота: он авторитет только на 24-е.
        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-24',
            '--to' => '2026-06-24',
            '--shop-ref' => $connectionRef,
            '--include-done' => true,
            '--execute-inline' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        // Главное: на общем дне по-прежнему ОДНА строка, а не две.
        self::assertSame(
            1,
            $this->transactionCountByExternalId($companyId, 'ozon:accrual-by-day:222222222:non_item_fee:type-1042'),
        );
        // И она осталась за свежим снапшотом, нетронутой.
        self::assertSame(
            $newRecord->getId(),
            (string) $this->connection->fetchOne(
                'SELECT raw_record_id FROM ingest_financial_transactions WHERE company_id = :companyId AND external_id = :externalId',
                ['companyId' => $companyId, 'externalId' => 'ozon:accrual-by-day:222222222:non_item_fee:type-1042'],
            ),
        );
        // Свой день старый снапшот разобрал: три строки постинга за 24-е.
        self::assertSame(3, $this->transactionCount($companyId, $oldRecord->getId()));
        self::assertSame(0, $this->duplicateNaturalKeyCount($companyId));
    }

    /**
     * Обратная сторона ограничения по дням: строки снапшота за дни ВНЕ окна
     * гасить нельзя — они живые и принадлежат этому же сырью. Гасилка смотрит
     * на набор разобранных строк, и без такого же ограничения суженный набор
     * выглядит для неё как «из выгрузки всё пропало». Так 18.09.2026 на проде
     * обнулились 19 дней, 26 496 строк.
     */
    public function testExecuteInlineKeepsRowsOfDaysOutsideTheRestrictionAlive(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();

        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-24:2026-06-25',
            fetchedAt: new \DateTimeImmutable('2026-06-26 03:00:00+00:00'),
            rows: [
                $this->postingRow('2026-06-24', 111111111),
                $this->postingRow('2026-06-25', 222222222),
            ],
        );
        $record->markNormalizationDone();
        $this->em->flush();

        // Полный разбор: оба дня на месте.
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-24',
            '--to' => '2026-06-25',
            '--shop-ref' => $connectionRef,
            '--include-done' => true,
            '--execute-inline' => true,
        ]), $tester->getDisplay());

        self::assertSame(6, $this->transactionCount($companyId, $record->getId()));

        // Чиним только 24-е. 25-е трогать нельзя.
        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-24',
            '--to' => '2026-06-24',
            '--shop-ref' => $connectionRef,
            '--include-done' => true,
            '--execute-inline' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(6, $this->transactionCount($companyId, $record->getId()));
        self::assertSame(0, $this->voidedTransactionCount($companyId, $record->getId()));
    }

    private function voidedTransactionCount(string $companyId, string $rawRecordId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM ingest_financial_transactions
             WHERE company_id = :companyId
               AND raw_record_id = :rawRecordId
               AND source_data->>'_ingestion_voided' = 'true'",
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:ozon-accrual:normalize-stored'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeRawRecord(
        string $companyId,
        string $connectionRef,
        string $externalId,
        \DateTimeImmutable $fetchedAt,
        array $rows,
    ): IngestRawRecord {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        return $facade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt,
            rows: $rows,
        ))[0];
    }

    private function persistExistingTransaction(
        string $companyId,
        string $connectionRef,
        string $rawRecordId,
        string $operationGroupId,
        string $externalId,
        TransactionType $type,
        TransactionDirection $direction,
        int $amountMinor,
        \DateTimeImmutable $occurredAt,
    ): void {
        $this->em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'),
            operationGroupId: $operationGroupId,
            type: $type,
            direction: $direction,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: $occurredAt,
            rawRecordId: $rawRecordId,
            description: 'Existing Ozon accrual transaction',
            sourceData: [],
            sourceTz: 'Europe/Moscow',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function postingRow(string $date = '2026-06-01', int $accrualId = 53675409100): array
    {
        return [
            'accrual_id' => $accrualId,
            'date' => $date,
            'unit_number' => '41774559-0885-1',
            'accrued_category' => 'POSTING',
            'posting' => [
                'products' => [[
                    'commission' => [
                        'sale_amount' => ['amount' => '100.00', 'currency' => 'RUB'],
                        'bonus' => ['amount' => '20.00', 'currency' => 'RUB'],
                        'commission' => ['amount' => '-30.00', 'currency' => 'RUB'],
                    ],
                ]],
            ],
        ];
    }

    private function rawStatus(IngestRawRecord $record): RawNormalizationStatus
    {
        return $this->rawStatusById($record->getCompanyId(), $record->getId());
    }

    private function rawStatusById(string $companyId, string $rawRecordId): RawNormalizationStatus
    {
        /** @var IngestRawRecordRepository $repository */
        $repository = self::getContainer()->get(IngestRawRecordRepository::class);

        return $repository->findByIdAndCompany($rawRecordId, $companyId)?->getNormalizationStatus()
            ?? throw new \RuntimeException('Raw record was not found.');
    }

    private function transactionCount(string $companyId, string $rawRecordId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_financial_transactions WHERE company_id = :companyId AND raw_record_id = :rawRecordId',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );
    }

    private function transactionCountByType(string $companyId, string $rawRecordId, TransactionType $type): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_financial_transactions WHERE company_id = :companyId AND raw_record_id = :rawRecordId AND type = :type',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId, 'type' => $type->value],
        );
    }

    private function duplicateNaturalKeyCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM (
                 SELECT external_id, type
                 FROM ingest_financial_transactions
                 WHERE company_id = :companyId
                   AND source = :source
                 GROUP BY external_id, type
                 HAVING COUNT(*) > 1
             ) duplicate_keys',
            ['companyId' => $companyId, 'source' => IngestSource::OZON->value],
        );
    }

    private function transactionCountByExternalId(string $companyId, string $externalId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_financial_transactions WHERE company_id = :companyId AND external_id = :externalId',
            ['companyId' => $companyId, 'externalId' => $externalId],
        );
    }

    private function openIssueCount(string $companyId, string $rawRecordId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :companyId AND raw_record_id = :rawRecordId AND resolved_at IS NULL',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );
    }
}
