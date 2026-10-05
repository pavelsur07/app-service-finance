<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Enum;

use App\Marketplace\Enum\FinancialReportSyncStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Политика готовности дня к закрытию месяца (R-04): каждый case enum принадлежит ровно одной
 * группе. Новый case без решения ломает этот тест, а не молча считается «готовым».
 */
final class FinancialReportSyncStatusMonthClosePolicyTest extends TestCase
{
    /** @return iterable<string, array{FinancialReportSyncStatus, string}> */
    public static function expectedGroups(): iterable
    {
        yield 'success' => [FinancialReportSyncStatus::SUCCESS, 'ready'];
        yield 'empty' => [FinancialReportSyncStatus::EMPTY, 'ready'];
        yield 'queued' => [FinancialReportSyncStatus::QUEUED, 'in_progress'];
        yield 'loading' => [FinancialReportSyncStatus::LOADING, 'in_progress'];
        yield 'raw_loaded' => [FinancialReportSyncStatus::RAW_LOADED, 'in_progress'];
        yield 'processing' => [FinancialReportSyncStatus::PROCESSING, 'in_progress'];
        yield 'failed' => [FinancialReportSyncStatus::FAILED, 'failed'];
        yield 'failed_final' => [FinancialReportSyncStatus::FAILED_FINAL, 'failed'];
        yield 'auth_failed' => [FinancialReportSyncStatus::AUTH_FAILED, 'failed'];
        yield 'conflict' => [FinancialReportSyncStatus::CONFLICT, 'failed'];
    }

    #[DataProvider('expectedGroups')]
    public function testEveryStatusBelongsToExactlyTheExpectedGroup(FinancialReportSyncStatus $status, string $group): void
    {
        self::assertSame('ready' === $group, $status->isReadyForMonthClose());
        self::assertSame('in_progress' === $group, $status->isInProgress());
        self::assertSame('failed' === $group, $status->isFailedOrInconsistent());
    }

    public function testExpectationsCoverEveryEnumCase(): void
    {
        $covered = array_map(
            static fn (array $row): FinancialReportSyncStatus => $row[0],
            iterator_to_array(self::expectedGroups(), false),
        );

        self::assertEqualsCanonicalizing(FinancialReportSyncStatus::cases(), $covered, 'Новый статус обязан получить явную группу готовности к закрытию.');
    }

    public function testOnlySuccessAndEmptyAreReadyAndFailedIsNotReadyEvenThoughRetryable(): void
    {
        $ready = array_values(array_filter(
            FinancialReportSyncStatus::cases(),
            static fn (FinancialReportSyncStatus $status): bool => $status->isReadyForMonthClose(),
        ));

        self::assertEqualsCanonicalizing([FinancialReportSyncStatus::SUCCESS, FinancialReportSyncStatus::EMPTY], $ready);
        self::assertFalse(FinancialReportSyncStatus::FAILED->isReadyForMonthClose());
    }
}
