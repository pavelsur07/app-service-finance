<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class WbFinanceNormalizationFlowTest extends IntegrationTestCase
{
    public function testNormalizesStoredWildberriesFinanceRawRecordToCanonicalTransactions(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord($companyId, [[
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
        ]]);

        $this->normalize($record->getId(), $companyId);
        $this->em->clear();

        /** @var IngestRawRecordRepository $rawRecordRepository */
        $rawRecordRepository = self::getContainer()->get(IngestRawRecordRepository::class);
        self::assertSame(
            RawNormalizationStatus::DONE,
            $rawRecordRepository->findByIdAndCompany($record->getId(), $companyId)?->getNormalizationStatus(),
        );

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transactions = $transactionRepository->findByRawRecordId($companyId, $record->getId());
        self::assertCount(4, $transactions);

        $byExternalId = [];
        foreach ($transactions as $transaction) {
            $byExternalId[$transaction->getExternalId()] = $transaction;
            self::assertNull($transaction->getListingId());
        }

        self::assertArrayHasKey('wb:sales-report-detailed:101:sale', $byExternalId);
        self::assertSame(TransactionType::SALE, $byExternalId['wb:sales-report-detailed:101:sale']->getType());
        self::assertSame(TransactionDirection::IN, $byExternalId['wb:sales-report-detailed:101:sale']->getDirection());
        self::assertSame(100000, $byExternalId['wb:sales-report-detailed:101:sale']->getAmountMinor());
        self::assertSame('sale-srid', $byExternalId['wb:sales-report-detailed:101:sale']->getOrderRef());
        self::assertSame('sku-1', $byExternalId['wb:sales-report-detailed:101:sale']->getSourceData()['sku']);

        self::assertArrayHasKey('wb:sales-report-detailed:101:commission', $byExternalId);
        self::assertSame(TransactionDirection::OUT, $byExternalId['wb:sales-report-detailed:101:commission']->getDirection());
        self::assertSame(13000, $byExternalId['wb:sales-report-detailed:101:commission']->getAmountMinor());

        self::assertArrayHasKey('wb:sales-report-detailed:101:acquiring', $byExternalId);
        self::assertSame(TransactionDirection::OUT, $byExternalId['wb:sales-report-detailed:101:acquiring']->getDirection());
        self::assertSame(2000, $byExternalId['wb:sales-report-detailed:101:acquiring']->getAmountMinor());

        self::assertArrayHasKey('wb:sales-report-detailed:101:logistics_delivery', $byExternalId);
        self::assertSame(TransactionType::LOGISTICS, $byExternalId['wb:sales-report-detailed:101:logistics_delivery']->getType());
        self::assertSame(TransactionDirection::OUT, $byExternalId['wb:sales-report-detailed:101:logistics_delivery']->getDirection());
        self::assertSame(5000, $byExternalId['wb:sales-report-detailed:101:logistics_delivery']->getAmountMinor());

        /** @var NormalizationIssueRepository $issueRepository */
        $issueRepository = self::getContainer()->get(NormalizationIssueRepository::class);
        self::assertSame([], $issueRepository->findOpenByRawRecord($companyId, $record->getId()));
    }

    public function testUnknownNonZeroWildberriesFinanceRowsAreRecordedAsMapperFailures(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord($companyId, [[
            'rrdId' => 102,
            'sellerOperName' => 'Новая операция',
            'rrDate' => '2026-06-21',
            'forPay' => '12.34',
        ]]);

        $this->normalize($record->getId(), $companyId);
        $this->em->clear();

        /** @var IngestRawRecordRepository $rawRecordRepository */
        $rawRecordRepository = self::getContainer()->get(IngestRawRecordRepository::class);
        self::assertSame(
            RawNormalizationStatus::FAILED,
            $rawRecordRepository->findByIdAndCompany($record->getId(), $companyId)?->getNormalizationStatus(),
        );

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        self::assertSame([], $transactionRepository->findByRawRecordId($companyId, $record->getId()));

        /** @var NormalizationIssueRepository $issueRepository */
        $issueRepository = self::getContainer()->get(NormalizationIssueRepository::class);
        $issues = $issueRepository->findOpenByRawRecord($companyId, $record->getId());
        self::assertCount(1, $issues);
        self::assertSame(NormalizationIssueKind::MAPPER_FAILURE, $issues[0]->getKind());
        self::assertStringContainsString('unmapped non-zero fields', $issues[0]->getDetails()['message']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeRawRecord(string $companyId, array $rows): IngestRawRecord
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);
        $connectionRef = Uuid::uuid7()->toString();

        return $facade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::WILDBERRIES,
            resourceType: WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-0:'.Uuid::uuid7()->toString(),
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:17:43+00:00'),
            rows: $rows,
        ))[0];
    }

    private function normalize(string $rawRecordId, string $companyId): void
    {
        /** @var NormalizeRawRecordAction $action */
        $action = self::getContainer()->get(NormalizeRawRecordAction::class);
        $action(new NormalizeRawRecordCommand($rawRecordId, $companyId));
    }
}
