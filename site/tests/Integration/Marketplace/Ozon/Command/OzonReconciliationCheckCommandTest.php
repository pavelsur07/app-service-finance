<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Command;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Ozon\Application\Action\RunOzonReconciliationAction;
use App\Marketplace\Ozon\Application\Service\OzonAccrualServiceCategoryResolver;
use App\Marketplace\Ozon\Command\OzonReconciliationCheckCommand;
use App\Marketplace\Ozon\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonLedgerTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRawAccrualTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRealizationTotalsQuery;
use App\Marketplace\Repository\OzonReconciliationLineRepository;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\ParameterType;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonReconciliationCheckCommandTest extends IntegrationTestCase
{
    private const DAY = '2026-10-10';

    private string $companyId;
    private string $docId;
    private string $listingId;
    private AbstractLogger $logger;

    /** @var \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> */
    private \ArrayObject $records;

    protected function setUp(): void
    {
        parent::setUp();

        $this->records = new \ArrayObject();
        $this->logger = new class($this->records) extends AbstractLogger {
            /** @param \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> $records */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records->append(['level' => (string) $level, 'message' => (string) $message, 'context' => $context]);
            }
        };

        $this->companyId = $this->seedCompany(1);
        $company = $this->em->find(Company::class, $this->companyId);
        self::assertNotNull($company);

        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('700000000')->build();
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('accrual_by_day')->withProcessingStatus('completed')->withPeriod(new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::DAY))->build();
        $doc->setApiEndpoint(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $doc->setRawData(['accruals' => [[
            'accrual_id' => 1, 'date' => self::DAY, 'unit_number' => 'A',
            'posting' => ['products' => [[
                'sku' => '700000000',
                'commission' => ['sale_amount' => ['amount' => '2999'], 'sale_price' => ['amount' => '1168.57'], 'seller_price' => ['amount' => '2999']],
            ]]],
        ]], 'service_types' => []]);
        $this->em->persist($listing);
        $this->em->persist($doc);
        $this->em->flush();

        $this->listingId = (string) $listing->getId();
        $this->docId = (string) $doc->getId();
        $this->seedConnection($this->companyId, true);
    }

    public function testGreenWhenLedgerMatchesRawAndMissingRealizationIsNotRed(): void
    {
        $this->seedSale(299900);

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('OK company '.$this->companyId.' 2026-10', $tester->getDisplay());
        self::assertStringContainsString('months: 2026-10, 2026-09', $tester->getDisplay());
        self::assertStringContainsString('raw vs ledger mismatches count: 0', $tester->getDisplay());
        self::assertSame([], array_values(array_filter($this->records->getArrayCopy(), static fn (array $r): bool => in_array($r['level'], ['warning', 'error'], true))));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_reconciliation_runs WHERE company_id = :c', ['c' => $this->companyId]));
    }

    public function testRedWithSingleAggregatedErrorWhenLedgerMissesRawSale(): void
    {
        // В учёте нет продажи, которая есть в сыром by-day.
        $tester = $this->runCommand();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('MISMATCH company '.$this->companyId.' 2026-10', $tester->getDisplay());

        $errors = array_values(array_filter($this->records->getArrayCopy(), static fn (array $r): bool => 'error' === $r['level']));
        self::assertCount(1, $errors);
        self::assertSame(1, $errors[0]['context']['affected_companies']);
        self::assertGreaterThanOrEqual(1, $errors[0]['context']['raw_vs_ledger_mismatch_lines']);
        // В лог не попадают суммы.
        self::assertStringNotContainsString('2999', json_encode($errors[0]['context'], \JSON_THROW_ON_ERROR));
    }

    public function testReportOnlyNeverFailsAndStaysQuiet(): void
    {
        $tester = $this->runCommand(['--report-only' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('MISMATCH company', $tester->getDisplay());
        self::assertSame([], array_values(array_filter($this->records->getArrayCopy(), static fn (array $r): bool => 'error' === $r['level'])));
    }

    public function testRealizationDisagreementIsWarningNotRed(): void
    {
        $this->seedSale(299900);
        // Реализация говорит 5 000 ₽ при 1 168.57 ₽ в сырье в той же базе (отчёт не обрабатывался — сверке это не нужно).
        $this->seedRealizationDocument([['item' => ['sku' => '700000000'], 'delivery_commission' => ['price_per_instance' => 5000, 'quantity' => 1]]]);

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('realization_vs_raw mismatches 1', $tester->getDisplay());
        $levels = array_column($this->records->getArrayCopy(), 'level');
        self::assertContains('warning', $levels);
        self::assertNotContains('error', $levels);
    }

    public function testCompanyWithoutActiveConnectionIsNotTouched(): void
    {
        $other = $this->seedCompany(2);
        $this->seedConnection($other, false);

        $tester = $this->runCommand();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_reconciliation_runs WHERE company_id = :c', ['c' => $other]));
        self::assertStringNotContainsString($other, $tester->getDisplay());
        self::assertStringContainsString('checked companies count: 1', $tester->getDisplay());
    }

    public function testCompanyIdOptionLimitsTheWalkAndIsValidated(): void
    {
        $other = $this->seedCompany(3);
        $this->seedConnection($other, true);
        $this->seedSale(299900);

        $tester = $this->runCommand(['--company-id' => $this->companyId]);

        self::assertStringContainsString('checked companies count: 1', $tester->getDisplay());
        self::assertStringNotContainsString($other, $tester->getDisplay());

        $bad = $this->runCommand(['--company-id' => 'not-a-uuid']);
        self::assertSame(2, $bad->getStatusCode());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): CommandTester
    {
        $clock = new MockClock('2026-10-12 09:00:00');
        $lines = self::getContainer()->get(OzonReconciliationLineRepository::class);

        $tester = new CommandTester(new OzonReconciliationCheckCommand(
            new ActiveOzonConnectionsQuery($this->connection),
            new RunOzonReconciliationAction(
                new OzonRealizationTotalsQuery($this->connection),
                new OzonRawAccrualTotalsQuery($this->connection, new OzonAccrualServiceCategoryResolver()),
                new OzonLedgerTotalsQuery($this->connection),
                self::getContainer()->get(OzonReconciliationRunRepository::class),
                $lines,
                $this->em,
                $clock,
                $this->logger,
            ),
            $lines,
            $this->em,
            $clock,
            $this->logger,
        ));
        $tester->execute($options);

        return $tester;
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

    private function seedConnection(string $companyId, bool $active): void
    {
        $this->connection->insert('marketplace_connections', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $companyId, 'marketplace' => 'ozon', 'api_key' => 'x', 'is_active' => $active,
            'connection_type' => 'seller', 'auth_status' => 'ok', 'auth_failure_count' => 0, 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
        ], ['is_active' => ParameterType::BOOLEAN]);
    }

    private function seedSale(int $minor): void
    {
        $amount = number_format($minor / 100, 2, '.', '');
        $this->connection->insert('marketplace_sales', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $this->companyId, 'listing_id' => $this->listingId, 'marketplace' => 'ozon',
            'external_order_id' => 'ozon-accrual-A-product-0', 'sale_date' => self::DAY, 'quantity' => 1, 'price_per_unit' => $amount,
            'total_revenue' => $amount, 'raw_document_id' => $this->docId, 'created_at' => '2026-10-11 00:00:00', 'updated_at' => '2026-10-11 00:00:00',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seedRealizationDocument(array $rows): string
    {
        $company = $this->em->find(Company::class, $this->companyId);
        self::assertNotNull($company);
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('realization')->withPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'))->build();
        $doc->setApiEndpoint(MarketplaceRawFormat::OZON_REALIZATION_V2->value);
        $doc->setRawData(['result' => ['rows' => $rows]]);
        $this->em->persist($doc);
        $this->em->flush();

        return (string) $doc->getId();
    }
}
