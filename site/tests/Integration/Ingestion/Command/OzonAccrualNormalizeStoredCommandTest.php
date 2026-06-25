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
    private function postingRow(): array
    {
        return [
            'accrual_id' => 53675409100,
            'date' => '2026-06-01',
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

    private function openIssueCount(string $companyId, string $rawRecordId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :companyId AND raw_record_id = :rawRecordId AND resolved_at IS NULL',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );
    }
}
