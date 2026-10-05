<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\MonthCloseDayViolation;
use App\Marketplace\Application\Service\MonthCloseDayCoverageEvaluator;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthCloseDayCoverageEvaluatorTest extends TestCase
{
    private MonthCloseDayCoverageEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new MonthCloseDayCoverageEvaluator();
    }

    public function testAllSuccessAndEmptyDaysArePass(): void
    {
        $days = $this->days('2026-09-01', '2026-09-03');

        $violations = $this->evaluator->evaluate($days, [
            '2026-09-01' => FinancialReportSyncStatus::SUCCESS,
            '2026-09-02' => FinancialReportSyncStatus::EMPTY,
            '2026-09-03' => FinancialReportSyncStatus::SUCCESS,
        ], []);

        self::assertSame([], $violations);
    }

    /** @return iterable<string, array{FinancialReportSyncStatus, string}> */
    public static function blockingStatuses(): iterable
    {
        yield 'processing' => [FinancialReportSyncStatus::PROCESSING, MonthCloseDayViolation::STATE_IN_PROGRESS];
        yield 'raw_loaded' => [FinancialReportSyncStatus::RAW_LOADED, MonthCloseDayViolation::STATE_IN_PROGRESS];
        yield 'queued' => [FinancialReportSyncStatus::QUEUED, MonthCloseDayViolation::STATE_IN_PROGRESS];
        yield 'loading' => [FinancialReportSyncStatus::LOADING, MonthCloseDayViolation::STATE_IN_PROGRESS];
        yield 'failed' => [FinancialReportSyncStatus::FAILED, MonthCloseDayViolation::STATE_FAILED];
        yield 'failed_final' => [FinancialReportSyncStatus::FAILED_FINAL, MonthCloseDayViolation::STATE_FAILED];
        yield 'auth_failed' => [FinancialReportSyncStatus::AUTH_FAILED, MonthCloseDayViolation::STATE_FAILED];
        yield 'conflict' => [FinancialReportSyncStatus::CONFLICT, MonthCloseDayViolation::STATE_FAILED];
    }

    #[DataProvider('blockingStatuses')]
    public function testIncompleteAndFailedStatusesBlock(FinancialReportSyncStatus $status, string $expectedState): void
    {
        $violations = $this->evaluator->evaluate(
            $this->days('2026-09-01', '2026-09-02'),
            ['2026-09-01' => FinancialReportSyncStatus::SUCCESS, '2026-09-02' => $status],
            [],
        );

        self::assertCount(1, $violations);
        self::assertSame('2026-09-02', $violations[0]->businessDate);
        self::assertSame($expectedState, $violations[0]->state);
        self::assertSame($status->value, $violations[0]->status);
    }

    public function testMissingExpectedDayBlocksAndIsNotTreatedAsSuccess(): void
    {
        $violations = $this->evaluator->evaluate(
            $this->days('2026-09-01', '2026-09-04'),
            [
                '2026-09-01' => FinancialReportSyncStatus::SUCCESS,
                '2026-09-02' => FinancialReportSyncStatus::SUCCESS,
                '2026-09-04' => FinancialReportSyncStatus::SUCCESS,
            ],
            [],
        );

        self::assertCount(1, $violations);
        self::assertSame('2026-09-03', $violations[0]->businessDate);
        self::assertSame(MonthCloseDayViolation::STATE_MISSING, $violations[0]->state);
        self::assertNull($violations[0]->status);
        self::assertSame('2026-09-03 missing', $violations[0]->label());
    }

    public function testFailedAndMissingDaysCoveredByCompletedLegacyDocumentAreNotBlocking(): void
    {
        $violations = $this->evaluator->evaluate(
            $this->days('2026-04-01', '2026-04-05'),
            [
                '2026-04-01' => FinancialReportSyncStatus::FAILED,
                '2026-04-02' => FinancialReportSyncStatus::CONFLICT,
                // 04-03 строки статуса нет
                '2026-04-04' => FinancialReportSyncStatus::FAILED_FINAL,
                '2026-04-05' => FinancialReportSyncStatus::AUTH_FAILED,
            ],
            [['2026-03-28', '2026-04-05']],
        );

        self::assertSame(
            ['2026-04-02', '2026-04-04', '2026-04-05'],
            array_map(static fn (MonthCloseDayViolation $v): string => $v->businessDate, $violations),
            'Легаси-документ снимает только failed и отсутствие статуса; conflict, failed_final и auth_failed остаются блокирующими.',
        );
    }

    public function testLegacyCoverageOnlyCoversItsOwnDays(): void
    {
        $violations = $this->evaluator->evaluate(
            $this->days('2026-04-01', '2026-04-03'),
            ['2026-04-01' => FinancialReportSyncStatus::FAILED, '2026-04-02' => FinancialReportSyncStatus::FAILED, '2026-04-03' => FinancialReportSyncStatus::FAILED],
            [['2026-03-28', '2026-04-02']],
        );

        self::assertSame(['2026-04-03'], array_map(static fn (MonthCloseDayViolation $v): string => $v->businessDate, $violations));
    }

    public function testInProgressDayIsNotRescuedByLegacyCoverage(): void
    {
        $violations = $this->evaluator->evaluate(
            $this->days('2026-04-01', '2026-04-01'),
            ['2026-04-01' => FinancialReportSyncStatus::PROCESSING],
            [['2026-03-28', '2026-04-07']],
        );

        self::assertCount(1, $violations);
        self::assertSame(MonthCloseDayViolation::STATE_IN_PROGRESS, $violations[0]->state);
    }

    public function testStatusesOutsideExpectedDaysAreIgnored(): void
    {
        $violations = $this->evaluator->evaluate(
            $this->days('2026-09-10', '2026-09-11'),
            [
                '2026-09-10' => FinancialReportSyncStatus::SUCCESS,
                '2026-09-11' => FinancialReportSyncStatus::SUCCESS,
                // ошибки за пределами ожидаемого диапазона не относятся к закрытию
                '2026-06-15' => FinancialReportSyncStatus::FAILED_FINAL,
                '2026-09-20' => FinancialReportSyncStatus::CONFLICT,
            ],
            [],
        );

        self::assertSame([], $violations);
    }

    public function testViolationsAreReportedInDayOrderWithAllProblemDays(): void
    {
        $violations = $this->evaluator->evaluate(
            $this->days('2026-09-12', '2026-09-14'),
            ['2026-09-12' => FinancialReportSyncStatus::PROCESSING, '2026-09-13' => FinancialReportSyncStatus::FAILED],
            [],
        );

        self::assertSame(
            ['2026-09-12 processing', '2026-09-13 failed', '2026-09-14 missing'],
            array_map(static fn (MonthCloseDayViolation $v): string => $v->label(), $violations),
        );
    }

    /** @return list<\DateTimeImmutable> */
    private function days(string $from, string $to): array
    {
        $days = [];
        for ($day = new \DateTimeImmutable($from); $day <= new \DateTimeImmutable($to); $day = $day->modify('+1 day')) {
            $days[] = $day;
        }

        return $days;
    }
}
