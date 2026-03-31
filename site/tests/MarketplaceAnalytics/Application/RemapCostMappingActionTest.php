<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Application\RemapCostMappingAction;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use App\Tests\Builders\MarketplaceAnalytics\UnitEconomyCostMappingBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RemapCostMappingActionTest extends TestCase
{
    private const COMPANY_ID  = '11111111-1111-1111-1111-111111111111';
    private const MAPPING_ID  = '55555555-5555-5555-5555-555555555555';

    private UnitEconomyCostMappingRepositoryInterface&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private RemapCostMappingAction $action;

    protected function setUp(): void
    {
        $this->repository    = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->action = new RemapCostMappingAction(
            $this->repository,
            $this->entityManager,
        );
    }

    public function testRemapChangesUnitEconomyCostType(): void
    {
        $mapping = UnitEconomyCostMappingBuilder::aMapping()
            ->withUnitEconomyCostType(UnitEconomyCostType::LOGISTICS_TO)
            ->build();

        $this->repository->method('findById')
            ->with(self::MAPPING_ID, self::COMPANY_ID)
            ->willReturn($mapping);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = ($this->action)(self::COMPANY_ID, self::MAPPING_ID, UnitEconomyCostType::COMMISSION);

        $this->assertSame(UnitEconomyCostType::COMMISSION, $result->getUnitEconomyCostType());
    }

    public function testThrowsDomainExceptionWhenMappingNotFound(): void
    {
        $this->repository->method('findById')
            ->with(self::MAPPING_ID, self::COMPANY_ID)
            ->willReturn(null);

        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->expectException(\DomainException::class);

        ($this->action)(self::COMPANY_ID, self::MAPPING_ID, UnitEconomyCostType::COMMISSION);
    }

    public function testThrowsDomainExceptionOnIdorAttempt(): void
    {
        // Маппинг принадлежит другой компании — findById возвращает null
        // так как репозиторий фильтрует по companyId (IDOR-защита)
        $otherCompanyId = '22222222-2222-2222-2222-222222222222';

        $this->repository->method('findById')
            ->with(self::MAPPING_ID, $otherCompanyId)
            ->willReturn(null);

        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->expectException(\DomainException::class);

        ($this->action)($otherCompanyId, self::MAPPING_ID, UnitEconomyCostType::COMMISSION);
    }
}
