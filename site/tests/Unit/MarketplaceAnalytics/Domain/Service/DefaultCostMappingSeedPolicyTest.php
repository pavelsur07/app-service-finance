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
        $repo->method('hasCompanyMappings')
            ->with(self::COMPANY_ID, MarketplaceType::WILDBERRIES)
            ->willReturn(false);
        $repo->method('save')
            ->willReturnCallback(static function (UnitEconomyCostMapping $mapping) use (&$saved): void {
                $saved[] = $mapping;
            });

        $policy = new DefaultCostMappingSeedPolicy($repo);
        $policy->seedForCompanyAndMarketplace(self::COMPANY_ID, self::MARKETPLACE);

        self::assertCount(6, $saved);

        foreach ($saved as $mapping) {
            self::assertTrue($mapping->isSystem());
        }
    }

    public function testSeedIsIdempotent(): void
    {
        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('hasCompanyMappings')
            ->with(self::COMPANY_ID, MarketplaceType::WILDBERRIES)
            ->willReturn(true);
        $repo->expects(self::never())->method('save');

        $policy = new DefaultCostMappingSeedPolicy($repo);
        $policy->seedForCompanyAndMarketplace(self::COMPANY_ID, self::MARKETPLACE);
    }

    public function testCreatedMappingsHaveCorrectTypes(): void
    {
        $saved = [];

        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->method('hasCompanyMappings')->willReturn(false);
        $repo->method('save')
            ->willReturnCallback(static function (UnitEconomyCostMapping $mapping) use (&$saved): void {
                $saved[] = $mapping;
            });

        $policy = new DefaultCostMappingSeedPolicy($repo);
        $policy->seedForCompanyAndMarketplace(self::COMPANY_ID, self::MARKETPLACE);

        $typesByCode = [];
        foreach ($saved as $mapping) {
            $typesByCode[$mapping->getCostCategoryCode()] = $mapping->getUnitEconomyCostType();
        }

        self::assertSame(UnitEconomyCostType::ADVERTISING_CPC, $typesByCode['advertising_cpc']);
        self::assertSame(UnitEconomyCostType::ADVERTISING_OTHER, $typesByCode['advertising_other']);
        self::assertSame(UnitEconomyCostType::LOGISTICS_TO, $typesByCode['logistics_delivery']);
        self::assertSame(UnitEconomyCostType::LOGISTICS_BACK, $typesByCode['logistics_return']);
        self::assertSame(UnitEconomyCostType::STORAGE, $typesByCode['storage']);
        self::assertSame(UnitEconomyCostType::COMMISSION, $typesByCode['commission']);
    }
}
