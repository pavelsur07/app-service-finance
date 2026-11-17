<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CashTransactionAutoRuleEditControllerTest extends WebTestCaseBase
{
    public function testResponsibilityCenterTargetIsCompanyScopedAndPairValidated(): void
    {
        $client = static::createClient();

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

        $form = $crawler->selectButton('Проверить')->form();
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
        $form = $crawler->selectButton('Проверить')->form();
        $form['cash_transaction_auto_rule[projectDirection]']->select($project->getId());
        $form['cash_transaction_auto_rule[responsibilityCenterId]']->select($center->getId());
        $checked = $client->submit($form);
        self::assertResponseIsSuccessful();
        $client->submit($checked->selectButton('Подтвердить и включить')->form());
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

        // Повторное открытие: сохранённый проект предвыбран в пикере (ChoiceType по id, этап 7.5b).
        $crawler = $client->request('GET', $editUrl);
        self::assertSame(
            (string) $project->getId(),
            $crawler->filter('select[name="cash_transaction_auto_rule[projectDirection]"] option[selected]')->attr('value'),
        );

        $client->request('GET', '/cash-transaction-auto-rules/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Краснодар [CFO_SOUTH]');
    }

    public function testRevisionChangesOnlyWhenRuleDefinitionChanges(): void
    {
        $client = static::createClient();

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
        $checked = $client->submit($crawler->selectButton('Проверить')->form());
        self::assertResponseIsSuccessful();
        $client->submit($checked->selectButton('Подтвердить и включить')->form());
        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        self::assertSame(1, $this->reloadRule($rule)->getRevision());

        $crawler = $client->request('GET', $editUrl);
        $form = $crawler->selectButton('Проверить')->form();
        $form['cash_transaction_auto_rule[conditions][0][value]'] = 'updated invoice';
        $checked = $client->submit($form);
        self::assertResponseIsSuccessful();
        $client->submit($checked->selectButton('Подтвердить и включить')->form());
        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        $storedRule = $this->reloadRule($rule);
        self::assertSame(2, $storedRule->getRevision());
        $storedCondition = $storedRule->getConditions()->first();
        self::assertInstanceOf(CashTransactionAutoRuleCondition::class, $storedCondition);
        self::assertSame('updated invoice', $storedCondition->getValue());
    }

    public function testEditedDefinitionStaysLiveUntilConfirmationAndRejectsChangedInputOrRuleSet(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $rule = new CashTransactionAutoRule(Uuid::uuid7()->toString(), $company, 'Аренда', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $category);
        $rule->addCondition(new CashTransactionAutoRuleCondition(field: CashTransactionAutoRuleConditionField::DESCRIPTION, operator: CashTransactionAutoRuleConditionOperator::CONTAINS, value: 'аренда'));
        $colleague = UserBuilder::aUser()->withIndex(2)->withRoles(['ROLE_COMPANY_USER'])->build();
        $finance = new CompanyRole(Uuid::uuid7()->toString(), 'Финансист', ['finance' => 'write'], $company);
        $member = CompanyMemberBuilder::aMember()->withCompany($company)->withUser($colleague)->withAccessRole($finance)->build();
        $other = new CashTransactionAutoRule(Uuid::uuid7()->toString(), $company, 'Связь', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $category);
        $other->addCondition(new CashTransactionAutoRuleCondition(field: CashTransactionAutoRuleConditionField::DESCRIPTION, operator: CashTransactionAutoRuleConditionOperator::CONTAINS, value: 'интернет'));
        foreach ([$user, $company, $category, $colleague, $finance, $member, $rule, $other] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $url = '/cash-transaction-auto-rules/'.$rule->getId().'/edit';
        $crawler = $client->request('GET', $url);
        $checked = $client->submit($crawler->selectButton('Проверить')->form(), [
            'cash_transaction_auto_rule[conditions][0][value]' => 'ремонт',
        ]);
        self::assertResponseIsSuccessful();
        $saved = $this->reloadRule($rule);
        $savedCondition = $saved->getConditions()->first();
        self::assertInstanceOf(CashTransactionAutoRuleCondition::class, $savedCondition);
        self::assertSame('аренда', $savedCondition->getValue());
        $confirmation = $checked->selectButton('Подтвердить и включить')->form();
        $client->submit($confirmation, ['cash_transaction_auto_rule[priority]' => '999']);
        self::assertSelectorTextContains('body', 'Повторите проверку');
        self::assertSame(1, $this->reloadRule($rule)->getRevision());

        // Второй финансист подтверждает свою редакцию другого активного правила,
        // пока у первого на экране открыт результат проверки.
        $second = new KernelBrowser($client->getKernel());
        $second->loginUser($colleague);
        $this->setClientSessionValue($second, 'active_company_id', $company->getId());
        $otherForm = $second->request('GET', '/cash-transaction-auto-rules/'.$other->getId().'/edit');
        $otherPreview = $second->submit($otherForm->selectButton('Проверить')->form(), [
            'cash_transaction_auto_rule[priority]' => '999',
        ]);
        $second->submit($otherPreview->selectButton('Подтвердить и включить')->form());
        self::assertSame(302, $second->getResponse()->getStatusCode());
        self::assertSame(2, $this->reloadRule($other)->getRevision());
        $client->submit($checked->selectButton('Подтвердить и включить')->form());
        self::assertSelectorTextContains('body', 'Набор или версия правил изменились');
        $savedCondition = $this->reloadRule($rule)->getConditions()->first();
        self::assertInstanceOf(CashTransactionAutoRuleCondition::class, $savedCondition);
        self::assertSame('аренда', $savedCondition->getValue());
    }

    private function reloadRule(CashTransactionAutoRule $rule): CashTransactionAutoRule
    {
        $this->em()->clear();

        return $this->em()->find(CashTransactionAutoRule::class, $rule->getId());
    }
}
