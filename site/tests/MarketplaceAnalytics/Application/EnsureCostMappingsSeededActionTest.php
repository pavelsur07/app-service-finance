<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Application\EnsureCostMappingsSeededAction;
use App\MarketplaceAnalytics\Domain\Service\DefaultCostMappingSeedPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EnsureCostMappingsSeededActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const MARKETPLACE = 'wildberries';

    private DefaultCostMappingSeedPolicy&MockObject $seedPolicy;
    private EntityManagerInterface&MockObject $entityManager;
    private EnsureCostMappingsSeededAction $action;

    protected function setUp(): void
    {
        $this->seedPolicy = $this->createMock(DefaultCostMappingSeedPolicy::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->action = new EnsureCostMappingsSeededAction(
            $this->seedPolicy,
            $this->entityManager,
        );
    }

    public function testCallsSeedPolicyAndFlushes(): void
    {
        $this->seedPolicy
            ->expects($this->once())
            ->method('seedForCompanyAndMarketplace')
            ->with(self::COMPANY_ID, self::MARKETPLACE);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        ($this->action)(self::COMPANY_ID, self::MARKETPLACE);
    }

    public function testFlushCalledEvenIfSeedIsIdempotent(): void
    {
        $this->seedPolicy
            ->expects($this->once())
            ->method('seedForCompanyAndMarketplace')
            ->with(self::COMPANY_ID, self::MARKETPLACE);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        ($this->action)(self::COMPANY_ID, self::MARKETPLACE);
    }
}
