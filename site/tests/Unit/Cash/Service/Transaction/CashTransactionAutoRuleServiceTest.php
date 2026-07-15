<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Transaction;

use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleSkipReason;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashTransactionAutoRuleServiceTest extends TestCase
{
    public function testDoesNotApplyRuleToDeletedTransaction(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $transaction->markDeleted(null);
        $rule = $this->createRule($company);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = $this->createService($entityManager);

        self::assertSame(CashTransactionAutoRuleSkipReason::DELETED, $service->getSkipReason($transaction));
        self::assertNull($service->findMatchingRule($transaction));
        self::assertFalse($service->applyRule($rule, $transaction));
        self::assertNull($transaction->getCashflowCategory());
    }

    public function testDoesNotApplyRuleBeforeFinanceLockDate(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2024-01-15'));
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $transaction->setOccurredAt(new \DateTimeImmutable('2024-01-14'));
        $rule = $this->createRule($company);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = $this->createService($entityManager);

        self::assertSame(CashTransactionAutoRuleSkipReason::LOCKED_PERIOD, $service->getSkipReason($transaction));
        self::assertNull($service->findMatchingRule($transaction));
        self::assertFalse($service->applyRule($rule, $transaction));
        self::assertNull($transaction->getCashflowCategory());
    }

    public function testAppliesRuleOnFinanceLockBoundary(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2024-01-15'));
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $transaction->setOccurredAt(new \DateTimeImmutable('2024-01-15'));
        $rule = $this->createRule($company);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $service = $this->createService($entityManager);

        self::assertNull($service->getSkipReason($transaction));
        self::assertTrue($service->applyRule($rule, $transaction));
        self::assertSame($rule->getCashflowCategory(), $transaction->getCashflowCategory());
    }

    public function testDoesNotApplyInactiveRule(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $rule = $this->createRule($company)->setIsActive(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = $this->createService($entityManager);

        self::assertFalse($service->applyRule($rule, $transaction));
        self::assertNull($transaction->getCashflowCategory());
    }

    public function testFillRuleReplacesUnallocatedCategory(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $unallocated = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->withName('Не распределено')
            ->build()
            ->markAsSystem(CashflowCategory::CODE_UNALLOCATED);
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withCashflowCategory($unallocated)
            ->build();
        $rule = $this->createRule($company);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $service = $this->createService($entityManager);

        self::assertTrue($service->applyRule($rule, $transaction));
        self::assertSame($rule->getCashflowCategory(), $transaction->getCashflowCategory());
    }

    public function testFillRulePreservesAssignedCategory(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $assignedCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->build();
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withCashflowCategory($assignedCategory)
            ->build();
        $rule = $this->createRule($company);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = $this->createService($entityManager);

        self::assertFalse($service->applyRule($rule, $transaction));
        self::assertSame($assignedCategory, $transaction->getCashflowCategory());
    }

    public function testFillRuleDoesNotReportChangeWhenUnallocatedTargetIsAlreadySet(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $unallocated = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->withName('Не распределено')
            ->build()
            ->markAsSystem(CashflowCategory::CODE_UNALLOCATED);
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withCashflowCategory($unallocated)
            ->build();
        $rule = $this->createRule($company)->setCashflowCategory($unallocated);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = $this->createService($entityManager);

        self::assertFalse($service->applyRule($rule, $transaction));
        self::assertSame($unallocated, $transaction->getCashflowCategory());
    }

    public function testDetectsConflictBetweenEqualPriorityRulesWithDifferentTargets(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $firstRule = $this->createRule($company);
        $secondCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->build();
        $secondRule = $this->createRule($company, $secondCategory);
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->with($company)->willReturn([$firstRule, $secondRule]);

        $result = $this->createService(
            $this->createMock(EntityManagerInterface::class),
            $repository,
        )->match($transaction);

        self::assertTrue($result->hasConflict());
        self::assertNull($result->rule);
        self::assertSame([$firstRule, $secondRule], $result->conflictingRules);
    }

    public function testEqualPriorityRulesWithSameEffectAreNotAConflict(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $firstRule = $this->createRule($company);
        $secondRule = $this->createRule($company, $firstRule->getCashflowCategory());
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->with($company)->willReturn([$firstRule, $secondRule]);

        $result = $this->createService(
            $this->createMock(EntityManagerInterface::class),
            $repository,
        )->match($transaction);

        self::assertFalse($result->hasConflict());
        self::assertSame($firstRule, $result->rule);
    }

    public function testLowerPriorityRuleDoesNotConflictWithWinner(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $winner = $this->createRule($company)->setPriority(200);
        $lowerCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->build();
        $lowerPriorityRule = $this->createRule($company, $lowerCategory)->setPriority(100);
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->with($company)->willReturn([$winner, $lowerPriorityRule]);

        $result = $this->createService(
            $this->createMock(EntityManagerInterface::class),
            $repository,
        )->match($transaction);

        self::assertFalse($result->hasConflict());
        self::assertSame($winner, $result->rule);
    }

    public function testPreviewUsesTheSameMatcherAsApplication(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $matchingTransaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $matchingTransaction->setDescription('Оплата аренды офиса');
        $otherTransaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $otherTransaction->setDescription('Комиссия банка');
        $rule = $this->createRule($company);
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            autoRule: $rule,
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'аренд',
        ));
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->with($company)->willReturn([$rule]);
        $service = $this->createService($this->createMock(EntityManagerInterface::class), $repository);

        $rows = $service->previewRule($rule, [$otherTransaction, $matchingTransaction], 10);

        self::assertCount(1, $rows);
        self::assertSame($matchingTransaction, $rows[0]['transaction']);
        self::assertSame($rule, $rows[0]['match']->rule);
        self::assertSame($service->match($matchingTransaction)->rule, $rows[0]['match']->rule);
    }

    public function testPreviewIncludesInactiveRuleWithoutSelectingItAsWinner(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $rule = $this->createRule($company)->setIsActive(false);
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->with($company)->willReturn([]);
        $service = $this->createService($this->createMock(EntityManagerInterface::class), $repository);

        $rows = $service->previewRule($rule, [$transaction], 10);

        self::assertCount(1, $rows);
        self::assertSame($transaction, $rows[0]['transaction']);
        self::assertNull($rows[0]['match']->rule);
    }

    private function createService(
        EntityManagerInterface $entityManager,
        ?CashTransactionAutoRuleRepository $repository = null,
    ): CashTransactionAutoRuleService {
        return new CashTransactionAutoRuleService(
            $repository ?? $this->createMock(CashTransactionAutoRuleRepository::class),
            $entityManager,
            new AutoRuleDispatchGuard(),
        );
    }

    private function createRule(Company $company, ?CashflowCategory $category = null): CashTransactionAutoRule
    {
        $category ??= CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();

        return new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Test rule',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        );
    }
}
