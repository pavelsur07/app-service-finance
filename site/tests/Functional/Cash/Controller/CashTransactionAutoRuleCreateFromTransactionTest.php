<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Enum\Transaction\CashDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

/**
 * «Создать правило из операции»: форма нового автоправила открывается заполненной
 * из транзакции разбора.
 */
final class CashTransactionAutoRuleCreateFromTransactionTest extends WebTestCaseBase
{
    public function testNewFormIsPrefilledFromTransaction(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId('44444444-4444-4444-4444-444444444401')
            ->withCompany($company)
            ->withName('Аренда')
            ->build();
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId('55555555-5555-5555-5555-555555555501')
            ->withCompany($company)
            ->withName('ООО Ромашка')
            ->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Расчётный счёт')
            ->build();

        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->withDirection(CashDirection::OUTFLOW)
            ->withCashflowCategory($category)
            ->build();
        $transaction->setCounterparty($counterparty);
        $transaction->setDescription('Оплата по счету №1423 от 12.03.2026 за аренду');

        foreach ([$user, $company, $category, $counterparty, $account, $transaction] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request(
            'GET',
            sprintf('/cash-transaction-auto-rules/new?fromTransaction=%s', $transaction->getId()),
        );

        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Сохранить')->form();
        self::assertSame('ООО Ромашка → Аренда', $form['cash_transaction_auto_rule[name]']->getValue());
        self::assertSame('OUTFLOW', $form['cash_transaction_auto_rule[operationType]']->getValue());
        self::assertSame($category->getId(), $form['cash_transaction_auto_rule[cashflowCategory]']->getValue());

        // Условие-скелет: контрагент, а не вся строка назначения платежа.
        self::assertSame('COUNTERPARTY', $form['cash_transaction_auto_rule[conditions][0][field]']->getValue());
        self::assertSame(
            $counterparty->getId(),
            $form['cash_transaction_auto_rule[conditions][0][counterparty]']->getValue(),
        );

        // Назначение показано целиком: из него пользователь выбирает устойчивый фрагмент.
        self::assertStringContainsString(
            'Оплата по счету №1423 от 12.03.2026 за аренду',
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * Повторный рендер после невалидного POST: карточка источника нужна именно здесь,
     * а условие не должно продублироваться предзаполнением поверх отправленного.
     */
    public function testSourceCardSurvivesInvalidSubmit(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId('44444444-4444-4444-4444-444444444402')
            ->withCompany($company)
            ->withName('Аренда')
            ->build();
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId('55555555-5555-5555-5555-555555555502')
            ->withCompany($company)
            ->withName('ООО Ромашка')
            ->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();

        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->withCashflowCategory($category)
            ->build();
        $transaction->setCounterparty($counterparty);
        $transaction->setDescription('Оплата по счету №1423 от 12.03.2026 за аренду');

        foreach ([$user, $company, $category, $counterparty, $account, $transaction] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request(
            'GET',
            sprintf('/cash-transaction-auto-rules/new?fromTransaction=%s', $transaction->getId()),
        );

        // Условие «контрагент» без контрагента не проходит валидацию.
        $crawler = $client->submit($crawler->selectButton('Сохранить')->form(), [
            'cash_transaction_auto_rule[conditions][0][counterparty]' => '',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Выберите контрагента.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString(
            'Оплата по счету №1423 от 12.03.2026 за аренду',
            (string) $client->getResponse()->getContent(),
        );
        self::assertCount(1, $crawler->filter('[data-auto-rule-condition].condition-row'));
    }

    public function testTransactionOfAnotherCompanyIsNotFound(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $otherAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($otherCompany)
            ->build();
        $otherTransaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($otherCompany)
            ->withMoneyAccount($otherAccount)
            ->build();

        foreach ([$user, $company, $otherCompany, $otherAccount, $otherTransaction] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request(
            'GET',
            sprintf('/cash-transaction-auto-rules/new?fromTransaction=%s', $otherTransaction->getId()),
        );

        self::assertResponseStatusCodeSame(404);
    }
}
