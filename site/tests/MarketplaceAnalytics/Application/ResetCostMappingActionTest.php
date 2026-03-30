<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Application;

use App\Marketplace\Enum\MarketplaceType;
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

    public function testResetRestoresToSystemValue(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->asCustom()
            ->withUnitEconomyCostType(UnitEconomyCostType::OTHER)
            ->build();

        $systemMapping = UnitEconomyCostMappingBuilder::aMapping()
            ->asSystem()
            ->withCostCategoryCode($mapping->getCostCategoryCode())
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withUnitEconomyCostType(UnitEconomyCostType::LOGISTICS_TO)
            ->build();

        $this->repository->method('findByIdAndCompany')
            ->with(self::MAPPING_ID, self::COMPANY_ID)
            ->willReturn($mapping);

        $this->repository->method('findSystemMapping')
            ->with($mapping->getMarketplace(), $mapping->getCostCategoryCode())
            ->willReturn($systemMapping);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = ($this->action)(self::COMPANY_ID, self::MAPPING_ID);

        $this->assertSame(UnitEconomyCostType::LOGISTICS_TO, $result->getUnitEconomyCostType());
    }

    public function testThrowsWhenMappingAlreadySystem(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->asSystem()
            ->build();

        $this->repository->method('findByIdAndCompany')
            ->with(self::MAPPING_ID, self::COMPANY_ID)
            ->willReturn($mapping);

        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('уже имеет системное значение');

        ($this->action)(self::COMPANY_ID, self::MAPPING_ID);
    }

    public function testThrowsWhenMappingNotFound(): void
    {
        $this->repository->method('findByIdAndCompany')
            ->with(self::MAPPING_ID, self::COMPANY_ID)
            ->willReturn(null);

        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->expectException(\DomainException::class);

        ($this->action)(self::COMPANY_ID, self::MAPPING_ID);
    }

    public function testThrowsWhenSystemMappingNotFound(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->asCustom()
            ->build();

        $this->repository->method('findByIdAndCompany')
            ->with(self::MAPPING_ID, self::COMPANY_ID)
            ->willReturn($mapping);

        $this->repository->method('findSystemMapping')
            ->willReturn(null);

        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Системный маппинг');

        ($this->action)(self::COMPANY_ID, self::MAPPING_ID);
    }
}
