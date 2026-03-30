<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Domain\Service;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Domain\Service\CostMappingResolver;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use App\Tests\Builders\MarketplaceAnalytics\UnitEconomyCostMappingBuilder;
use PHPUnit\Framework\TestCase;

final class CostMappingResolverTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const MARKETPLACE = 'wildberries';
    private const COST_CODE = 'logistics_delivery';

    public function testResolveReturnsUserMappingWhenExists(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->withCostCategoryCode(self::COST_CODE)
            ->withUnitEconomyCostType(UnitEconomyCostType::LOGISTICS_TO)
            ->build();

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findOneByKey')
            ->with(self::COMPANY_ID, MarketplaceType::WILDBERRIES, self::COST_CODE)
            ->willReturn($mapping);

        $resolver = new CostMappingResolver($repo);

        self::assertSame(
            UnitEconomyCostType::LOGISTICS_TO,
            $resolver->resolve(self::COMPANY_ID, self::MARKETPLACE, self::COST_CODE),
        );
    }

    public function testResolveReturnsFallbackToSystemMapping(): void
    {
        $systemMapping = UnitEconomyCostMappingBuilder::aMapping()
            ->asSystem()
            ->withCostCategoryCode(self::COST_CODE)
            ->withUnitEconomyCostType(UnitEconomyCostType::LOGISTICS_TO)
            ->build();

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findOneByKey')->willReturn(null);
        $repo->method('findSystemMapping')
            ->with(MarketplaceType::WILDBERRIES, self::COST_CODE)
            ->willReturn($systemMapping);

        $resolver = new CostMappingResolver($repo);

        self::assertSame(
            UnitEconomyCostType::LOGISTICS_TO,
            $resolver->resolve(self::COMPANY_ID, self::MARKETPLACE, self::COST_CODE),
        );
    }

    public function testResolveReturnsOtherWhenNoMappingFound(): void
    {
        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findOneByKey')->willReturn(null);
        $repo->method('findSystemMapping')->willReturn(null);

        $resolver = new CostMappingResolver($repo);

        self::assertSame(
            UnitEconomyCostType::OTHER,
            $resolver->resolve(self::COMPANY_ID, self::MARKETPLACE, self::COST_CODE),
        );
    }

    public function testIsAdvertisingCategoryReturnsTrueForCpcCode(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->withCostCategoryCode('advertising_cpc')
            ->withUnitEconomyCostType(UnitEconomyCostType::ADVERTISING_CPC)
            ->build();

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findOneByKey')->willReturn($mapping);

        $resolver = new CostMappingResolver($repo);

        self::assertTrue(
            $resolver->isAdvertisingCategory(self::COMPANY_ID, self::MARKETPLACE, 'advertising_cpc'),
        );
    }

    public function testIsAdvertisingCategoryReturnsFalseForLogisticsCode(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->withCostCategoryCode(self::COST_CODE)
            ->withUnitEconomyCostType(UnitEconomyCostType::LOGISTICS_TO)
            ->build();

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findOneByKey')->willReturn($mapping);

        $resolver = new CostMappingResolver($repo);

        self::assertFalse(
            $resolver->isAdvertisingCategory(self::COMPANY_ID, self::MARKETPLACE, self::COST_CODE),
        );
    }
}
