<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Application;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Application\AddCostMappingAction;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use App\Tests\Builders\MarketplaceAnalytics\UnitEconomyCostMappingBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AddCostMappingActionTest extends TestCase
{
    private const COMPANY_ID  = '11111111-1111-1111-1111-111111111111';
    private const CATEGORY_ID = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
    private const MARKETPLACE = 'wildberries';

    private UnitEconomyCostMappingRepositoryInterface&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private AddCostMappingAction $action;

    protected function setUp(): void
    {
        $this->repository    = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->action = new AddCostMappingAction(
            $this->repository,
            $this->entityManager,
        );
    }

    public function testCreatesAndReturnsMappingWhenNoDuplicate(): void
    {
        $this->repository->method('findOneByCategoryId')
            ->with(self::COMPANY_ID, self::MARKETPLACE, self::CATEGORY_ID)
            ->willReturn(null);

        $this->repository->expects($this->once())
            ->method('save');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = ($this->action)(
            self::COMPANY_ID,
            self::MARKETPLACE,
            self::CATEGORY_ID,
            'Логистика',
            UnitEconomyCostType::LOGISTICS_TO,
        );

        $this->assertSame(self::COMPANY_ID, $result->getCompanyId());
        $this->assertSame(MarketplaceType::WILDBERRIES, $result->getMarketplace());
        $this->assertSame(self::CATEGORY_ID, $result->getCostCategoryId());
        $this->assertSame('Логистика', $result->getCostCategoryName());
        $this->assertSame(UnitEconomyCostType::LOGISTICS_TO, $result->getUnitEconomyCostType());
    }

    public function testThrowsDomainExceptionWhenDuplicateExists(): void
    {
        $existing = UnitEconomyCostMappingBuilder::aMapping()->build();

        $this->repository->method('findOneByCategoryId')
            ->willReturn($existing);

        $this->repository->expects($this->never())
            ->method('save');

        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('уже существует');

        ($this->action)(
            self::COMPANY_ID,
            self::MARKETPLACE,
            self::CATEGORY_ID,
            'Логистика',
            UnitEconomyCostType::LOGISTICS_TO,
        );
    }
}
