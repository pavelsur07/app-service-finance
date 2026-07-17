<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class CashTransactionAutoRuleEditControllerTest extends WebTestCaseBase
{
    public function testResponsibilityCenterTargetIsCompanyScopedAndPairValidated(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажи');
        $otherProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Сервис');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_SOUTH', 'Краснодар');
        $foreignCenter = new FinancialResponsibilityCenter((string) $otherCompany->getId(), 'CFO_FOREIGN', 'Ростов');
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Rule',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
            createdByUserId: $user->getId(),
        );
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'invoice',
        ));

        foreach ([$user, $company, $otherCompany, $category, $project, $otherProject, $center, $foreignCenter, $rule] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new FinancialResponsibilityCenterProject(
            (string) $company->getId(),
            $project,
            $center,
        ));
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $editUrl = '/cash-transaction-auto-rules/'.$rule->getId().'/edit';

        $crawler = $client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        $centerOptionValues = $crawler
            ->filter('select[name="cash_transaction_auto_rule[responsibilityCenterId]"] option')
            ->each(static fn ($option): string => (string) $option->attr('value'));
        self::assertContains($center->getId(), $centerOptionValues);
        self::assertNotContains($foreignCenter->getId(), $centerOptionValues);

        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction_auto_rule[projectDirection]']->select($otherProject->getId());
        $form['cash_transaction_auto_rule[responsibilityCenterId]']->select($center->getId());
        $client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Выбранная пара проекта и ЦФО недоступна для автоправила.');
        $storedRule = $this->reloadRule($rule);
        self::assertNull($storedRule->getProjectDirection());
        self::assertNull($storedRule->getResponsibilityCenterId());
        self::assertSame(1, $storedRule->getRevision());

        $crawler = $client->request('GET', $editUrl);
        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction_auto_rule[projectDirection]']->select($project->getId());
        $form['cash_transaction_auto_rule[responsibilityCenterId]']->select($center->getId());
        $client->submit($form);
        self::assertResponseRedirects('/cash-transaction-auto-rules/');

        $storedRule = $this->reloadRule($rule);
        self::assertSame(
            [$project->getId(), $center->getId(), 2],
            [
                $storedRule->getProjectDirection()?->getId(),
                $storedRule->getResponsibilityCenterId(),
                $storedRule->getRevision(),
            ],
        );

        $client->request('GET', '/cash-transaction-auto-rules/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Краснодар [CFO_SOUTH]');
    }

    public function testRevisionChangesOnlyWhenRuleDefinitionChanges(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $ownAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();
        $foreignAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->withIndex(2)
            ->forCompany($otherCompany)
            ->build();
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Rule',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
            createdByUserId: $user->getId(),
        );
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'invoice',
        ));

        foreach ([$user, $company, $otherCompany, $category, $ownAccount, $foreignAccount, $rule] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $editUrl = '/cash-transaction-auto-rules/'.$rule->getId().'/edit';

        $crawler = $client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        $accountOptionValues = $crawler
            ->filter('select[name="cash_transaction_auto_rule[conditions][0][moneyAccount]"] option')
            ->each(static fn ($option): string => (string) $option->attr('value'));
        self::assertContains($ownAccount->getId(), $accountOptionValues);
        self::assertNotContains($foreignAccount->getId(), $accountOptionValues);
        $client->submit($crawler->selectButton('Сохранить')->form());
        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        self::assertSame(1, $this->reloadRule($rule)->getRevision());

        $crawler = $client->request('GET', $editUrl);
        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction_auto_rule[conditions][0][value]'] = 'updated invoice';
        $client->submit($form);
        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        $storedRule = $this->reloadRule($rule);
        self::assertSame(2, $storedRule->getRevision());
        $storedCondition = $storedRule->getConditions()->first();
        self::assertInstanceOf(CashTransactionAutoRuleCondition::class, $storedCondition);
        self::assertSame('updated invoice', $storedCondition->getValue());
    }

    private function reloadRule(CashTransactionAutoRule $rule): CashTransactionAutoRule
    {
        $this->em()->clear();

        return $this->em()->find(CashTransactionAutoRule::class, $rule->getId());
    }
}
