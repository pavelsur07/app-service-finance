<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Command\OzonRealizationCheckCommand;
use App\Marketplace\Ozon\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\OzonRealizationAppliedQuery;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\ParameterType;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonRealizationCheckCommandTest extends IntegrationTestCase
{
    private const IN_PERIOD = '2026-10-09 07:20:00 Europe/Moscow';

    private MarketplaceFinancialReportSyncStatusRepository $statuses;

    /** @var \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> */
    private \ArrayObject $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statuses = self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);
        $this->logs = new \ArrayObject();
    }

    public function testOutsideGatePeriodStaysSilent(): void
    {
        $this->seedCompany(1);

        foreach (['2026-10-05 07:20:00', '2026-10-08 23:59:00', '2026-10-17 07:20:00', '2026-10-28 07:20:00'] as $now) {
            $tester = $this->runCommand($now.' Europe/Moscow');
            self::assertSame(0, $tester->getStatusCode(), $now);
            self::assertStringContainsString('outside gate period', $tester->getDisplay(), $now);
        }
        self::assertCount(0, $this->logs);
    }

    public function testMissingReportIsRedWithOneAggregatedError(): void
    {
        $companyA = $this->seedCompany(1);
        $companyB = $this->seedCompany(2);
        $this->seedStatus($companyB, 'auth');

        $tester = $this->runCommand(self::IN_PERIOD);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('MISSING company '.$companyA.': never_polled', $tester->getDisplay());
        self::assertStringContainsString('MISSING company '.$companyB.': auth_failed', $tester->getDisplay());
        $errors = $this->logsOf('error');
        self::assertCount(1, $errors);
        self::assertSame(2, $errors[0]['context']['missing']);
        self::assertSame('2026-09', $errors[0]['context']['report_month']);
    }

    public function testStillMissingAfterTheFirstDayIsWarningOnly(): void
    {
        $this->seedCompany(1);

        $tester = $this->runCommand('2026-10-12 07:20:00 Europe/Moscow');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('missing count: 1', $tester->getDisplay());
        self::assertSame([], $this->logsOf('error'));
        self::assertCount(1, $this->logsOf('warning'));
    }

    public function testReceivedAndAppliedReportsAreGreen(): void
    {
        $viaPipeline = $this->seedCompany(1);
        $manual = $this->seedCompany(2);
        $this->seedStatus($viaPipeline, 'success');
        $this->seedAppliedRows($manual);

        $tester = $this->runCommand(self::IN_PERIOD);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('ok count: 2', $tester->getDisplay());
        self::assertSame([], $this->logsOf('error'));
    }

    public function testClosedStageIsWarningNotRed(): void
    {
        $company = $this->seedCompany(1);
        $this->seedStatus($company, 'conflict');

        $tester = $this->runCommand(self::IN_PERIOD);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('CONFLICT company '.$company, $tester->getDisplay());
        self::assertSame([], $this->logsOf('error'));
        self::assertCount(1, $this->logsOf('warning'));
    }

    public function testReportOnlyNeverFailsAndCompanyOptionLimitsTheCheck(): void
    {
        $companyA = $this->seedCompany(1);
        $companyB = $this->seedCompany(2);

        $reportOnly = $this->runCommand(self::IN_PERIOD, ['--report-only' => true]);
        self::assertSame(0, $reportOnly->getStatusCode());
        self::assertStringContainsString('missing count: 2', $reportOnly->getDisplay());
        self::assertSame([], $this->logsOf('error'));

        $one = $this->runCommand(self::IN_PERIOD, ['--company-id' => $companyA]);
        self::assertSame(1, $one->getStatusCode());
        self::assertStringContainsString('missing count: 1', $one->getDisplay());
        self::assertStringNotContainsString($companyB, $one->getDisplay());

        self::assertSame(2, $this->runCommand(self::IN_PERIOD, ['--company-id' => 'nope'])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(string $now, array $options = []): CommandTester
    {
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

        $tester = new CommandTester(new OzonRealizationCheckCommand(
            new ActiveOzonConnectionsQuery($this->connection),
            $this->statuses,
            new OzonRealizationAppliedQuery($this->connection),
            new MockClock($now),
            $logger,
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
        $companyId = (string) $company->getId();
        $this->connection->insert('marketplace_connections', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $companyId, 'marketplace' => 'ozon', 'api_key' => 'x', 'is_active' => true,
            'connection_type' => 'seller', 'auth_status' => 'ok', 'auth_failure_count' => 0, 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
        ], ['is_active' => ParameterType::BOOLEAN]);

        return $companyId;
    }

    private function seedStatus(string $companyId, string $kind): void
    {
        $connectionId = (string) $this->connection->fetchOne('SELECT id FROM marketplace_connections WHERE company_id = :c', ['c' => $companyId]);
        $status = $this->statuses->findOrCreateForDay($connectionId, $companyId, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, OzonRealizationReport::apiEndpoint(), new \DateTimeImmutable('2026-09-01'));
        match ($kind) {
            'success' => $status->markSuccess(),
            'conflict' => $status->markConflict('MonthStageClosed', 'closed', null, null),
            'auth' => $status->markAuthFailed('X', 'rejected', 403, null),
            default => throw new \LogicException($kind),
        };
        $this->statuses->save($status);
        $this->em->flush();
        $this->em->clear();
    }

    private function seedAppliedRows(string $companyId): void
    {
        $company = $this->em->find(\App\Company\Entity\Company::class, $companyId);
        self::assertNotNull($company);
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('realization')->withPeriod(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'))->build();
        $this->em->persist($doc);
        $this->em->flush();
        $this->connection->insert('marketplace_ozon_realizations', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $companyId, 'raw_document_id' => (string) $doc->getId(), 'sku' => '1',
            'seller_price_per_instance' => '10.00', 'quantity' => 1, 'total_amount' => '10.00',
            'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'created_at' => '2026-10-05 00:00:00',
        ]);
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    private function logsOf(string $level): array
    {
        return array_values(array_filter($this->logs->getArrayCopy(), static fn (array $r): bool => $level === $r['level']));
    }
}
