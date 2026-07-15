<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class CashTransactionAutoRuleEditControllerTest extends WebTestCaseBase
{
    public function testRevisionChangesOnlyWhenRuleDefinitionChanges(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
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

        foreach ([$user, $company, $category, $rule] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $editUrl = '/cash-transaction-auto-rules/'.$rule->getId().'/edit';

        $crawler = $client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Сохранить')->form());
        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        self::assertSame(1, $this->reloadRule($rule)->getRevision());

        $crawler = $client->request('GET', $editUrl);
        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction_auto_rule[conditions][0][value]'] = 'updated invoice';
        $client->submit($form);
        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        self::assertSame(2, $this->reloadRule($rule)->getRevision());
    }

    private function reloadRule(CashTransactionAutoRule $rule): CashTransactionAutoRule
    {
        $this->em()->clear();

        return $this->em()->find(CashTransactionAutoRule::class, $rule->getId());
    }
}
