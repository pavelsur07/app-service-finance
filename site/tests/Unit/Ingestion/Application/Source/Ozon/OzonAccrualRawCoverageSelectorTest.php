<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualRawCoverageSelector;
use PHPUnit\Framework\TestCase;

final class OzonAccrualRawCoverageSelectorTest extends TestCase
{
    public function testLaterWeeklyRawSupersedesPreviouslyLoadedDailyTail(): void
    {
        $selector = new OzonAccrualRawCoverageSelector();

        $rows = $selector->selectLatest([
            $this->raw('old-22-23', 'accrual-by-day:2026-06-22:2026-06-23', '2026-06-22', '2026-06-23', '2026-06-24 10:25:18'),
            $this->raw('old-24', 'accrual-by-day:2026-06-24:2026-06-24', '2026-06-24', '2026-06-24', '2026-06-25 03:00:04'),
            $this->raw('old-25', 'accrual-by-day:2026-06-25:2026-06-25', '2026-06-25', '2026-06-25', '2026-06-26 03:00:02'),
            $this->raw('old-26', 'accrual-by-day:2026-06-26:2026-06-26', '2026-06-26', '2026-06-26', '2026-06-27 03:00:03'),
            $this->raw('old-27', 'accrual-by-day:2026-06-27:2026-06-27', '2026-06-27', '2026-06-27', '2026-06-28 03:00:05'),
            $this->raw('old-28', 'accrual-by-day:2026-06-28:2026-06-28', '2026-06-28', '2026-06-28', '2026-06-29 03:00:03'),
            $this->raw('new-22-28', 'accrual-by-day:2026-06-22:2026-06-28', '2026-06-22', '2026-06-28', '2026-06-29 20:39:25'),
        ], new \DateTimeImmutable('2026-06-22'), new \DateTimeImmutable('2026-06-28'));

        self::assertCount(1, $rows);
        self::assertSame('new-22-28', $rows[0]['id']);
        self::assertSame([
            '2026-06-22',
            '2026-06-23',
            '2026-06-24',
            '2026-06-25',
            '2026-06-26',
            '2026-06-27',
            '2026-06-28',
        ], $rows[0]['selected_dates']);
    }

    public function testNewerSingleDayRawCanPartiallySupersedeWeeklyRaw(): void
    {
        $selector = new OzonAccrualRawCoverageSelector();

        $rows = $selector->selectLatest([
            $this->raw('week', 'accrual-by-day:2026-06-22:2026-06-28', '2026-06-22', '2026-06-28', '2026-06-29 03:00:00'),
            $this->raw('day-25', 'accrual-by-day:2026-06-25:2026-06-25', '2026-06-25', '2026-06-25', '2026-06-29 04:00:00'),
        ], new \DateTimeImmutable('2026-06-22'), new \DateTimeImmutable('2026-06-28'));

        self::assertCount(2, $rows);
        self::assertSame('week', $rows[0]['id']);
        self::assertSame(['2026-06-22', '2026-06-23', '2026-06-24', '2026-06-26', '2026-06-27', '2026-06-28'], $rows[0]['selected_dates']);
        self::assertSame('day-25', $rows[1]['id']);
        self::assertSame(['2026-06-25'], $rows[1]['selected_dates']);
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(string $id, string $externalId, string $windowFrom, string $windowTo, string $fetchedAt): array
    {
        return [
            'id' => $id,
            'company_id' => 'company-1',
            'shop_ref' => 'shop-1',
            'external_id' => $externalId,
            'normalization_status' => 'done',
            'window_from' => $windowFrom,
            'window_to' => $windowTo,
            'fetched_at' => $fetchedAt,
            'created_at' => $fetchedAt,
        ];
    }
}
