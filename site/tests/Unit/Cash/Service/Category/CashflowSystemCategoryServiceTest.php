<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Category;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CashflowSystemCategoryServiceTest extends TestCase
{
    public function testReturnsExistingSystemCategoryWithoutPersist(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $existing = (new CashflowCategory('22222222-2222-2222-2222-222222222222', $company))
            ->setName('Не распределено')
            ->setSort(1000000)
            ->setParent(null)
            ->setSystemCode(CashflowCategory::SYSTEM_UNALLOCATED);

        $repository = $this->createMock(CashflowCategoryRepository::class);
        $repository
            ->expects(self::once())
            ->method('findSystemUnallocatedByCompany')
            ->with($company)
            ->willReturn($existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new CashflowSystemCategoryService($entityManager, $repository);

        self::assertSame($existing, $service->getOrCreateUnallocated($company));
    }

    public function testCreatesSystemCategoryWhenMissing(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $repository = $this->createMock(CashflowCategoryRepository::class);
        $repository
            ->expects(self::once())
            ->method('findSystemUnallocatedByCompany')
            ->with($company)
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = new CashflowSystemCategoryService($entityManager, $repository);
        $created = $service->getOrCreateUnallocated($company);

        self::assertSame('Не распределено', $created->getName());
        self::assertNull($created->getParent());
        self::assertSame(1000000, $created->getSort());
        self::assertSame(CashflowCategory::SYSTEM_UNALLOCATED, $created->getSystemCode());
        self::assertSame($company, $created->getCompany());
    }
}
