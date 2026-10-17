<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\PlPreview;

use App\Marketplace\Application\DTO\PlPreviewCostCoverageDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewCostStatus;
use App\Marketplace\Infrastructure\Query\PlPreviewCostCoverageQuery;
use App\Marketplace\Infrastructure\Query\PlPreviewSalesCoverageQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class PlPreviewCoverageQueryTest extends IntegrationTestCase
{
    private PlPreviewFixture $fixture;
    private PlPreviewCostCoverageQuery $costs;
    private PlPreviewSalesCoverageQuery $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new PlPreviewFixture($this->em, 411);
        $this->costs = new PlPreviewCostCoverageQuery($this->em->getConnection());
        $this->sales = new PlPreviewSalesCoverageQuery($this->em->getConnection());
    }

    public function testCostCategoriesWithStatusAndNetAmount(): void
    {
        $rows = [];
        foreach ($this->costs->fetch($this->companyId(), ...$this->january(), marketplace: null) as $row) {
            $rows[$row->costCategoryCode ?? '(без категории)'] = [$row->status, $row->rowsCount, (float) $row->netAmount];
        }
        ksort($rows);

        self::assertEquals([
            '(без категории)' => [PlPreviewCostStatus::UNMAPPED, 1, 15.0],
            'wb_excluded' => [PlPreviewCostStatus::EXCLUDED, 1, 500.0],
            'wb_logistics' => [PlPreviewCostStatus::MAPPED, 4, 190.0],
            'wb_no_rule' => [PlPreviewCostStatus::UNMAPPED, 1, 25.0],
            'wb_unmapped' => [PlPreviewCostStatus::UNMAPPED, 1, 400.0],
        ], $rows);
    }

    public function testTotalWithoutMappingsReconcilesWithStatuses(): void
    {
        $byStatus = array_sum(array_map(
            static fn (PlPreviewCostCoverageDTO $row): float => (float) $row->netAmount,
            $this->costs->fetch($this->companyId(), ...$this->january(), marketplace: null),
        ));

        self::assertEqualsWithDelta(1130.0, (float) $this->costs->fetchTotal($this->companyId(), ...$this->january(), marketplace: null), 0.001);
        self::assertEqualsWithDelta(1130.0, $byStatus, 0.001);
    }

    public function testCostsOfOtherMarketplaceOrPeriodAreNotCounted(): void
    {
        self::assertSame([], $this->costs->fetch($this->companyId(), ...$this->january(), marketplace: MarketplaceType::OZON));
        self::assertSame(0.0, (float) $this->costs->fetchTotal(
            $this->companyId(),
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
            null,
        ));
    }

    public function testSalesCoverage(): void
    {
        self::assertSame([
            'ozon|realization' => [2, 400.0, true],
            'ozon|realization_return' => [1, 100.0, true],
            'wildberries|return' => [1, 200.0, true],
            'wildberries|sale' => [2, 1300.0, true],
        ], $this->salesMap(null));
    }

    public function testKindWithoutActiveMappingIsReported(): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE marketplace_sale_mappings SET is_active = false WHERE company_id = :companyId AND operation_type = 'sale' AND marketplace = 'wildberries'",
            ['companyId' => $this->companyId()],
        );

        $map = $this->salesMap(MarketplaceType::WILDBERRIES);

        self::assertSame([2, 1300.0, false], $map['wildberries|sale']);
        self::assertSame([1, 200.0, true], $map['wildberries|return']);
        self::assertArrayNotHasKey('ozon|realization', $map);
    }

    /**
     * @return array<string, array{int, float, bool}>
     */
    private function salesMap(?MarketplaceType $marketplace): array
    {
        $map = [];
        foreach ($this->sales->fetch($this->companyId(), ...$this->january(), marketplace: $marketplace) as $row) {
            $map[$row->marketplace->value.'|'.$row->kind->value] = [$row->rowsCount, (float) $row->amount, $row->hasActiveMapping];
        }
        ksort($map);

        return $map;
    }

    /**
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable}
     */
    private function january(): array
    {
        return ['from' => new \DateTimeImmutable('2026-01-01'), 'to' => new \DateTimeImmutable('2026-01-31')];
    }

    private function companyId(): string
    {
        return (string) $this->fixture->company->getId();
    }
}
