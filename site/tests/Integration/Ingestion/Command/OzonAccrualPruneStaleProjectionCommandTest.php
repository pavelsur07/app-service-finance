<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAccrualPruneStaleProjectionCommandTest extends IntegrationTestCase
{
    public function testDryRunAndExecutePruneStaleProjectionRows(): void
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
        $newRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-23:2026-06-29',
            fetchedAt: new \DateTimeImmutable('2026-07-03 03:00:00+00:00'),
            rows: [$this->postingRow('2026-06-25', 222222222)],
        );
        $newRecord->markNormalizationDone();
        $newerRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-24:2026-06-30',
            fetchedAt: new \DateTimeImmutable('2026-07-04 03:00:00+00:00'),
            rows: [
                $this->postingRow('2026-06-25', 222222222),
                $this->postingRow('2026-06-25', 333333333),
            ],
        );
        $newerRecord->markNormalizationDone();

        $this->persistTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $oldRecord->getId(),
            externalId: 'ozon:accrual-by-day:111111111:sale:product-0',
            amountMinor: 240200,
        );
        $this->persistTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $newRecord->getId(),
            externalId: 'ozon:accrual-by-day:222222222:sale:product-0',
            amountMinor: 10000,
        );
        $this->persistTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $newerRecord->getId(),
            externalId: 'ozon:accrual-by-day:333333333:sale:product-0',
            amountMinor: 20000,
        );
        $this->em->flush();

        $dryRun = $this->tester();
        $dryRunExit = $dryRun->execute([
            '--company-id' => $companyId,
            '--shop-ref' => $connectionRef,
            '--from' => '2026-06-23',
            '--to' => '2026-06-29',
            '--limit' => '10',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $dryRunExit, $dryRun->getDisplay());
        self::assertStringContainsString('accrual-by-day:2026-06-22:2026-06-28', $dryRun->getDisplay());
        self::assertStringContainsString('2402.00', $dryRun->getDisplay());
        self::assertStringNotContainsString('4804.00', $dryRun->getDisplay());
        self::assertSame(1, substr_count($dryRun->getDisplay(), '2402.00'), $dryRun->getDisplay());
        self::assertSame(1, $this->transactionCountByExternalId($companyId, 'ozon:accrual-by-day:111111111:sale:product-0'));

        $execute = $this->tester();
        $executeExit = $execute->execute([
            '--company-id' => $companyId,
            '--shop-ref' => $connectionRef,
            '--from' => '2026-06-23',
            '--to' => '2026-06-29',
            '--limit' => '10',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $executeExit, $execute->getDisplay());
        self::assertStringContainsString('deleted', $execute->getDisplay());
        self::assertSame(0, $this->transactionCountByExternalId($companyId, 'ozon:accrual-by-day:111111111:sale:product-0'));
        self::assertSame(1, $this->transactionCountByExternalId($companyId, 'ozon:accrual-by-day:222222222:sale:product-0'));
        self::assertSame(1, $this->transactionCountByExternalId($companyId, 'ozon:accrual-by-day:333333333:sale:product-0'));
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:ozon-accrual:prune-stale-projection'));
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

    private function persistTransaction(
        string $companyId,
        string $connectionRef,
        string $rawRecordId,
        string $externalId,
        int $amountMinor,
    ): void {
        $this->em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-07-03 03:00:00+00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-25 00:00:00+03:00'),
            rawRecordId: $rawRecordId,
            description: 'Ozon accrual transaction',
            sourceData: ['_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY],
            sourceTz: 'Europe/Moscow',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function postingRow(string $date, int $accrualId): array
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

    private function transactionCountByExternalId(string $companyId, string $externalId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_financial_transactions WHERE company_id = :companyId AND external_id = :externalId',
            ['companyId' => $companyId, 'externalId' => $externalId],
        );
    }
}
