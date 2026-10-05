<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\MarketplacePeriodIntegrityChecker;
use App\Marketplace\Application\Service\MonthCloseDayCoverageEvaluator;
use App\Marketplace\Application\Source\MarketplaceDataSourceInterface;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\WbReportDayCoverageQuery;
use App\Marketplace\Wildberries\Application\FinancialReport\WbFinancialReportPeriodResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MarketplacePeriodIntegrityCheckerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private Connection&MockObject $connection;

    /** @var list<array<string, mixed>> */
    private array $statusRows = [];

    /** @var list<array<string, mixed>> */
    private array $legacyRows = [];

    private int $legacyQueries = 0;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'marketplace_financial_report_sync_statuses')) {
                return $this->statusRows;
            }

            ++$this->legacyQueries;

            return $this->legacyRows;
        });
    }

    public function testNonWildberriesMarketplaceHasNoDayModel(): void
    {
        self::assertNull($this->checker()->checkReportDays(self::COMPANY_ID, MarketplaceType::OZON, '2026-09-01', '2026-09-30'));
    }

    public function testPreviousYearPeriodWithoutAnyStatusHasNoDayModel(): void
    {
        self::assertNull($this->checker()->checkReportDays(self::COMPANY_ID, MarketplaceType::WILDBERRIES, '2025-12-01', '2025-12-31'));
    }

    public function testPreviousYearDecemberClosedInJanuaryIsCheckedWhenStatusesExist(): void
    {
        $this->statusRows = array_values(array_filter(
            $this->statusRowsFor('2025-12-01', '2025-12-31', 'success'),
            static fn (array $row): bool => '2025-12-17' !== $row['business_date'],
        ));

        $coverage = $this->checker('2026-01-10 12:00:00 Europe/Moscow')->checkReportDays(self::COMPANY_ID, MarketplaceType::WILDBERRIES, '2025-12-01', '2025-12-31');

        self::assertNotNull($coverage);
        self::assertSame(31, $coverage->expectedDays);
        self::assertSame(['2025-12-17 missing'], array_map(static fn ($v): string => $v->label(), $coverage->violations));
    }

    public function testFuturePeriodAndTodayAreNotExpected(): void
    {
        // «Сегодня» 2026-10-04: вчера = 10-03, значит октябрь ожидает дни 10-01..10-03, а ноябрь — ничего.
        self::assertNull($this->checker()->checkReportDays(self::COMPANY_ID, MarketplaceType::WILDBERRIES, '2026-11-01', '2026-11-30'));

        $coverage = $this->checker()->checkReportDays(self::COMPANY_ID, MarketplaceType::WILDBERRIES, '2026-10-01', '2026-10-31');
        self::assertNotNull($coverage);
        self::assertSame(3, $coverage->expectedDays);
    }

    public function testFirstDayOfNewMonthHasNoCompletedDaysYet(): void
    {
        $checker = $this->checker('2026-10-01 09:00:00 Europe/Moscow');

        self::assertNull($checker->checkReportDays(self::COMPANY_ID, MarketplaceType::WILDBERRIES, '2026-10-01', '2026-10-31'));
    }

    public function testFullMonthIsReadyWhenEveryDayIsSuccessAndLegacyIsNotQueried(): void
    {
        $this->statusRows = $this->statusRowsFor('2026-09-01', '2026-09-30', 'success');

        $coverage = $this->checker()->checkReportDays(self::COMPANY_ID, MarketplaceType::WILDBERRIES, '2026-09-01', '2026-09-30');

        self::assertNotNull($coverage);
        self::assertSame(30, $coverage->expectedDays);
        self::assertTrue($coverage->isReady());
        self::assertSame(0, $this->legacyQueries, 'Легаси-покрытие запрашивается только если есть непокрытые дни.');
    }

    public function testMissingAndFailedDaysAreReportedAndLegacyCoverageIsConsulted(): void
    {
        $this->statusRows = array_values(array_filter(
            $this->statusRowsFor('2026-09-01', '2026-09-30', 'success'),
            static fn (array $row): bool => '2026-09-03' !== $row['business_date'],
        ));
        $this->statusRows[] = ['business_date' => '2026-09-13', 'status' => 'failed'];

        $coverage = $this->checker()->checkReportDays(self::COMPANY_ID, MarketplaceType::WILDBERRIES, '2026-09-01', '2026-09-30');

        self::assertNotNull($coverage);
        self::assertFalse($coverage->isReady());
        self::assertSame(1, $this->legacyQueries);
        self::assertContains('2026-09-03 missing', array_map(static fn ($v): string => $v->label(), $coverage->violations));
    }

    public function testRealizationSourcesAreExcludedFromTheLinkedInvariant(): void
    {
        $realization = $this->createMock(MarketplaceDataSourceInterface::class);
        $realization->method('getSourceId')->willReturn('ozon_realization');
        $realization->expects(self::never())->method('getUnprocessedEntries');

        $realizationReturn = $this->createMock(MarketplaceDataSourceInterface::class);
        $realizationReturn->method('getSourceId')->willReturn('ozon_realization_return');
        $realizationReturn->expects(self::never())->method('getUnprocessedEntries');

        self::assertSame([], $this->checker()->findStillUnprocessed([$realization, $realizationReturn], self::COMPANY_ID, 'ozon', '2026-09-01', '2026-09-30', false));
    }

    public function testStillUnprocessedReportsOnlySourcesThatStillHaveRows(): void
    {
        $clean = $this->createMock(MarketplaceDataSourceInterface::class);
        $clean->method('getUnprocessedEntries')->willReturn([]);
        $clean->method('getSourceId')->willReturn('clean');

        $dirty = $this->createMock(MarketplaceDataSourceInterface::class);
        $dirty->method('getUnprocessedEntries')->willReturn([['pl_category_id' => 'x'], ['pl_category_id' => 'y']]);
        $dirty->method('getSourceId')->willReturn('costs');
        $dirty->method('getStage')->willReturn(CloseStage::COSTS);

        $remaining = $this->checker()->findStillUnprocessed([$clean, $dirty], self::COMPANY_ID, 'wildberries', '2026-09-01', '2026-09-30', false);

        self::assertSame([['source' => 'costs', 'entries' => 2]], $remaining);
    }

    private function checker(string $now = '2026-10-04 12:00:00 Europe/Moscow'): MarketplacePeriodIntegrityChecker
    {
        return new MarketplacePeriodIntegrityChecker(
            new WbReportDayCoverageQuery($this->connection),
            new WbFinancialReportPeriodResolver(new MockClock($now)),
            new MonthCloseDayCoverageEvaluator(),
        );
    }

    /** @return list<array<string, mixed>> */
    private function statusRowsFor(string $from, string $to, string $status): array
    {
        $rows = [];
        for ($day = new \DateTimeImmutable($from); $day <= new \DateTimeImmutable($to); $day = $day->modify('+1 day')) {
            $rows[] = ['business_date' => $day->format('Y-m-d'), 'status' => $status];
        }

        return $rows;
    }
}
