<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AssignGeneralCfoToCashAutoRulesCommandTest extends IntegrationTestCase
{
    public function testDryRunAndExecuteAssignOnlyEligibleRulesWithAuditMetadata(): void
    {
        $graph = $this->createCompanyGraph(7921, true);
        $actorUserId = $graph['actorUserId'];
        $eligible = $this->createRule($graph['company'], $graph['category'], $graph['generalProject'], 'Eligible');
        $customProjectRule = $this->createRule($graph['company'], $graph['category'], $graph['customProject'], 'Custom');
        $disabledRule = $this->createRule($graph['company'], $graph['category'], $graph['generalProject'], 'Disabled');
        $disabledRule->disable($actorUserId);
        $configuredRule = $this->createRule($graph['company'], $graph['category'], $graph['generalProject'], 'Configured');
        $configuredRule->setResponsibilityCenterId($graph['generalCenter']->getId());

        foreach ([$eligible, $customProjectRule, $disabledRule, $configuredRule] as $rule) {
            $this->em->persist($rule);
        }
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('dry-run', $tester->getDisplay());
        self::assertNull($this->reloadRule($eligible)->getResponsibilityCenterId());

        self::assertSame(Command::INVALID, $tester->execute(['--execute' => true]), $tester->getDisplay());
        self::assertNull($this->reloadRule($eligible)->getResponsibilityCenterId());
        self::assertSame(Command::INVALID, $tester->execute([
            '--execute' => true,
            '--actor-user-id' => '99999999-9999-9999-9999-999999999999',
            '--expected-count' => '1',
        ]), $tester->getDisplay());
        self::assertNull($this->reloadRule($eligible)->getResponsibilityCenterId());
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--execute' => true,
            '--actor-user-id' => $actorUserId,
            '--expected-count' => '1',
        ]), $tester->getDisplay());

        $storedEligible = $this->reloadRule($eligible);
        self::assertSame($graph['generalCenter']->getId(), $storedEligible->getResponsibilityCenterId());
        self::assertSame(2, $storedEligible->getRevision());
        self::assertSame($actorUserId, $storedEligible->getUpdatedByUserId());
        self::assertNull($this->reloadRule($customProjectRule)->getResponsibilityCenterId());
        self::assertNull($this->reloadRule($disabledRule)->getResponsibilityCenterId());
        self::assertSame($graph['generalCenter']->getId(), $this->reloadRule($configuredRule)->getResponsibilityCenterId());

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertMatchesRegularExpression('/candidates\s+0/', $tester->getDisplay());
    }

    public function testExecuteRejectsCandidateCountDriftBeforeMutation(): void
    {
        $graph = $this->createCompanyGraph(7924, true);
        $rule = $this->createRule($graph['company'], $graph['category'], $graph['generalProject'], 'Candidate');
        $this->em->persist($rule);
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute([
            '--execute' => true,
            '--actor-user-id' => $graph['actorUserId'],
            '--expected-count' => '2',
        ]), $tester->getDisplay());
        self::assertStringContainsString('Число кандидатов изменилось', $tester->getDisplay());
        self::assertNull($this->connection->fetchOne(
            'SELECT responsibility_center_id FROM cash_transaction_auto_rule WHERE id = :id',
            ['id' => $rule->getId()],
        ));
    }

    public function testExecuteIsAtomicWhenOneCompanyHasNoGeneralPair(): void
    {
        $validGraph = $this->createCompanyGraph(7922, true);
        $blockedGraph = $this->createCompanyGraph(7923, false);
        $validRule = $this->createRule($validGraph['company'], $validGraph['category'], $validGraph['generalProject'], 'Valid');
        $blockedRule = $this->createRule($blockedGraph['company'], $blockedGraph['category'], $blockedGraph['generalProject'], 'Blocked');
        $this->em->persist($validRule);
        $this->em->persist($blockedRule);
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute([
            '--execute' => true,
            '--actor-user-id' => $validGraph['actorUserId'],
            '--expected-count' => '2',
        ]), $tester->getDisplay());
        self::assertStringContainsString('Изменений нет', $tester->getDisplay());
        self::assertNull($this->reloadRule($validRule)->getResponsibilityCenterId());
        self::assertNull($this->reloadRule($blockedRule)->getResponsibilityCenterId());
    }

    /**
     * @return array{
     *     actorUserId: string,
     *     company: Company,
     *     category: CashflowCategory,
     *     generalProject: ProjectDirection,
     *     customProject: ProjectDirection,
     *     generalCenter: FinancialResponsibilityCenter
     * }
     */
    private function createCompanyGraph(int $index, bool $withGeneralPair): array
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withIndex($index)->withCompany($company)->build();
        $generalProject = new ProjectDirection(
            Uuid::uuid4()->toString(),
            $company,
            'Общие',
            ProjectDirection::CODE_GENERAL,
        );
        $customProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажи');
        $generalCenter = new FinancialResponsibilityCenter(
            $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );

        foreach ([$user, $company, $category, $generalProject, $customProject, $generalCenter] as $entity) {
            $this->em->persist($entity);
        }
        if ($withGeneralPair) {
            $this->em->persist(new FinancialResponsibilityCenterProject(
                $company->getId(),
                $generalProject,
                $generalCenter,
            ));
        }

        return [
            'actorUserId' => $user->getId(),
            'company' => $company,
            'category' => $category,
            'generalProject' => $generalProject,
            'customProject' => $customProject,
            'generalCenter' => $generalCenter,
        ];
    }

    private function createRule(
        Company $company,
        CashflowCategory $category,
        ProjectDirection $project,
        string $name,
    ): CashTransactionAutoRule {
        return (new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            $name,
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        ))->setProjectDirection($project);
    }

    private function reloadRule(CashTransactionAutoRule $rule): CashTransactionAutoRule
    {
        $id = $rule->getId();
        $this->em->clear();

        return $this->em->find(CashTransactionAutoRule::class, $id);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:cash-auto-rules:assign-general-cfo'));
    }
}
