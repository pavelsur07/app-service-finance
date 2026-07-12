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
        $entityManager->expects(self::never())->method('flush');

        $service = new CashflowSystemCategoryService($entityManager, $repository);
        $created = $service->getOrCreateUnallocated($company);

        self::assertSame('Не распределено', $created->getName());
        self::assertNull($created->getParent());
        self::assertSame(50, $created->getSort());
        self::assertSame(CashflowCategory::CODE_UNALLOCATED, $created->getCode());
        self::assertTrue($created->isSystem());
        self::assertSame($company, $created->getCompany());
    }

    public function testCreatesCompleteSystemStructureWithoutFlush(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $repository = $this->createMock(CashflowCategoryRepository::class);
        $repository->method('findOneByCompanyAndCode')->willReturn(null);
        $repository->method('findSystemUnallocatedByCompany')->willReturn(null);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(7))
            ->method('persist')
            ->willReturnCallback(static function (CashflowCategory $category) use (&$persisted): void {
                $persisted[] = $category;
            });
        $entityManager->expects(self::never())->method('flush');

        $structure = (new CashflowSystemCategoryService($entityManager, $repository))->ensureStructure($company);

        self::assertCount(7, $structure);
        self::assertCount(7, $persisted);
        self::assertSame(
            $structure[CashflowCategory::CODE_TECHNICAL],
            $structure[CashflowCategory::CODE_TECHNICAL_IN]->getParent(),
        );
        self::assertSame(
            $structure[CashflowCategory::CODE_TECHNICAL],
            $structure[CashflowCategory::CODE_TECHNICAL_OUT]->getParent(),
        );

        foreach ($structure as $code => $category) {
            self::assertSame($code, $category->getCode());
            self::assertTrue($category->isSystem());
        }
    }
}
