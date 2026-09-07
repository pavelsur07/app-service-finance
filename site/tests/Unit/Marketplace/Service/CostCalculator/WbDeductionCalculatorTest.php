<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Service\CostCalculator;

use App\Marketplace\Service\CostCalculator\WbDeductionCalculator;
use App\Shared\Service\SlugifyService;
use PHPUnit\Framework\TestCase;

final class WbDeductionCalculatorTest extends TestCase
{
    public function testMonthlyWarehouseDisposalNamesUseOneStableCategory(): void
    {
        $calculator = new WbDeductionCalculator(new SlugifyService());

        $june = $calculator->calculate($this->deductionRow(
            7001,
            'Отчет об утилизированном товаре (по складу) за июнь 2026',
            17.86,
        ), null);
        $july = $calculator->calculate($this->deductionRow(
            7002,
            'Отчёт об утилизированном товаре (по складу) за июль 2026',
            9.12,
        ), null);

        self::assertCount(1, $june);
        self::assertCount(1, $july);
        self::assertSame('wb_warehouse_disposal', $june[0]['category_code']);
        self::assertSame('wb_warehouse_disposal', $july[0]['category_code']);
        self::assertSame('Отчет об утилизированном товаре (по складу) за июнь 2026', $june[0]['description']);
        self::assertSame('Отчёт об утилизированном товаре (по складу) за июль 2026', $july[0]['description']);
        self::assertSame('wb:7001:wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_i', $june[0]['external_id']);
        self::assertSame('wb:7002:wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_i', $july[0]['external_id']);
    }

    /** @return array<string, mixed> */
    private function deductionRow(int $rrdId, string $reason, float $amount): array
    {
        return [
            'rrd_id' => $rrdId,
            'supplier_oper_name' => 'Удержание',
            'rr_dt' => '2026-08-10',
            'deduction' => $amount,
            'bonus_type_name' => $reason,
        ];
    }
}
