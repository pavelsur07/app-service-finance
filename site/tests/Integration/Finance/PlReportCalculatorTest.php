<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Entity\Company;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Repository\PLCategoryRepository;
use App\Tests\Integration\Finance\Fixtures\MiniTreeFactory;
use PHPUnit\Framework\TestCase;

final class PlReportCalculatorTest extends TestCase
{
    public function testMiniTreeCalculation(): void
    {
        $company = $this->createMock(Company::class);

        // Репозиторий вернёт фикстуру in-memory
        $repo = $this->createMock(PLCategoryRepository::class);
        $tree = MiniTreeFactory::build($company);
        $repo->method('findBy')->willReturn($tree);

        // Факты: REV_WB = 500, COGS = 100
        $facts = new class implements FactsProviderInterface {
            public function value(Company $company, PlReportPeriod $period, string $code): float
            {
                return match ($code) {
                    'REV_WB' => 500.0,
                    'COGS' => 100.0,
                    default => 0.0,
                };
            }
        };

        $calc = new PlReportCalculator($repo, $facts);
        $res = $calc->calculate($company, PlReportPeriod::forMonth(new \DateTimeImmutable('2025-01-01')));

        $map = [];
        foreach ($res->rows as $r) {
            $map[$r->code ?? $r->id] = $r->rawValue;
        }

        $this->assertSame(500.0, $map['REV_TOTAL']);        // subtotal по детям
        $this->assertSame(100.0, $map['VAR_COSTS_TOTAL']);  // subtotal по детям
        $this->assertSame(400.0, $map['MARGIN']);           // KPI: 500 - 100
        $this->assertEquals(0.8, $map['MARGIN_PCT'], '', 1e-6); // SAFE_DIV(400, 500) = 0.8
    }
}
