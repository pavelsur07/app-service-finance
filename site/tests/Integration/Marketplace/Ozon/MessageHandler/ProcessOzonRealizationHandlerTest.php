<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ProcessOzonRealizationMessage;
use App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction;
use App\Marketplace\Ozon\Application\Action\RunOzonReconciliationAction;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Application\Service\OzonAccrualServiceCategoryResolver;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonLedgerTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRawAccrualTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRealizationTotalsQuery;
use App\Marketplace\Ozon\MessageHandler\ProcessOzonRealizationHandler;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Repository\OzonReconciliationLineRepository;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ProcessOzonRealizationHandlerTest extends IntegrationTestCase
{
    private const NOW = '2026-10-03 10:00:00 Europe/Moscow';

    private string $companyId;
    private string $otherCompanyId;
    private string $connectionId;
    private string $docId;

    /** @var \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> */
    private \ArrayObject $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = new \ArrayObject();
        $this->companyId = $this->seedCompany(1);
        $this->otherCompanyId = $this->seedCompany(2);
        $this->connectionId = Uuid::uuid4()->toString();
        $this->docId = $this->seedRealizationDocument($this->companyId, [
            ['item' => ['sku' => '700000000', 'offer_id' => 'A', 'name' => 'Товар А'], 'delivery_commission' => ['price_per_instance' => 100.0, 'quantity' => 2]],
            ['item' => ['sku' => '700000001', 'offer_id' => 'B', 'name' => 'Товар Б'], 'return_commission' => ['price_per_instance' => 50.0, 'quantity' => 1]],
        ]);
    }

    public function testOpenMonthIsProcessedAndReconciliationRefreshed(): void
    {
        $this->handle();

        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::SUCCESS, $status->getStatus());
        self::assertSame(2, $this->realizationRows());
        self::assertSame('200.00', $this->connection->fetchOne('SELECT SUM(total_amount)::numeric(12,2)::text FROM marketplace_ozon_realizations WHERE company_id = :c AND raw_document_id = :d', ['c' => $this->companyId, 'd' => $this->docId]));
        // Сверка за месяц пересчитана и видит «Реализацию».
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_reconciliation_runs WHERE company_id = :c AND period_from = :f', ['c' => $this->companyId, 'f' => '2026-09-01']));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT realization_present::int FROM marketplace_ozon_reconciliation_runs WHERE company_id = :c AND period_from = :f', ['c' => $this->companyId, 'f' => '2026-09-01']));
        self::assertSame([], $this->logsOf('error'));

        // Итоги из сырого документа (источник сверки) совпадают с тем, что записала обработка.
        $totals = (new OzonRealizationTotalsQuery($this->connection))->fetch($this->companyId, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
        self::assertNotNull($totals);
        self::assertSame(20000, $totals->sales->amountMinor());
        self::assertSame(5000, $totals->returns->amountMinor());
        self::assertSame('50.00', $this->connection->fetchOne('SELECT SUM(return_amount)::numeric(12,2)::text FROM marketplace_ozon_realizations WHERE company_id = :c', ['c' => $this->companyId]));
    }

    public function testRedeliveryDoesNotReprocess(): void
    {
        $this->handle();
        $this->connection->executeStatement("UPDATE marketplace_ozon_realizations SET name = 'ручная правка' WHERE company_id = :c", ['c' => $this->companyId]);

        $this->handle();

        self::assertSame(2, $this->realizationRows());
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_ozon_realizations WHERE name = 'ручная правка'"));
    }

    public function testFinallyClosedSalesReturnsStageBlocksProcessing(): void
    {
        $this->seedMonthClose(false);

        $this->handle();

        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::CONFLICT, $status->getStatus());
        self::assertSame(0, $this->realizationRows());
        self::assertSame([], $this->logsOf('error'));
        self::assertNotSame([], $this->logsOf('warning'));
    }

    public function testPreliminaryCloseDoesNotBlockProcessing(): void
    {
        $this->seedMonthClose(true);

        $this->handle();

        self::assertSame(FinancialReportSyncStatus::SUCCESS, $this->pairStatus()->getStatus());
        self::assertSame(2, $this->realizationRows());
    }

    public function testProcessingFailureIsRecordedWithRetryAndIncidentLog(): void
    {
        // Цена не помещается в NUMERIC(12,2): вставка падает уже после начала обработки.
        $docId = $this->seedRealizationDocument($this->otherCompanyId, [
            ['item' => ['sku' => '1', 'offer_id' => 'X', 'name' => 'Ломаный'], 'delivery_commission' => ['price_per_instance' => 1e12, 'quantity' => 1]],
        ]);

        $this->handle($this->otherCompanyId, $docId);

        $status = $this->pairStatus($this->otherCompanyId);
        self::assertSame(FinancialReportSyncStatus::FAILED, $status->getStatus());
        self::assertSame('2026-10-03 11:00:00', $status->getNextRetryAt()?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s'));
        self::assertNotSame([], $this->logsOf('error'));
    }

    public function testForeignDocumentIsRejectedWithoutTouchingStatus(): void
    {
        $this->handle($this->otherCompanyId, $this->docId);

        self::assertNull($this->statuses()->findByBusinessDay($this->otherCompanyId, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, new \DateTimeImmutable('2026-09-01')));
        self::assertSame(0, $this->realizationRows());
        self::assertNotSame([], $this->logsOf('error'));
    }

    private function handle(?string $companyId = null, ?string $docId = null): void
    {
        $this->em->clear();
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> $logs */
            public function __construct(private readonly \ArrayObject $logs)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs->append(['level' => (string) $level, 'message' => (string) $message, 'context' => $context]);
            }
        };
        $clock = new MockClock(self::NOW);

        $handler = new ProcessOzonRealizationHandler(
            $this->em,
            self::getContainer()->get(MarketplaceRawDocumentRepository::class),
            self::getContainer()->get(MarketplaceMonthCloseRepository::class),
            $this->statuses(),
            self::getContainer()->get(ProcessOzonRealizationAction::class),
            new RunOzonReconciliationAction(
                new OzonRealizationTotalsQuery($this->connection),
                new OzonRawAccrualTotalsQuery($this->connection, new OzonAccrualServiceCategoryResolver()),
                new OzonLedgerTotalsQuery($this->connection),
                self::getContainer()->get(OzonReconciliationRunRepository::class),
                self::getContainer()->get(OzonReconciliationLineRepository::class),
                $this->em,
                $clock,
                $logger,
            ),
            new LockFactory(new InMemoryStore()),
            $clock,
            $logger,
        );

        $handler(new ProcessOzonRealizationMessage($companyId ?? $this->companyId, $this->connectionId, $docId ?? $this->docId, 2026, 9));
    }

    private function statuses(): MarketplaceFinancialReportSyncStatusRepository
    {
        return self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);
    }

    private function pairStatus(?string $companyId = null): MarketplaceFinancialReportSyncStatus
    {
        $this->em->clear();
        $status = $this->statuses()->findByBusinessDay($companyId ?? $this->companyId, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, new \DateTimeImmutable('2026-09-01'));
        self::assertNotNull($status);
        self::assertSame(FinancialReportSyncMode::MANUAL, $status->getMode() ?? FinancialReportSyncMode::MANUAL);

        return $status;
    }

    private function realizationRows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_realizations WHERE company_id = :c', ['c' => $this->companyId]);
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

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seedRealizationDocument(string $companyId, array $rows): string
    {
        $company = $this->em->find(Company::class, $companyId);
        self::assertNotNull($company);
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('realization')->withPeriod(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'))->build();
        $doc->setApiEndpoint(MarketplaceRawFormat::OZON_REALIZATION_V2->value);
        $doc->setRawData(['result' => ['header' => ['start_date' => '2026-09-01', 'stop_date' => '2026-09-30'], 'rows' => $rows]]);
        $this->em->persist($doc);
        $this->em->flush();

        return (string) $doc->getId();
    }

    private function seedMonthClose(bool $preliminary): void
    {
        $close = new MarketplaceMonthClose(Uuid::uuid4()->toString(), $this->companyId, MarketplaceType::OZON, 2026, 9);
        $close->closeStage(CloseStage::SALES_RETURNS, Uuid::uuid4()->toString(), [], []);
        if ($preliminary) {
            $close->setSettings(['last_close_was_preliminary' => ['sales_returns' => true]]);
        }
        $this->em->persist($close);
        $this->em->flush();
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    private function logsOf(string $level): array
    {
        return array_values(array_filter($this->logs->getArrayCopy(), static fn (array $r): bool => $level === $r['level']));
    }
}
