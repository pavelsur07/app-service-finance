<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplacePlProjection;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use App\MarketplacePlProjection\Application\Service\TreeCut;
use PHPUnit\Framework\TestCase;

final class TreeCutTest extends TestCase
{
    public function testTreeIsKeptAsIsUpToCm3AndItsRatioAndOnlyFilledLeavesBelowAreListed(): void
    {
        $cut = TreeCut::cut([
            $this->row('REV', 100.0),
            $this->row('EMPTY_ABOVE', 0.0),
            $this->row('CM3', 60.0, PLCategoryType::KPI),
            $this->row('CM3K', 0.6, PLCategoryType::KPI),
            $this->row('INFRA_EMPTY', 0.0),
            $this->row('OVERHEAD_TOTAL', -5.0, PLCategoryType::SUBTOTAL),
            $this->row('OVERHEAD_ADMIN_BANK', -5.0),
            $this->row('EBITDA', 55.0, PLCategoryType::KPI),
        ]);

        self::assertTrue($cut['found']);
        self::assertSame(['REV', 'EMPTY_ABOVE', 'CM3', 'CM3K'], array_column($cut['rows'], 'code'));
        self::assertSame(['OVERHEAD_ADMIN_BANK'], array_column($cut['below'], 'code'));
    }

    public function testBoundaryWithoutRatioRowEndsAtCm3(): void
    {
        $cut = TreeCut::cut([$this->row('REV', 1.0), $this->row('CM3', 1.0, PLCategoryType::KPI), $this->row('EBITDA', 1.0, PLCategoryType::KPI)]);

        self::assertSame(['REV', 'CM3'], array_column($cut['rows'], 'code'));
        self::assertSame([], $cut['below']);
    }

    public function testOwnTreeWithoutBoundaryIsShownWhole(): void
    {
        $rows = [$this->row(null, 1.0), $this->row('OWN', 2.0)];

        self::assertSame(['rows' => $rows, 'below' => [], 'found' => false], TreeCut::cut($rows));
    }

    private function row(?string $code, float $value, PLCategoryType $type = PLCategoryType::LEAF_INPUT): PlPreviewRow
    {
        return new PlPreviewRow('id-'.($code ?? 'none'), $code, $code ?? 'Без кода', 1, $type, PLValueFormat::MONEY, ['new' => $value, 'legacy' => 0.0]);
    }
}
