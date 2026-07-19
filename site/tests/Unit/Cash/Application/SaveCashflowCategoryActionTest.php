<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\SaveCashflowCategoryAction;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SaveCashflowCategoryActionTest extends TestCase
{
    public function testSavesCategoryAndInheritsParentFlowKind(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $parent = $this->category('11111111-1111-4111-8111-111111111111', $company)
            ->setFlowKind(CashflowFlowKind::INVESTING);
        $category = $this->category('22222222-2222-4222-8222-222222222222', $company)
            ->setParent($parent);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($category);
        $entityManager->expects(self::once())->method('flush');

        ($this->action($entityManager))($category);

        self::assertSame(CashflowFlowKind::INVESTING, $category->getFlowKind());
    }

    public function testRejectsNestingDeeperThanFiveLevels(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $parent = null;
        for ($level = 1; $level <= 5; ++$level) {
            $current = $this->category(sprintf('%08d-1111-4111-8111-111111111111', $level), $company);
            if (null !== $parent) {
                $current->setParent($parent);
            }
            $parent = $current;
        }

        $sixth = $this->category('99999999-9999-4999-8999-999999999999', $company)
            ->setParent($parent);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Максимальная вложенность — 5 уровней');

        ($this->action($entityManager))($sixth);
    }

    public function testRejectsParentFromAnotherCompany(): void
    {
        $foreignParent = $this->category(
            '11111111-1111-4111-8111-111111111111',
            CompanyBuilder::aCompany()->withIndex(2)->build(),
        );
        $category = $this->category(
            '22222222-2222-4222-8222-222222222222',
            CompanyBuilder::aCompany()->withIndex(1)->build(),
        )->setParent($foreignParent);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Родительская статья принадлежит другой компании.');

        ($this->action($entityManager))($category);
    }

    public function testRejectsInvalidEntity(): void
    {
        $category = $this->category(
            '22222222-2222-4222-8222-222222222222',
            CompanyBuilder::aCompany()->build(),
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $violations = new ConstraintViolationList([
            new ConstraintViolation('Код уже используется в этой компании.', null, [], null, 'code', null),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Код уже используется в этой компании.');

        ($this->action($entityManager, $violations))($category);
    }

    private function category(string $id, Company $company): CashflowCategory
    {
        return (new CashflowCategory($id, $company))->setName('Статья');
    }

    private function action(
        EntityManagerInterface $entityManager,
        ?ConstraintViolationList $violations = null,
    ): SaveCashflowCategoryAction {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn($violations ?? new ConstraintViolationList());

        return new SaveCashflowCategoryAction($entityManager, $validator);
    }
}
