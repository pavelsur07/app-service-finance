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
    private const CATEGORY_ID = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    public function testResolveReturnsMappingWhenExists(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->withCostCategoryId(self::CATEGORY_ID)
            ->withUnitEconomyCostType(UnitEconomyCostType::LOGISTICS_TO)
            ->build();

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findOneByCategoryId')
            ->with(self::COMPANY_ID, MarketplaceType::WILDBERRIES, self::CATEGORY_ID)
            ->willReturn($mapping);

        $resolver = new CostMappingResolver($repo);

        self::assertSame(
            UnitEconomyCostType::LOGISTICS_TO,
            $resolver->resolve(self::COMPANY_ID, self::MARKETPLACE, self::CATEGORY_ID),
        );
    }

    public function testResolveReturnsOtherWhenNoMappingFound(): void
    {
        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findOneByCategoryId')->willReturn(null);

        $resolver = new CostMappingResolver($repo);

        self::assertSame(
            UnitEconomyCostType::OTHER,
            $resolver->resolve(self::COMPANY_ID, self::MARKETPLACE, self::CATEGORY_ID),
        );
    }

    public function testIsAdvertisingCategoryReturnsTrueForAdvertisingType(): void
    {
        $categoryId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->withCostCategoryId($categoryId)
            ->withUnitEconomyCostType(UnitEconomyCostType::ADVERTISING_CPC)
            ->build();

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findOneByCategoryId')->willReturn($mapping);

        $resolver = new CostMappingResolver($repo);

        self::assertTrue(
            $resolver->isAdvertisingCategory(self::COMPANY_ID, self::MARKETPLACE, $categoryId),
        );
    }

    public function testIsAdvertisingCategoryReturnsFalseForLogisticsType(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->withCostCategoryId(self::CATEGORY_ID)
            ->withUnitEconomyCostType(UnitEconomyCostType::LOGISTICS_TO)
            ->build();

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findOneByCategoryId')->willReturn($mapping);

        $resolver = new CostMappingResolver($repo);

        self::assertFalse(
            $resolver->isAdvertisingCategory(self::COMPANY_ID, self::MARKETPLACE, self::CATEGORY_ID),
        );
    }
}
