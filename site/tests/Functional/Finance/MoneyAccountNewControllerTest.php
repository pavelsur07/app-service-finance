<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class MoneyAccountNewControllerTest extends WebTestCaseBase
{
    public function testNewAndEditFormsUseAccountDesign(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/accounts/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Новый счёт');
        self::assertSelectorTextContains('.card-title', 'Реквизиты счёта');
        self::assertSelectorTextContains('.money-account-info', 'остаток средств на счёте');
        self::assertCount(1, $crawler->filter('form#money-account-create-form.card'));
        self::assertCount(0, $crawler->filter('select[id$="_type"]'));
        self::assertCount(0, $crawler->filter('label[for$="_type"]'));
        self::assertCount(4, $crawler->filter('input[data-money-account-type] + .form-selectgroup-label'));
        self::assertSame(
            ['bank', 'cash', 'ewallet', 'crypto_wallet'],
            $crawler->filter('input[data-money-account-type]')->each(static fn ($node) => $node->attr('value')),
        );
        self::assertCount(1, $crawler->filter('input[data-money-account-type][value="bank"]:checked'));
        self::assertSame(
            ['RUB', 'USD', 'EUR', 'KZT'],
            $crawler->filter('select[id$="_currency"] option')->each(static fn ($node) => $node->attr('value')),
        );
        self::assertCount(0, $crawler->filter('select[id$="_currency"] option[value="USDT"]'));
        self::assertCount(1, $crawler->filter('#money-account-number-field'));
        self::assertCount(1, $crawler->filter('#money-account-default-badge.d-none'));

        foreach ([
            'minimumSafeBalance',
            'bankName',
            'iban',
            'bic',
            'corrAccount',
            'location',
            'responsiblePerson',
            'provider',
            'walletId',
        ] as $field) {
            self::assertCount(0, $crawler->filter(sprintf('[id$="_%s"]', $field)));
        }

        $form = $crawler->filter('#money-account-create-form')->form();
        $nameField = $crawler->filter('input[id$="_name"]')->attr('name');
        $typeField = $crawler->filter('input[data-money-account-type]')->first()->attr('name');
        $currencyField = $crawler->filter('select[id$="_currency"]')->attr('name');
        $accountNumberField = $crawler->filter('input[id$="_accountNumber"]')->attr('name');
        $openingBalanceField = $crawler->filter('input[id$="_openingBalance"]')->attr('name');
        $openingBalanceDateField = $crawler->filter('input[id$="_openingBalanceDate"]')->attr('name');

        $form[$nameField] = 'Validation error';
        $form[$typeField] = MoneyAccountType::CASH->value;
        $form[$currencyField] = 'EUR';
        $form[$openingBalanceDateField] = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $invalidCrawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $invalidForm = $invalidCrawler->filter('#money-account-create-form')->form();
        self::assertSame(MoneyAccountType::CASH->value, $invalidForm[$typeField]->getValue());
        self::assertSame('EUR', $invalidForm[$currencyField]->getValue());

        $crawler = $client->request('GET', '/accounts/new');
        $form = $crawler->filter('#money-account-create-form')->form();
        $form[$nameField] = 'Основной банк';
        $form[$typeField] = MoneyAccountType::BANK->value;
        $form[$currencyField] = 'KZT';
        $form[$accountNumberField] = '40702810400000012345';
        $form[$openingBalanceField] = '1250.50';
        $form[$openingBalanceDateField] = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $client->submit($form);

        self::assertResponseRedirects('/accounts/');

        $em->clear();
        $account = $this->em()->getRepository(MoneyAccount::class)->findOneBy(['name' => 'Основной банк']);

        self::assertInstanceOf(MoneyAccount::class, $account);
        self::assertSame(MoneyAccountType::BANK, $account->getType());
        self::assertSame('KZT', $account->getCurrency());
        self::assertSame('40702810400000012345', $account->getAccountNumber());
        self::assertSame(0, bccomp('1250.50', $account->getOpeningBalance(), 2));
        self::assertFalse($account->isDefault());

        $bankMeta = [
            'provider' => 'demo',
            'external_account_id' => 'external-1',
            'number' => '40817810000000000001',
            'auth' => ['token' => 'kept'],
        ];
        $account->setBankMeta($bankMeta);
        $this->em()->flush();

        $editCrawler = $client->request('GET', '/accounts/'.$account->getId().'/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Редактирование счёта');
        self::assertCount(1, $editCrawler->filter('form#money-account-create-form.card'));
        self::assertCount(0, $editCrawler->filter('select[id$="_type"]'));
        self::assertCount(4, $editCrawler->filter('input[data-money-account-type] + .form-selectgroup-label'));
        self::assertCount(1, $editCrawler->filter('input[data-money-account-type][value="bank"]:checked'));
        self::assertCount(1, $editCrawler->filter('select[id$="_currency"]'));
        self::assertCount(0, $editCrawler->filter('#tab-integration, [name="bank_provider"]'));
        self::assertCount(1, $editCrawler->filter('#money-account-create-form button[type="submit"]'));
        self::assertSelectorTextContains('#money-account-create-form button[type="submit"]', 'Сохранить');

        foreach ([
            'minimumSafeBalance',
            'bankName',
            'iban',
            'bic',
            'corrAccount',
            'location',
            'responsiblePerson',
            'provider',
            'walletId',
        ] as $field) {
            self::assertCount(0, $editCrawler->filter(sprintf('[id$="_%s"]', $field)));
        }

        $editForm = $editCrawler->filter('#money-account-create-form')->form();
        $editForm[$nameField] = 'Основной банк — обновлён';
        $client->submit($editForm);

        self::assertResponseRedirects('/accounts/');

        $this->em()->clear();
        $updatedAccount = $this->em()->getRepository(MoneyAccount::class)->find($account->getId());

        self::assertInstanceOf(MoneyAccount::class, $updatedAccount);
        self::assertSame('Основной банк — обновлён', $updatedAccount->getName());
        self::assertSame($bankMeta, $updatedAccount->getBankMeta());
    }
}
