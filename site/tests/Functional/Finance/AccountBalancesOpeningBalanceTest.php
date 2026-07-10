<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class AccountBalancesOpeningBalanceTest extends WebTestCaseBase
{
    public function testReportUsesOpeningBalanceBeforeSelectedDayTransactions(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(Uuid::uuid4().'@example.test')
            ->asCompanyOwner()
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('PAO SBERBANK')
            ->build();
        $account->setOpeningBalance('209594.87');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));

        $snapshot = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            new \DateTimeImmutable('2026-01-01'),
            '209594.87',
            '0.00',
            '990.00',
            '208604.87',
            'RUB',
        );

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->persist($snapshot);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', '/finance/reports/account-balances', ['date' => '2026-01-01']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', '209 594.87');
        self::assertSelectorTextNotContains('table', '208 604.87');

        $client->request('GET', '/finance/reports/account-balances', ['date' => '2026-01-02']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', '208 604.87');
    }
}
