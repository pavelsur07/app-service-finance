<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Application\DeleteCostMappingAction;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeleteCostMappingActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const MAPPING_ID = '55555555-5555-5555-5555-555555555555';

    private UnitEconomyCostMappingRepositoryInterface&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private DeleteCostMappingAction $action;

    protected function setUp(): void
    {
        $this->repository    = $this->createMock(UnitEconomyCostMappingRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->action = new DeleteCostMappingAction(
            $this->repository,
            $this->entityManager,
        );
    }

    public function testDeletesExistingMapping(): void
    {
        $this->repository->expects($this->once())
            ->method('delete')
            ->with(self::MAPPING_ID, self::COMPANY_ID);

        $this->entityManager->expects($this->once())
            ->method('flush');

        ($this->action)(self::COMPANY_ID, self::MAPPING_ID);
    }

    public function testThrowsDomainExceptionWhenMappingNotFound(): void
    {
        $this->repository->method('delete')
            ->willThrowException(new \DomainException('Маппинг не найден'));

        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->expectException(\DomainException::class);

        ($this->action)(self::COMPANY_ID, self::MAPPING_ID);
    }
}
