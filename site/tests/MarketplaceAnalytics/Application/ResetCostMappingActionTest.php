<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Application\ResetCostMappingAction;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use App\Tests\Builders\MarketplaceAnalytics\UnitEconomyCostMappingBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ResetCostMappingActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const MAPPING_ID = '55555555-5555-5555-5555-555555555555';

    private UnitEconomyCostMappingRepositoryInterface&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private ResetCostMappingAction $action;

    protected function setUp(): void
    {
        $this->repository    = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->action = new ResetCostMappingAction(
            $this->repository,
            $this->entityManager,
        );
    }

    public function testRemapsToNewType(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->withUnitEconomyCostType(UnitEconomyCostType::OTHER)
            ->build();

        $this->repository->method('findByIdAndCompany')
            ->with(self::MAPPING_ID, self::COMPANY_ID)
            ->willReturn($mapping);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = ($this->action)(self::COMPANY_ID, self::MAPPING_ID, UnitEconomyCostType::LOGISTICS_TO);

        $this->assertSame(UnitEconomyCostType::LOGISTICS_TO, $result->getUnitEconomyCostType());
    }

    public function testThrowsWhenMappingNotFound(): void
    {
        $this->repository->method('findByIdAndCompany')
            ->with(self::MAPPING_ID, self::COMPANY_ID)
            ->willReturn(null);

        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->expectException(\DomainException::class);

        ($this->action)(self::COMPANY_ID, self::MAPPING_ID, UnitEconomyCostType::LOGISTICS_TO);
    }
}
