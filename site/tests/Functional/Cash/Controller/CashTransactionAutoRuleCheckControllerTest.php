<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Shared\Entity\AuditLog;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class CashTransactionAutoRuleCheckControllerTest extends WebTestCaseBase
{
    public function testPreviewIsCompanyScopedExactAndReadOnly(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId('33333333-3333-3333-3333-333333333301')
            ->forCompany($company)
            ->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId('44444444-4444-4444-4444-444444444401')
            ->withCompany($company)
            ->withName('Аренда')
            ->build();
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Правило аренды',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        );
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'аренд',
        ));
        $today = new \DateTimeImmutable('today');
        $matchingTransaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->build();
        $matchingTransaction->setOccurredAt($today)->setDescription('Оплата аренды');
        $matchingTransactions = [$matchingTransaction];
        for ($index = 2; $index <= 11; ++$index) {
            $transaction = CashTransactionBuilder::aCashTransaction()
                ->forCompany($company)
                ->withMoneyAccount($account)
                ->build();
            $transaction->setOccurredAt($today)->setDescription(sprintf('Оплата аренды %d', $index));
            $matchingTransactions[] = $transaction;
        }
        $otherTransaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->build();
        $otherTransaction->setOccurredAt($today)->setDescription('Комиссия банка');

        $otherUser = UserBuilder::aUser()->withIndex(2)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherUser)->build();
        $otherAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId('33333333-3333-3333-3333-333333333302')
            ->forCompany($otherCompany)
            ->build();
        $otherCompanyTransaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($otherCompany)
            ->withMoneyAccount($otherAccount)
            ->build();
        $otherCompanyTransaction->setOccurredAt($today)->setDescription('Оплата аренды другой компании');

        foreach ([
            $user,
            $company,
            $account,
            $category,
            $rule,
            ...$matchingTransactions,
            $otherTransaction,
            $otherUser,
            $otherCompany,
            $otherAccount,
            $otherCompanyTransaction,
        ] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $auditCountBefore = $this->em()->getRepository(AuditLog::class)->count([]);

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('GET', sprintf(
            '/cash-transaction-auto-rules/%s/check?dateFrom=%s&dateTo=%s&limit=10',
            $rule->getId(),
            $today->format('Y-m-d'),
            $today->format('Y-m-d'),
        ));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.auto-rule-preview-summary[data-scanned="12"][data-matched="11"][data-would-change="11"][data-conflicts="0"]');
        self::assertSelectorCount(10, '.auto-rule-preview-plan');
        self::assertSelectorTextContains('.auto-rule-preview-plan', 'Статья: Не задано → Аренда');
        self::assertSelectorTextNotContains('body', 'Оплата аренды другой компании');
        self::assertSame($auditCountBefore, $this->em()->getRepository(AuditLog::class)->count([]));

        $client->request('GET', sprintf(
            '/cash-transaction-auto-rules/%s/check?dateFrom=invalid&dateTo=%s&limit=10',
            $rule->getId(),
            $today->format('Y-m-d'),
        ));

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('.alert.alert-danger');
        self::assertSame($auditCountBefore, $this->em()->getRepository(AuditLog::class)->count([]));

        $this->em()->clear();
        $reloadedTransaction = $this->em()->find(CashTransaction::class, $matchingTransaction->getId());
        self::assertNull($reloadedTransaction?->getCashflowCategory());
    }

    public function testPreviewShowsAtomicProjectAndResponsibilityCenterPlanWithoutMutation(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withCompany($company)
            ->withName('Продажи')
            ->build();
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажа компьютеров');
        $center = new FinancialResponsibilityCenter(
            (string) $company->getId(),
            'CFO_KRASNODAR',
            'Краснодар',
        );
        $pair = new FinancialResponsibilityCenterProject((string) $company->getId(), $project, $center);
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Продажи Краснодар',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        );
        $rule->setProjectDirection($project)->setResponsibilityCenterId($center->getId());
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'компьютер',
        ));
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->withCashflowCategory($category)
            ->build();
        $transaction
            ->setOccurredAt(new \DateTimeImmutable('today'))
            ->setDescription('Продажа компьютера');

        foreach ([$user, $company, $account, $category, $project, $center, $pair, $rule, $transaction] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('GET', sprintf(
            '/cash-transaction-auto-rules/%s/check?dateFrom=%s&dateTo=%s&limit=10',
            $rule->getId(),
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auto-rule-preview-plan', 'Проект: Не задано → Продажа компьютеров');
        self::assertSelectorTextContains('.auto-rule-preview-plan', 'ЦФО: Не задано → Краснодар');
        self::assertSelectorTextContains('body', 'По итоговому ЦФО');
        self::assertSelectorTextNotContains('body', 'PAIR_');

        $this->em()->clear();
        $reloaded = $this->em()->find(CashTransaction::class, $transaction->getId());
        self::assertInstanceOf(CashTransaction::class, $reloaded);
        self::assertNull($reloaded->getProjectDirection());
        self::assertNull($reloaded->getResponsibilityCenterId());

        $client->request('GET', sprintf('/cash-transaction-auto-rules/match/%s', $transaction->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Проект: Не задано → Продажа компьютеров');
        self::assertSelectorTextContains('body', 'ЦФО: Не задано → Краснодар');
    }
}
