<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Форма операции без суммы проходила валидацию (Assert\Positive пропускает null),
 * и сервис падал на неинициализированном CashTransactionDTO::$amount → 500.
 * Пустая сумма должна возвращать форму с ошибкой поля, а не 500 и не сырой JSON.
 */
final class CashTransactionEmptyAmountTest extends WebTestCaseBase
{
    public function testNewTransactionWithoutAmountShowsFieldErrorInsteadOf500(): void
    {
        $client = static::createClient();
        [$user, $company, $accountId] = $this->seed(7671);
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/finance/cash-transactions/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction[occurredAt]'] = '2026-07-18';
        $accountField = $form['cash_transaction[moneyAccount]'];
        self::assertInstanceOf(ChoiceFormField::class, $accountField);
        $accountField->select($accountId);
        $form['cash_transaction[amount]'] = '';
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form[name="cash_transaction"]');
        // Ошибка — именно у поля суммы: блок .invalid-feedback сразу после input суммы.
        self::assertStringContainsString('Укажите сумму', $client->getCrawler()->filter('input[name="cash_transaction[amount]"] + .invalid-feedback')->text());
        self::assertCount(0, $this->em()->getRepository(CashTransaction::class)->findBy(['company' => $company]));
    }

    public function testClearingAmountOnEditShowsFieldErrorAndKeepsTheTransaction(): void
    {
        $client = static::createClient();
        [$user, $company, $accountId] = $this->seed(7672);
        $account = $this->em()->getRepository(\App\Cash\Entity\Accounts\MoneyAccount::class)->find($accountId);
        self::assertNotNull($account);
        $tx = new CashTransaction(
            '66666666-6666-6666-8666-000000007672',
            $company,
            $account,
            CashDirection::OUTFLOW,
            '1000.00',
            'RUB',
            new \DateTimeImmutable('2026-07-18'),
        );
        $this->em()->persist($tx);
        $this->em()->flush();
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/finance/cash-transactions/'.$tx->getId().'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction[amount]'] = '';
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        // Ошибка — именно у поля суммы: блок .invalid-feedback сразу после input суммы.
        self::assertStringContainsString('Укажите сумму', $client->getCrawler()->filter('input[name="cash_transaction[amount]"] + .invalid-feedback')->text());
        $this->em()->clear();
        $saved = $this->em()->find(CashTransaction::class, $tx->getId());
        self::assertInstanceOf(CashTransaction::class, $saved);
        self::assertSame('1000.00', $saved->getAmount());
    }

    /**
     * @return array{0: User, 1: Company, 2: string}
     */
    private function seed(int $index): array
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $accountId = sprintf('33333333-3333-3333-3333-%012d', $index);
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId($accountId)
            ->forCompany($company)
            ->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->persist($account);
        $this->em()->flush();

        return [$user, $company, $accountId];
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
