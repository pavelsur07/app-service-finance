<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Domain\Service;

use App\MarketplaceAnalytics\Domain\Service\DefaultCostMappingSeedPolicy;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class DefaultCostMappingSeedPolicyTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const MARKETPLACE = 'wildberries';

    public function testSeedDoesNothing(): void
    {
        $repo = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $policy = new DefaultCostMappingSeedPolicy($repo);
        $policy->seedForCompanyAndMarketplace(self::COMPANY_ID, self::MARKETPLACE);
    }
}
