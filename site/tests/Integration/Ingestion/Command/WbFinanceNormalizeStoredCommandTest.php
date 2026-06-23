<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class WbFinanceNormalizeStoredCommandTest extends IntegrationTestCase
{
    public function testDryRunSelectsWindowlessRecordsByReportDateFromExternalId(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $transport = $this->getNormalizeTransport();
        $transport->reset();

        $oldReport = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'wb-sales-report-detailed:2026-06-20:rrd-0',
            fetchedAt: new \DateTimeImmutable('2026-06-21 10:00:00+00:00'),
            rows: [$this->emptyRow(1)],
        );
        $selected = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-0',
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:17:43+00:00'),
            rows: [$this->emptyRow(2)],
        );
        $done = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-1',
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:18:00+00:00'),
            rows: [$this->emptyRow(3)],
        );

        $oldReport->markNormalizationSkipped();
        $selected->markNormalizationSkipped();
        $done->markNormalizationDone();
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-21',
            '--to' => '2026-06-21',
            '--shop-ref' => $connectionRef,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('wb-sales-report-detailed:2026-06-21:rrd-0', $display);
        self::assertStringNotContainsString('wb-sales-report-detailed:2026-06-20:rrd-0', $display);
        self::assertStringNotContainsString('wb-sales-report-detailed:2026-06-21:rrd-1', $display);
        self::assertSame(RawNormalizationStatus::SKIPPED, $this->rawStatus($selected));
        self::assertCount(0, $transport->getSent());
    }

    public function testDispatchResetsSkippedAndFailedRecordsToPendingAndQueuesNormalization(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $transport = $this->getNormalizeTransport();
        $transport->reset();

        $skipped = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-0',
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:17:43+00:00'),
            rows: [$this->emptyRow(11)],
        );
        $failed = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-1',
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:18:43+00:00'),
            rows: [$this->emptyRow(12)],
        );
        $done = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-2',
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:19:43+00:00'),
            rows: [$this->emptyRow(13)],
        );

        $skipped->markNormalizationSkipped();
        $failed->markNormalizationFailed();
        $done->markNormalizationDone();
        $this->em->persist(new NormalizationIssue(
            companyId: $companyId,
            rawRecordId: $failed->getId(),
            operationGroupId: null,
            kind: NormalizationIssueKind::MAPPER_FAILURE,
            details: ['message' => 'old failure'],
        ));
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-21',
            '--to' => '2026-06-21',
            '--shop-ref' => $connectionRef,
            '--dispatch' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(RawNormalizationStatus::PENDING, $this->rawStatus($skipped));
        self::assertSame(RawNormalizationStatus::PENDING, $this->rawStatus($failed));
        self::assertSame(RawNormalizationStatus::DONE, $this->rawStatus($done));
        self::assertSame(0, $this->openIssueCount($companyId, $failed->getId()));

        $messages = array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());
        self::assertCount(2, $messages);
        foreach ($messages as $message) {
            self::assertInstanceOf(NormalizeRawRecordMessage::class, $message);
        }
        self::assertEqualsCanonicalizing(
            [$skipped->getId(), $failed->getId()],
            array_map(static fn (NormalizeRawRecordMessage $message): string => $message->rawRecordId, $messages),
        );
    }

    public function testExecuteInlineNormalizesSelectedRecordToCanonicalTransactions(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-0',
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:17:43+00:00'),
            rows: [[
                'rrdId' => 101,
                'reportId' => 42880202606211,
                'currency' => 'RUB',
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Продажа',
                'saleDt' => '2026-06-21T10:15:00Z',
                'retailPriceWithDisc' => '1000.00',
                'forPay' => '850.00',
                'acquiringFee' => '20.00',
                'deliveryAmount' => 1,
                'deliveryService' => '-50.00',
                'srid' => 'sale-srid',
                'nmId' => 123,
                'sku' => 'sku-1',
            ]],
        );
        $record->markNormalizationSkipped();
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-21',
            '--to' => '2026-06-21',
            '--shop-ref' => $connectionRef,
            '--execute-inline' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(RawNormalizationStatus::DONE, $this->rawStatus($record));

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        self::assertCount(4, $transactionRepository->findByRawRecordId($companyId, $record->getId()));
        self::assertStringContainsString('Normalized 1 Wildberries finance raw records inline.', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:wb-finance:normalize-stored'));
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
            source: IngestSource::WILDBERRIES,
            resourceType: WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt,
            rows: $rows,
        ))[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(int $rrdId): array
    {
        return [
            'rrdId' => $rrdId,
            'rrDate' => '2026-06-21',
            'currency' => 'RUB',
            'sellerOperName' => '',
            'docTypeName' => '',
        ];
    }

    private function rawStatus(IngestRawRecord $record): RawNormalizationStatus
    {
        /** @var IngestRawRecordRepository $repository */
        $repository = self::getContainer()->get(IngestRawRecordRepository::class);

        return $repository->findByIdAndCompany($record->getId(), $record->getCompanyId())?->getNormalizationStatus()
            ?? throw new \RuntimeException('Raw record was not found.');
    }

    private function openIssueCount(string $companyId, string $rawRecordId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :companyId AND raw_record_id = :rawRecordId AND resolved_at IS NULL',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );
    }

    private function getNormalizeTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_normalize');

        return $transport;
    }
}
