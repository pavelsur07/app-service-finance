<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Transaction;

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
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
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

        $service = $this->createService();

        self::assertSame(CashTransactionAutoRuleSkipReason::DELETED, $service->getSkipReason($transaction));
        self::assertNull($service->findMatchingRule($transaction));
        self::assertNull($service->applyRule($rule, $transaction));
        self::assertNull($transaction->getCashflowCategory());
    }

    public function testDoesNotApplyRuleBeforeFinanceLockDate(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2024-01-15'));
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $transaction->setOccurredAt(new \DateTimeImmutable('2024-01-14'));
        $rule = $this->createRule($company);

        $service = $this->createService();

        self::assertSame(CashTransactionAutoRuleSkipReason::LOCKED_PERIOD, $service->getSkipReason($transaction));
        self::assertNull($service->findMatchingRule($transaction));
        self::assertNull($service->applyRule($rule, $transaction));
        self::assertNull($transaction->getCashflowCategory());
    }

    public function testAppliesRuleOnFinanceLockBoundary(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2024-01-15'));
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $transaction->setOccurredAt(new \DateTimeImmutable('2024-01-15'));
        $rule = $this->createRule($company);

        $service = $this->createService(rules: [$rule]);

        self::assertNull($service->getSkipReason($transaction));
        self::assertTrue($service->applyRule($rule, $transaction)?->hasChanges());
        self::assertSame($rule->getCashflowCategory(), $transaction->getCashflowCategory());
    }

    public function testDoesNotApplyInactiveRule(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $rule = $this->createRule($company)->setIsActive(false);

        $service = $this->createService();

        self::assertNull($service->applyRule($rule, $transaction));
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

        $service = $this->createService(rules: [$rule]);

        self::assertTrue($service->applyRule($rule, $transaction)?->hasChanges());
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

        $service = $this->createService(rules: [$rule]);

        self::assertFalse($service->applyRule($rule, $transaction)?->hasChanges());
        self::assertSame($assignedCategory, $transaction->getCashflowCategory());
    }

    public function testFillRuleDoesNotReportChangeWhenUnallocatedTargetIsAlreadySet(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $categoryId = Uuid::uuid4()->toString();
        $unallocated = CashflowCategoryBuilder::aCashflowCategory()
            ->withId($categoryId)
            ->withCompany($company)
            ->withName('Не распределено')
            ->build()
            ->markAsSystem(CashflowCategory::CODE_UNALLOCATED);
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withCashflowCategory($unallocated)
            ->build();
        $sameCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withId($categoryId)
            ->withCompany($company)
            ->build();
        $rule = $this->createRule($company)->setCashflowCategory($sameCategory);

        $service = $this->createService(rules: [$rule]);

        self::assertFalse($service->applyRule($rule, $transaction)?->hasChanges());
        self::assertSame($unallocated, $transaction->getCashflowCategory());
    }

    public function testUpdateRuleComparesTargetsById(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $categoryId = Uuid::uuid4()->toString();
        $projectId = Uuid::uuid4()->toString();
        $counterpartyId = Uuid::uuid4()->toString();
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withCashflowCategory(CashflowCategoryBuilder::aCashflowCategory()->withId($categoryId)->withCompany($company)->build())
            ->build()
            ->setProjectDirection(new ProjectDirection($projectId, $company, 'Current project'))
            ->setCounterparty(CounterpartyBuilder::aCounterparty()->withId($counterpartyId)->withCompany($company)->build());
        $rule = $this->createRule(
            $company,
            CashflowCategoryBuilder::aCashflowCategory()->withId($categoryId)->withCompany($company)->build(),
        )
            ->setAction(CashTransactionAutoRuleAction::UPDATE)
            ->setProjectDirection(new ProjectDirection($projectId, $company, 'Rule project'))
            ->setCounterparty(CounterpartyBuilder::aCounterparty()->withId($counterpartyId)->withCompany($company)->build());

        $service = $this->createService(rules: [$rule]);
        $plan = $service->createApplicationPlan($service->match($transaction), $transaction);

        self::assertSame([], $plan->changes);
    }

    public function testDetectsConflictBetweenEqualPriorityRulesWithDifferentTargets(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $firstRule = $this->createRule($company, id: '11111111-1111-1111-1111-111111111111');
        $secondCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->build();
        $secondRule = $this->createRule($company, $secondCategory, '22222222-2222-2222-2222-222222222222');
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->with($company)->willReturn([$firstRule, $secondRule]);

        $result = $this->createService($repository)->match($transaction);

        self::assertTrue($result->hasConflict());
        self::assertNull($result->rule);
        self::assertSame([$firstRule, $secondRule], $result->conflictingRules);
    }

    public function testEqualPriorityRulesWithSameEffectAreNotAConflict(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $firstRule = $this->createRule($company, id: '11111111-1111-1111-1111-111111111111');
        $secondRule = $this->createRule(
            $company,
            $firstRule->getCashflowCategory(),
            '22222222-2222-2222-2222-222222222222',
        );
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->with($company)->willReturn([$firstRule, $secondRule]);

        $result = $this->createService($repository)->match($transaction);

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

        $result = $this->createService($repository)->match($transaction);

        self::assertFalse($result->hasConflict());
        self::assertSame($winner, $result->rule);
    }

    public function testResolvesAndAppliesIndependentFieldWinners(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $categoryRule = $this->createRule(
            $company,
            id: '11111111-1111-1111-1111-111111111111',
        )->setPriority(300);
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project');
        $projectRule = $this->createRule(
            $company,
            id: '22222222-2222-2222-2222-222222222222',
        )->setPriority(200)->setProjectDirection($project);
        $counterparty = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $counterpartyRule = $this->createRule(
            $company,
            id: '33333333-3333-3333-3333-333333333333',
        )->setPriority(100)->setCounterparty($counterparty);
        $service = $this->createService(rules: [$counterpartyRule, $projectRule, $categoryRule]);

        $match = $service->match($transaction);
        $plan = $service->applyRule($categoryRule, $transaction, $match);

        self::assertSame([$categoryRule, $projectRule, $counterpartyRule], $match->matchingRules);
        self::assertSame($categoryRule, $match->winners['cashflowCategory']);
        self::assertSame($projectRule, $match->winners['projectDirection']);
        self::assertSame($counterpartyRule, $match->winners['counterparty']);
        self::assertSame($categoryRule->getCashflowCategory(), $transaction->getCashflowCategory());
        self::assertSame($project, $transaction->getProjectDirection());
        self::assertSame($counterparty, $transaction->getCounterparty());
        self::assertSame([
            'cashflowCategory' => $categoryRule,
            'projectDirection' => $projectRule,
            'counterparty' => $counterpartyRule,
        ], $plan?->rulesByField);
    }

    public function testSkipsOnlyConflictingField(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project');
        $firstRule = $this->createRule(
            $company,
            id: '11111111-1111-1111-1111-111111111111',
        )->setProjectDirection($project);
        $secondRule = $this->createRule(
            $company,
            CashflowCategoryBuilder::aCashflowCategory()
                ->withId(Uuid::uuid4()->toString())
                ->withCompany($company)
                ->build(),
            '22222222-2222-2222-2222-222222222222',
        )->setProjectDirection($project);
        $service = $this->createService(rules: [$secondRule, $firstRule]);

        $match = $service->match($transaction);
        $plan = $service->applyMatch($transaction, $match);

        self::assertSame(['cashflowCategory'], array_keys($match->conflicts));
        self::assertSame($firstRule, $match->winners['projectDirection']);
        self::assertNull($transaction->getCashflowCategory());
        self::assertSame($project, $transaction->getProjectDirection());
        self::assertSame(['projectDirection'], array_keys($plan?->changes ?? []));
    }

    public function testMoreSpecificRuleWinsAtEqualPriority(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $transaction->setDescription('Оплата аренды');
        $generalRule = $this->createRule(
            $company,
            id: '11111111-1111-1111-1111-111111111111',
        );
        $specificRule = $this->createRule(
            $company,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
            '22222222-2222-2222-2222-222222222222',
        );
        $specificRule->addCondition(new CashTransactionAutoRuleCondition(
            autoRule: $specificRule,
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'аренд',
        ));

        $match = $this->createService(rules: [$generalRule, $specificRule])->match($transaction);

        self::assertSame($specificRule, $match->winners['cashflowCategory']);
        self::assertFalse($match->hasConflict());
    }

    public function testUpdateRulePreservesManualValuesAndIgnoresNullTargets(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $manualCategory = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $manualProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Manual project');
        $manualCounterparty = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withCashflowCategory($manualCategory)
            ->build()
            ->setProjectDirection($manualProject)
            ->setCounterparty($manualCounterparty);
        $rule = $this->createRule($company)->setAction(CashTransactionAutoRuleAction::UPDATE);
        $service = $this->createService(rules: [$rule]);

        $plan = $service->applyRule($rule, $transaction);

        self::assertFalse($plan?->hasChanges());
        self::assertSame($manualCategory, $transaction->getCashflowCategory());
        self::assertSame($manualProject, $transaction->getProjectDirection());
        self::assertSame($manualCounterparty, $transaction->getCounterparty());
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
        $service = $this->createService($repository);

        $preview = $service->previewRule($rule, [$otherTransaction, $matchingTransaction], 10);

        self::assertCount(1, $preview->rows);
        self::assertSame($matchingTransaction, $preview->rows[0]['transaction']);
        self::assertSame($rule, $preview->rows[0]['match']->rule);
        self::assertSame($service->match($matchingTransaction)->rule, $preview->rows[0]['match']->rule);
        self::assertSame($rule, $preview->rows[0]['plan']?->rule);
        self::assertTrue($preview->rows[0]['plan']?->hasChanges());
    }

    public function testMatchesLocalizedDecimalAmount(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withAmount('100.50')
            ->build();
        $rule = $this->createRule($company);
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            autoRule: $rule,
            field: CashTransactionAutoRuleConditionField::AMOUNT,
            operator: CashTransactionAutoRuleConditionOperator::BETWEEN,
            value: '100,00',
            valueTo: '101,00',
        ));
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->with($company)->willReturn([$rule]);

        $match = $this->createService($repository)->match($transaction);

        self::assertSame($rule, $match->rule);
    }

    public function testPreviewIncludesInactiveRuleWithoutSelectingItAsWinner(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $rule = $this->createRule($company)->setIsActive(false);
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->with($company)->willReturn([]);
        $service = $this->createService($repository);

        $preview = $service->previewRule($rule, [$transaction], 10);

        self::assertCount(1, $preview->rows);
        self::assertSame($transaction, $preview->rows[0]['transaction']);
        self::assertNull($preview->rows[0]['match']->rule);
        self::assertNull($preview->rows[0]['plan']);
    }

    public function testPreviewStatisticsCoverAllMatchesBeyondTheRowLimitWithoutMutation(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $targetCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withId('11111111-1111-1111-1111-111111111111')
            ->withCompany($company)
            ->withName('аренда')
            ->build();
        $assignedCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withId('22222222-2222-2222-2222-222222222222')
            ->withCompany($company)
            ->withName('Банк')
            ->build();
        $rule = $this->createRule($company, $targetCategory);
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            autoRule: $rule,
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'match',
        ));
        $first = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $first->setDescription('match one')->setOccurredAt(new \DateTimeImmutable('2024-01-15'));
        $second = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withCashflowCategory($assignedCategory)
            ->build();
        $second
            ->setDescription('match two')
            ->setOccurredAt(new \DateTimeImmutable('2024-02-15'))
            ->setCurrency('USD');
        $skipped = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $skipped
            ->setDescription('match deleted')
            ->setOccurredAt(new \DateTimeImmutable('2024-03-15'))
            ->setCurrency('EUR')
            ->markDeleted(null);
        $other = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $other->setDescription('other')->setOccurredAt(new \DateTimeImmutable('2024-04-15'));

        $preview = $this->createService(rules: [$rule])->previewRule(
            $rule,
            [$first, $second, $skipped, $other],
            1,
        );

        self::assertCount(1, $preview->rows);
        self::assertSame($first, $preview->rows[0]['transaction']);
        self::assertSame([
            'scanned' => 4,
            'matched' => 3,
            'wouldChange' => 1,
            'noChange' => 2,
            'skipped' => 1,
            'conflicts' => 0,
        ], $preview->summary);
        self::assertSame([
            'cashflowCategory' => 1,
            'projectDirection' => 0,
            'counterparty' => 0,
        ], $preview->changesByField);
        self::assertSame(['2024-03', '2024-02', '2024-01'], array_column($preview->byMonth, 'key'));
        self::assertSame(['EUR', 'RUB', 'USD'], array_column($preview->byCurrency, 'key'));
        self::assertSame(['аренда', 'Банк', 'Не задано'], array_column($preview->byCategory, 'label'));
        self::assertSame(3, $preview->byProject[0]['matched']);
        self::assertSame(1, $preview->byProject[0]['wouldChange']);
        self::assertNull($first->getCashflowCategory());
        self::assertSame($assignedCategory, $second->getCashflowCategory());
    }

    public function testPreviewReportsFieldConflictWithoutGuessingAChange(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $firstRule = $this->createRule(
            $company,
            CashflowCategoryBuilder::aCashflowCategory()
                ->withId('11111111-1111-1111-1111-111111111111')
                ->withCompany($company)
                ->build(),
            '33333333-3333-3333-3333-333333333333',
        );
        $secondRule = $this->createRule(
            $company,
            CashflowCategoryBuilder::aCashflowCategory()
                ->withId('22222222-2222-2222-2222-222222222222')
                ->withCompany($company)
                ->build(),
            '44444444-4444-4444-4444-444444444444',
        );

        $preview = $this->createService(rules: [$firstRule, $secondRule])->previewRule(
            $firstRule,
            [$transaction],
            10,
        );

        self::assertSame(1, $preview->summary['matched']);
        self::assertSame(1, $preview->summary['conflicts']);
        self::assertSame(0, $preview->summary['wouldChange']);
        self::assertSame(1, $preview->summary['noChange']);
        self::assertSame(['cashflowCategory'], array_keys($preview->rows[0]['match']->conflicts));
        self::assertNull($preview->rows[0]['plan']);
        self::assertNull($transaction->getCashflowCategory());
    }

    public function testApplicationPlanDoesNotMutateTransactionAndRecordsChanges(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $rule = $this->createRule($company);
        $service = $this->createService(rules: [$rule]);
        $correlationId = Uuid::uuid7()->toString();

        $plan = $service->createApplicationPlan($service->match($transaction), $transaction);

        self::assertNull($transaction->getCashflowCategory());
        self::assertSame([
            'cashflowCategory' => [
                'before' => null,
                'after' => $rule->getCashflowCategory()?->getId(),
            ],
        ], $plan->changes);
        self::assertSame([
            'correlationId' => $correlationId,
            'autoRules' => [
                'cashflowCategory' => [
                    'id' => $rule->getId(),
                    'revision' => 1,
                ],
            ],
            'changes' => $plan->changes,
        ], $plan->auditDiff($correlationId));
    }

    public function testDoesNotApplyRuleWithTargetFromAnotherCompany(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $rule = $this->createRule(
            $company,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($otherCompany)->build(),
        );
        $service = $this->createService(rules: [$rule]);

        self::assertNull($service->applyRule($rule, $transaction));
        self::assertNull($transaction->getCashflowCategory());
    }

    public function testDoesNotApplyRuleThatIsNotTheCurrentWinner(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $winner = $this->createRule($company)->setPriority(200);
        $lowerPriorityRule = $this->createRule($company)->setPriority(100);
        $service = $this->createService(rules: [$winner, $lowerPriorityRule]);

        self::assertNull($service->applyRule($lowerPriorityRule, $transaction));
        self::assertNull($transaction->getCashflowCategory());
    }

    private function createService(
        ?CashTransactionAutoRuleRepository $repository = null,
        array $rules = [],
    ): CashTransactionAutoRuleService {
        if (null === $repository) {
            $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
            $repository->method('findActiveByCompany')->willReturn($rules);
        }

        return new CashTransactionAutoRuleService(
            $repository,
        );
    }

    private function createRule(
        Company $company,
        ?CashflowCategory $category = null,
        ?string $id = null,
    ): CashTransactionAutoRule {
        $category ??= CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();

        return new CashTransactionAutoRule(
            $id ?? Uuid::uuid4()->toString(),
            $company,
            'Test rule',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        );
    }
}
