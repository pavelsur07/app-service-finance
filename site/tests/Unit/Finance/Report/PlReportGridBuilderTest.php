<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Report;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportGridBuilder;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Repository\PLCategoryRepository;
use PHPUnit\Framework\TestCase;

final class PlReportGridBuilderTest extends TestCase
{
    public function testBuildsCalendarQuartersWithPartialEdgeLabels(): void
    {
        $categories = $this->createMock(PLCategoryRepository::class);
        $categories->method('findBy')->willReturn([]);
        $facts = new class implements FactsProviderInterface {
            public function value(
                Company $company,
                PlReportPeriod $period,
                string $code,
                ProjectDirection|array|null $projectDirection = null,
                string|array|null $responsibilityCenterId = null,
            ): float {
                return 0.0;
            }
        };
        $builder = new PlReportGridBuilder(new PlReportCalculator($categories, $facts));

        $grid = $builder->build(
            $this->createMock(Company::class),
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-07-15'),
            'quarter',
        );

        self::assertSame(
            [
                'I кв. 2026 (01.02.2026 — 31.03.2026)',
                'II кв. 2026',
                'III кв. 2026 (01.07.2026 — 15.07.2026)',
            ],
            array_map(static fn (PlReportPeriod $period): string => $period->label, $grid['periods']),
        );
    }
}
