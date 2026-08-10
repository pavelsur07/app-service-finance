<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class CashTransactionCurrencyFilterTest extends WebTestCaseBase
{
    public function testIndexFiltersByCurrencyWithoutBreakingTenantScopeOrPagination(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('cash-currency-list@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);

        $rubAccount = $this->account($company, 'RUB account', 'RUB');
        $usdAccount = $this->account($company, 'USD account', 'USD');
        $em->persist($rubAccount);
        $em->persist($usdAccount);

        $this->transaction($em, $company, $rubAccount, 'RUB operation', new \DateTimeImmutable('2026-02-01'));
        for ($day = 1; $day <= 21; ++$day) {
            $this->transaction(
                $em,
                $company,
                $usdAccount,
                sprintf('USD operation %d', $day),
                new \DateTimeImmutable(sprintf('2026-01-%02d', $day)),
            );
        }

        $otherOwner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('cash-currency-list-other@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($otherOwner)
            ->build();
        $otherAccount = $this->account($otherCompany, 'Foreign USD account', 'USD');
        $em->persist($otherOwner);
        $em->persist($otherCompany);
        $em->persist($otherAccount);
        $this->transaction($em, $otherCompany, $otherAccount, 'Foreign USD operation', new \DateTimeImmutable('2026-01-01'));
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $firstPage = $client->request('GET', '/finance/cash-transactions/?currency=USD');
        self::assertResponseIsSuccessful();
        self::assertCount(20, $firstPage->filter('.js-cash-transaction-select'));
        self::assertSame(1, $firstPage->filter('#filter-currency option[value="USD"][selected]')->count());
        self::assertStringContainsString('currency=USD', (string) $firstPage->filter('.pagination a')->last()->attr('href'));

        $secondPage = $client->request('GET', '/finance/cash-transactions/?currency=USD&page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $secondPage->filter('.js-cash-transaction-select'));
        $tableText = $secondPage->filter('table tbody')->text();
        self::assertStringContainsString('USD operation', $tableText);
        self::assertStringNotContainsString('RUB operation', $tableText);
        self::assertStringNotContainsString('Foreign USD operation', $tableText);

        $unfiltered = $client->request('GET', '/finance/cash-transactions/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('RUB operation', $unfiltered->filter('table tbody')->text());
    }

    public function testIndexRejectsUnsupportedCurrency(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('cash-currency-invalid@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('GET', '/finance/cash-transactions/?currency=BTC');

        self::assertResponseRedirects('/finance/cash-transactions/');
    }

    private function account(Company $company, string $name, string $currency): MoneyAccount
    {
        return MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName($name)
            ->withCurrency($currency)
            ->build();
    }

    private function transaction(
        EntityManagerInterface $entityManager,
        Company $company,
        MoneyAccount $account,
        string $description,
        \DateTimeImmutable $date,
    ): void {
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '1.00',
            $account->getCurrency(),
            $date,
        );
        $transaction->setDescription($description);
        $entityManager->persist($transaction);
    }
}
