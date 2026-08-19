<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Report;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Engine\ValueFormatter;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Report\PlReportProjectsCompareBuilder;
use App\Finance\Repository\PLCategoryRepository;
use App\Tests\Unit\Finance\Report\Fixtures\MiniTreeFactory;
use PHPUnit\Framework\TestCase;

final class PlReportProjectsCompareBuilderTest extends TestCase
{
    public function testDeduplicatedTotalIsCalculatedFromProjectUnion(): void
    {
        $company = $this->createMock(Company::class);
        $parent = new ProjectDirection('11111111-1111-1111-1111-000000000001', $company, 'Parent');
        $child = (new ProjectDirection('11111111-1111-1111-1111-000000000002', $company, 'Child'))
            ->setParent($parent);

        $categories = $this->createMock(PLCategoryRepository::class);
        $categories->method('findBy')->willReturn(MiniTreeFactory::build($company));
        $facts = new class($parent, $child) implements FactsProviderInterface {
            public function __construct(
                private readonly ProjectDirection $parent,
                private readonly ProjectDirection $child,
            ) {
            }

            public function value(
                Company $company,
                PlReportPeriod $period,
                string $code,
                ProjectDirection|array|null $projectDirection = null,
                string|array|null $responsibilityCenterId = null,
            ): float {
                if ('REV_WB' !== $code) {
                    return 0.0;
                }
                if (\is_array($projectDirection)) {
                    return 25.0;
                }

                return $projectDirection === $this->parent ? 20.0 : ($projectDirection === $this->child ? 10.0 : 0.0);
            }
        };
        $builder = new PlReportProjectsCompareBuilder(
            new PlReportCalculator($categories, $facts),
            $categories,
            new ValueFormatter(),
        );

        $result = $builder->build(
            $company,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            [$parent, $child],
            null,
            null,
            true,
        );
        $revenue = array_values(array_filter(
            $result['rows'],
            static fn (array $row): bool => 'REV_WB' === $row['code'],
        ))[0];

        self::assertSame(20.0, $result['rawValues'][$revenue['id']][(string) $parent->getId()]);
        self::assertSame(10.0, $result['rawValues'][$revenue['id']][(string) $child->getId()]);
        self::assertSame(25.0, $result['rawValues'][$revenue['id']]['_total']);

        $legacyResult = $builder->build(
            $company,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            [$parent, $child],
        );
        self::assertSame(30.0, $legacyResult['rawValues'][$revenue['id']]['_total']);
    }
}
