<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service\AutoRule;

use App\Cash\Application\Service\AutoRule\CashAutoRuleApplicationPlanner;
use App\Cash\Application\Service\AutoRule\CashAutoRuleMatcher;
use App\Cash\Application\Service\AutoRule\CashAutoRulePreviewBuilder;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashAutoRulePreviewBuilderTest extends TestCase
{
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
