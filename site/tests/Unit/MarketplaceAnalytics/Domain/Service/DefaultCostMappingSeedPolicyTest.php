<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Domain\Service;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Domain\Service\DefaultCostMappingSeedPolicy;
use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class DefaultCostMappingSeedPolicyTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const MARKETPLACE = 'wildberries';

    public function testSeedCreatesSystemMappingsWhenNoneExist(): void
    {
        $saved = [];

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findByCompanyAndMarketplace')
            ->with(self::COMPANY_ID, MarketplaceType::WILDBERRIES->value)
            ->willReturn([]);
        $repo->method('save')
            ->willReturnCallback(static function (UnitEconomyCostMapping $mapping) use (&$saved): void {
                $saved[] = $mapping;
            });

        $policy = new DefaultCostMappingSeedPolicy($repo);
        $policy->seedForCompanyAndMarketplace(self::COMPANY_ID, self::MARKETPLACE);

        self::assertCount(6, $saved);

        foreach ($saved as $mapping) {
            self::assertSame(self::COMPANY_ID, $mapping->getCompanyId());
            self::assertSame(MarketplaceType::WILDBERRIES, $mapping->getMarketplace());
        }
    }

    public function testSeedIsIdempotent(): void
    {
        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findByCompanyAndMarketplace')
            ->with(self::COMPANY_ID, MarketplaceType::WILDBERRIES->value)
            ->willReturn([
                $this->createMock(UnitEconomyCostMapping::class),
            ]);
        $repo->expects(self::never())->method('save');

        $policy = new DefaultCostMappingSeedPolicy($repo);
        $policy->seedForCompanyAndMarketplace(self::COMPANY_ID, self::MARKETPLACE);
    }

    public function testCreatedMappingsHaveCorrectTypes(): void
    {
        $saved = [];

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('findByCompanyAndMarketplace')->willReturn([]);
        $repo->method('save')
            ->willReturnCallback(static function (UnitEconomyCostMapping $mapping) use (&$saved): void {
                $saved[] = $mapping;
            });

        $policy = new DefaultCostMappingSeedPolicy($repo);
        $policy->seedForCompanyAndMarketplace(self::COMPANY_ID, self::MARKETPLACE);

        $typesByCategoryName = [];
        foreach ($saved as $mapping) {
            $typesByCategoryName[$mapping->getCostCategoryName()] = $mapping->getUnitEconomyCostType();
        }

        self::assertSame(UnitEconomyCostType::ADVERTISING_CPC, $typesByCategoryName['Реклама (CPC)']);
        self::assertSame(UnitEconomyCostType::ADVERTISING_OTHER, $typesByCategoryName['Реклама (прочая)']);
        self::assertSame(UnitEconomyCostType::LOGISTICS_TO, $typesByCategoryName['Логистика (доставка)']);
        self::assertSame(UnitEconomyCostType::LOGISTICS_BACK, $typesByCategoryName['Логистика (возврат)']);
        self::assertSame(UnitEconomyCostType::STORAGE, $typesByCategoryName['Хранение']);
        self::assertSame(UnitEconomyCostType::COMMISSION, $typesByCategoryName['Комиссия']);
    }
}
