<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service\AutoRule;

use App\Cash\Application\Service\AutoRule\CashAutoRuleApplicationPlanner;
use App\Cash\Application\Service\AutoRule\CashAutoRuleMatcher;
use App\Cash\Application\Service\AutoRule\CashAutoRulePreviewBuilder;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashAutoRulePreviewBuilderTest extends TestCase
{
    public function testDisabledRuleContributesToPreviewWithoutMutatingTheRuleOrTransaction(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $rule = new CashTransactionAutoRule(Uuid::uuid7()->toString(), $company, 'Кандидат', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $category);
        $rule->disable();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->willReturn([]);
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $builder = new CashAutoRulePreviewBuilder($repository, $facade, new CashAutoRuleMatcher(), new CashAutoRuleApplicationPlanner($facade));

        $preview = $builder->forRule($rule, [$transaction], 10);

        self::assertSame(1, $preview->summary['wouldChange']);
        self::assertSame($rule, $preview->rows[0]['match']->winners['cashflowCategory']);
        self::assertFalse($rule->isActive());
        self::assertNull($transaction->getCashflowCategory());
    }

    public function testEditingReplacesOldDefinitionAndKeepsManualCategorySeparateFromWinningRule(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->withId(Uuid::uuid7()->toString())->build();
        $otherCategory = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->withId(Uuid::uuid7()->toString())->build();
        $old = new CashTransactionAutoRule(Uuid::uuid7()->toString(), $company, 'Старое', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $category);
        $other = new CashTransactionAutoRule(Uuid::uuid7()->toString(), $company, 'Другое', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $otherCategory);
        $draft = new CashTransactionAutoRule((string) $old->getId(), $company, 'Редакция', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $category);
        $draft->addCondition(new CashTransactionAutoRuleCondition(field: CashTransactionAutoRuleConditionField::DESCRIPTION, operator: CashTransactionAutoRuleConditionOperator::NOT_CONTAINS, value: 'обеспечительный платёж'));
        $transactions = [];
        foreach (['Аренда', 'Аренда', 'Обеспечительный платеж', ''] as $description) {
            $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
            $transaction->setDescription($description);
            $transactions[] = $transaction;
        }
        $transactions[1]->setCashflowCategory($otherCategory);
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->willReturn([$old, $other]);
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $builder = new CashAutoRulePreviewBuilder($repository, $facade, new CashAutoRuleMatcher(), new CashAutoRuleApplicationPlanner($facade));

        $result = $builder->forRule($draft, $transactions, 1);
        $metrics = $result->impact->metrics;

        self::assertSame(4, $metrics['conflictRemoved.cashflowCategory']['count']);
        self::assertSame(4, $metrics['winner.cashflowCategory']['count']);
        self::assertSame(3, $metrics['value.cashflowCategory']['count']);
        self::assertSame(3, $metrics['newCategory']['count']);
        self::assertSame(2, $metrics['oldOnly']['count']);
        self::assertSame(1, $metrics['excluded']['count']);
        self::assertSame($transactions[2], $metrics['excluded']['examples'][0]['transaction']);
        self::assertSame($draft, $result->rows[0]['match']->winners['cashflowCategory']);
        self::assertSame($otherCategory, $transactions[1]->getCashflowCategory());

        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->method('findActiveByCompany')->willReturn([$other]);
        $newRulePreview = (new CashAutoRulePreviewBuilder($repository, $facade, new CashAutoRuleMatcher(), new CashAutoRuleApplicationPlanner($facade)))
            ->forRule($old, [$transactions[0]], 1);
        self::assertSame(1, $newRulePreview->impact->metrics['conflictAdded.cashflowCategory']['count']);
        self::assertArrayNotHasKey('conflictAdded.projectDirection', $newRulePreview->impact->metrics);
    }

    public function testClosedPeriodAndManualSplitHaveSeparateExamples(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $lock = new \DateTimeImmutable('2024-02-01');
        $company->setFinanceLockBefore($lock);
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $secondCategory = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->withId(Uuid::uuid7()->toString())->build();
        $rule = new CashTransactionAutoRule(Uuid::uuid7()->toString(), $company, 'Аренда', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $category);
        $locked = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $locked->setOccurredAt($lock->modify('-1 day'));
        $split = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withAmount('100.00')->build();
        $split->setOccurredAt($lock);
        $split->replaceSplits([
            new CashTransactionSplit($split, $category, '30.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($split, $secondCategory, '70.00', CashTransactionSplitSource::MANUAL),
        ]);
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $builder = new CashAutoRulePreviewBuilder($this->createMock(CashTransactionAutoRuleRepository::class), $facade, new CashAutoRuleMatcher(), new CashAutoRuleApplicationPlanner($facade));

        $result = $builder->forRule($rule, [$locked, $split], 1);

        self::assertSame(0, $result->summary['wouldChange']);
        self::assertSame($locked, $result->impact->metrics['SKIPPED_LOCKED_PERIOD']['examples'][0]['transaction']);
        self::assertSame($split, $result->impact->metrics['SKIPPED_MANUAL_SPLIT']['examples'][0]['transaction']);
        self::assertCount(2, $split->getSplits());
    }

    public function testGeneratorBeyondLimitUsesSingleSnapshotsAndKeepsPreviewPlanPure(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project');
        $centerId = Uuid::uuid4()->toString();
        $rule = new CashTransactionAutoRule(Uuid::uuid4()->toString(), $company, 'Pair', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY);
        $rule->setProjectDirection($project)->setResponsibilityCenterId($centerId);
        $transactions = [
            CashTransactionBuilder::aCashTransaction()->forCompany($company)->build(),
            CashTransactionBuilder::aCashTransaction()->forCompany($company)->build(),
            CashTransactionBuilder::aCashTransaction()->forCompany($company)->build(),
        ];
        $transactions[2]->markDeleted(null);
        $pairs = [new FinancialResponsibilityCenterProjectDTO((string) $project->getId(), $centerId, 'Center')];
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->expects(self::once())->method('findActiveByCompany')->with($company)->willReturn([$rule]);
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade->expects(self::once())->method('getActivePairs')->with($company->getId())->willReturn($pairs);
        $facade->expects(self::never())->method('getActiveChoices');
        $matcher = new CashAutoRuleMatcher();
        $planner = new CashAutoRuleApplicationPlanner($facade);
        $preview = new CashAutoRulePreviewBuilder($repository, $facade, $matcher, $planner);
        $stream = static function () use ($transactions): \Generator { yield from $transactions; };

        $result = $preview->forRule($rule, $stream(), 1);

        self::assertCount(1, $result->rows);
        self::assertSame(['scanned' => 3, 'matched' => 3, 'wouldChange' => 2, 'noChange' => 1, 'skipped' => 1, 'conflicts' => 0], $result->summary);
        self::assertSame(2, $result->changesByField['projectDirection']);
        self::assertSame('Center', $result->byResponsibilityCenter[0]['label']);
        self::assertEquals($planner->create($matcher->match($transactions[0], [$rule]), $transactions[0], $pairs), $result->rows[0]['plan']);
        foreach ($transactions as $transaction) {
            self::assertNull($transaction->getProjectDirection());
            self::assertNull($transaction->getResponsibilityCenterId());
        }
    }

    public function testSkippedSinglePreviewDoesNotLoadRulesOrBuildPlan(): void
    {
        $transaction = CashTransactionBuilder::aCashTransaction()->build();
        $transaction->markDeleted(null);
        $repository = $this->createMock(CashTransactionAutoRuleRepository::class);
        $repository->expects(self::never())->method('findActiveByCompany');
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade->expects(self::never())->method('getActivePairs');
        $facade->expects(self::once())->method('getActiveChoices')->willReturn([]);
        $builder = new CashAutoRulePreviewBuilder($repository, $facade, new CashAutoRuleMatcher(), new CashAutoRuleApplicationPlanner($facade));

        $preview = $builder->forTransaction($transaction);

        self::assertNull($preview['rule']);
        self::assertNull($preview['plan']);
        self::assertSame([], $preview['conflicts']);
    }
}
