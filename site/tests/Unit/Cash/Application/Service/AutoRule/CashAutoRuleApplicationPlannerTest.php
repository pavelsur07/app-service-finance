<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service\AutoRule;

use App\Cash\Application\DTO\CashTransactionAutoRuleProvenance;
use App\Cash\Application\Service\AutoRule\CashAutoRuleApplicationPlanner;
use App\Cash\Application\Service\AutoRule\CashAutoRuleMatcher;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionAutoRulePairIssue;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashAutoRuleApplicationPlannerTest extends TestCase
{
    public function testExplicitEmptyPairSnapshotDoesNotReloadUnavailablePairOrMutate(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project');
        $rule = new CashTransactionAutoRule(Uuid::uuid4()->toString(), $company, 'Pair', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY);
        $rule->setProjectDirection($project)->setResponsibilityCenterId(Uuid::uuid4()->toString());
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade->expects(self::never())->method('getActivePairs');
        $planner = new CashAutoRuleApplicationPlanner($facade);
        $match = (new CashAutoRuleMatcher())->match($transaction, [$rule]);

        $plan = $planner->create($match, $transaction, []);

        self::assertTrue($planner->requiresPairSnapshot([$rule]));
        self::assertFalse($planner->requiresPairSnapshot([]));
        self::assertSame(CashTransactionAutoRulePairIssue::UNAVAILABLE, $plan->pairIssue);
        self::assertFalse($plan->hasChanges());
        self::assertNull($transaction->getProjectDirection());
        self::assertNull($transaction->getResponsibilityCenterId());
    }

    public function testProvenanceOnlyAllowsReplacementInReplaceModeWithoutMutation(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $current = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $target = CashflowCategoryBuilder::aCashflowCategory()->withId(Uuid::uuid4()->toString())->withCompany($company)->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withCashflowCategory($current)->build();
        $rule = new CashTransactionAutoRule(Uuid::uuid4()->toString(), $company, 'Category', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $target);
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade->expects(self::never())->method('getActivePairs');
        $planner = new CashAutoRuleApplicationPlanner($facade);
        $match = (new CashAutoRuleMatcher())->match($transaction, [$rule]);
        $provenance = new CashTransactionAutoRuleProvenance(['cashflowCategory' => true]);

        self::assertFalse($planner->create($match, $transaction, provenance: $provenance)->hasChanges());
        self::assertFalse($planner->create($match, $transaction, mode: CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED)->hasChanges());
        $replace = $planner->create($match, $transaction, mode: CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED, provenance: $provenance);
        self::assertSame(['cashflowCategory' => ['before' => $current->getId(), 'after' => $target->getId()]], $replace->changes);
        self::assertSame($rule, $replace->rulesByField['cashflowCategory']);
        self::assertSame($current, $transaction->getCashflowCategory());
    }
}
