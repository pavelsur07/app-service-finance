<?php

declare(strict_types=1);

namespace App\Tests\Functional\BankSync;

use App\BankSync\Entity\BankAccount;
use App\BankSync\Entity\BankConnection;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Tests\Builders\BankSync\BankAccountBuilder;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class BankAccountsPageTest extends WebTestCaseBase
{
    private const NUMBER = '40702810100000000001';

    private KernelBrowser $client;
    private Company $company;
    private BankConnection $bankConnection;
    private BankAccount $bankAccount;
    private MoneyAccount $matching;
    private MoneyAccount $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('bank-accounts-owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $this->company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($this->company);

        $this->bankConnection = $this->persistConnection((string) $this->company->getId());
        $this->bankAccount = BankAccountBuilder::anAccount()
            ->forConnection((string) $this->company->getId(), $this->bankConnection->getId())
            ->withAccountNumber(self::NUMBER)
            ->build();
        $this->em()->persist($this->bankAccount);
        // Подсказка — по цифрам номера, даже если в Cash он записан с пробелами.
        $this->matching = $this->persistMoneyAccount($this->company, 'Альфа расчётный', 'RUB', '4070 2810 1000 0000 0001');
        $this->other = $this->persistMoneyAccount($this->company, 'Касса', 'RUB');
        $this->persistMoneyAccount($this->company, 'Валютный', 'USD');
        $this->em()->flush();
        $this->em()->clear();

        $this->loginAs($owner);
    }

    public function testPageSuggestsAccountWithSameNumberAndLinksIt(): void
    {
        $crawler = $this->client->request('GET', '/bank-connections/');
        self::assertResponseIsSuccessful();
        $link = $crawler->filter(sprintf('a[href="/bank-connections/%s/accounts"]', $this->bankConnection->getId()));
        self::assertCount(1, $link);
        self::assertStringContainsString('1', $link->filter('.badge')->text(), 'непривязанный счёт посчитан');

        $crawler = $this->client->request('GET', $this->pageUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', self::NUMBER);
        self::assertSelectorTextContains('table', 'не привязан');
        $options = $crawler->filter($this->linkSelect().' option')->each(static fn ($option): string => $option->text());
        self::assertContains('Альфа расчётный', $options);
        self::assertContains('Касса', $options);
        self::assertNotContains('Валютный', $options, 'другая валюта не предлагается');
        self::assertSame($this->id($this->matching), $crawler->filter($this->linkSelect().' option[selected]')->attr('value'));

        $this->client->submit($crawler->selectButton('Привязать')->form());

        self::assertResponseRedirects($this->pageUrl());
        self::assertSame($this->id($this->matching), $this->linkedMoneyAccountId());
        self::assertSame('alfa', $this->bankApiSource($this->matching));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'Счёт банка привязан');
        self::assertSelectorTextContains('table', 'загружается');
        self::assertSelectorTextContains('table', 'Альфа расчётный');

        $this->client->submit($crawler->selectButton('Отвязать')->form());

        self::assertResponseRedirects($this->pageUrl());
        self::assertNull($this->linkedMoneyAccountId());
        self::assertNull($this->bankApiSource($this->matching));
    }

    public function testMoneyAccountLinkedElsewhereIsNotOffered(): void
    {
        $secondNumber = '40702810100000000002';
        $this->em()->persist(BankAccountBuilder::anAccount()
            ->withIndex(2)
            ->forConnection((string) $this->company->getId(), $this->bankConnection->getId())
            ->withAccountNumber($secondNumber)
            ->linkedTo($this->id($this->other))
            ->build());
        $this->em()->flush();

        $crawler = $this->client->request('GET', $this->pageUrl());

        $options = $crawler->filter($this->linkSelect().' option')
            ->each(static fn ($option): string => $option->text());
        self::assertNotContains('Касса', $options, 'денежный счёт уже получает выписку из другого счёта банка');
        self::assertContains('Альфа расчётный', $options);
    }

    public function testChoiceOutsideOfferedListIsRejected(): void
    {
        $crawler = $this->client->request('GET', $this->pageUrl());
        $form = $crawler->selectButton('Привязать')->form();
        $field = sprintf('link_%s', str_replace('-', '', $this->bankAccount->getId()));
        $values = $form->getPhpValues();
        $values[$field]['moneyAccountId'] = Uuid::uuid4()->toString();

        $this->client->request('POST', (string) $form->getUri(), $values);

        self::assertResponseRedirects($this->pageUrl());
        self::assertNull($this->linkedMoneyAccountId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'Выберите денежный счёт');
    }

    public function testLinkWithoutCsrfIsRejected(): void
    {
        $field = sprintf('link_%s', str_replace('-', '', $this->bankAccount->getId()));
        $this->client->request('POST', sprintf('%s/%s/link', $this->pageUrl(), $this->bankAccount->getId()), [
            $field => ['moneyAccountId' => $this->id($this->matching), '_token' => 'forged'],
        ]);

        self::assertResponseRedirects($this->pageUrl());
        self::assertNull($this->linkedMoneyAccountId());

        $this->linkDirectly();
        $this->client->request('POST', sprintf('%s/%s/unlink', $this->pageUrl(), $this->bankAccount->getId()), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($this->id($this->matching), $this->linkedMoneyAccountId());
    }

    public function testForeignConnectionAndAccountAre404(): void
    {
        $otherOwner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-accounts-other@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($otherOwner)->build();
        $this->em()->persist($otherOwner);
        $this->em()->persist($otherCompany);
        $foreignConnection = $this->persistConnection((string) $otherCompany->getId());
        $foreignAccount = BankAccountBuilder::anAccount()
            ->withIndex(3)
            ->forConnection((string) $otherCompany->getId(), $foreignConnection->getId())
            ->build();
        $this->em()->persist($foreignAccount);
        $foreignMoneyAccount = $this->persistMoneyAccount($otherCompany, 'Чужой счёт', 'RUB');
        $this->em()->flush();

        $this->client->request('GET', sprintf('/bank-connections/%s/accounts', $foreignConnection->getId()));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', sprintf('/bank-connections/%s/accounts/%s/unlink', $foreignConnection->getId(), $foreignAccount->getId()), ['_token' => 'any']);
        self::assertResponseStatusCodeSame(404);

        // Свой URL подключения, но счёт банка чужой компании.
        $this->client->request('POST', sprintf('%s/%s/link', $this->pageUrl(), $foreignAccount->getId()), []);
        self::assertResponseStatusCodeSame(404);

        // Чужой денежный счёт в форме своего счёта банка — не в списке вариантов.
        $crawler = $this->client->request('GET', $this->pageUrl());
        $form = $crawler->selectButton('Привязать')->form();
        $field = sprintf('link_%s', str_replace('-', '', $this->bankAccount->getId()));
        $values = $form->getPhpValues();
        $values[$field]['moneyAccountId'] = $this->id($foreignMoneyAccount);
        $this->client->request('POST', (string) $form->getUri(), $values);
        self::assertNull($this->linkedMoneyAccountId());
        self::assertNull($this->bankApiSource($foreignMoneyAccount));
    }

    public function testAccountOfAnotherConnectionOfSameCompanyIs404(): void
    {
        $secondConnection = $this->persistConnection((string) $this->company->getId());
        $this->em()->flush();

        $this->client->request('POST', sprintf('/bank-connections/%s/accounts/%s/unlink', $secondConnection->getId(), $this->bankAccount->getId()), ['_token' => 'any']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testReadOnlyMemberSeesPageButCannotLink(): void
    {
        $readOnlyRole = new CompanyRole(Uuid::uuid4()->toString(), 'Только чтение финансов', [Module::FINANCE->value => AccessLevel::READ->value]);
        $memberUser = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('bank-accounts-reader@example.test')
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->withAccessRole($readOnlyRole)
            ->build();
        foreach ([$readOnlyRole, $memberUser, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->loginAs($memberUser);

        $this->client->request('GET', $this->pageUrl());
        self::assertResponseIsSuccessful();

        $field = sprintf('link_%s', str_replace('-', '', $this->bankAccount->getId()));
        $this->client->request('POST', sprintf('%s/%s/link', $this->pageUrl(), $this->bankAccount->getId()), [
            $field => ['moneyAccountId' => $this->id($this->matching)],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->linkedMoneyAccountId());
    }

    private function linkDirectly(): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE bank_sync_account SET money_account_id = :moneyAccountId, linked_at = now() WHERE id = :id',
            ['moneyAccountId' => $this->id($this->matching), 'id' => $this->bankAccount->getId()],
        );
    }

    private function persistConnection(string $companyId): BankConnection
    {
        $connection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($companyId)
            ->build();
        $this->em()->persist($connection);

        return $connection;
    }

    private function persistMoneyAccount(Company $company, string $name, string $currency, ?string $number = null): MoneyAccount
    {
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName($name)
            ->withCurrency($currency)
            ->build();
        $account->setAccountNumber($number);
        $this->em()->persist($account);

        return $account;
    }

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
    }

    private function linkSelect(): string
    {
        return sprintf('form[action="%s/%s/link"] select', $this->pageUrl(), $this->bankAccount->getId());
    }

    private function pageUrl(): string
    {
        return sprintf('/bank-connections/%s/accounts', $this->bankConnection->getId());
    }

    private function id(MoneyAccount $account): string
    {
        return (string) $account->getId();
    }

    private function linkedMoneyAccountId(): ?string
    {
        $value = $this->em()->getConnection()->fetchOne('SELECT money_account_id FROM bank_sync_account WHERE id = :id', ['id' => $this->bankAccount->getId()]);

        return false === $value ? null : $value;
    }

    private function bankApiSource(MoneyAccount $account): ?string
    {
        $value = $this->em()->getConnection()->fetchOne('SELECT bank_api_source FROM money_account WHERE id = :id', ['id' => $this->id($account)]);

        return false === $value ? null : $value;
    }
}
