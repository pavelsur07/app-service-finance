<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\FiatCurrency;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class BalanceDynamicsControllerTest extends WebTestCaseBase
{
    public function testReturnsTypedSeriesForActiveCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withEmail('balance-dynamics-api@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $company->setMinimumBalance(Money::fromString('30.00', 'RUB'));
        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'API account',
            'RUB',
        );
        $account
            ->setOpeningBalance('25.00')
            ->setOpeningBalanceDate(new \DateTimeImmutable('2020-01-01'));

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
        $client->request('GET', '/api/finance/dashboard/balance-dynamics');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $today = new \DateTimeImmutable('today');

        self::assertSame([
            'days' => 30,
            'from' => $today->modify('-29 days')->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
        ], $payload['period']);
        self::assertSame(FiatCurrency::RUB->value, $payload['currency']);
        self::assertSame(['amount' => '30.00', 'currency' => 'RUB'], $payload['minimum_balance']);
        self::assertCount(30, $payload['points']);
        self::assertSame('25.00', $payload['points'][0]['balance']);
        self::assertTrue($payload['points'][0]['below_minimum']);
        self::assertSame([
            'operating' => '0.00',
            'financing' => '0.00',
            'investing' => '0.00',
        ], $payload['points'][0]['flows']);

        $client->request('GET', '/api/finance/dashboard/balance-dynamics?period=90');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(90, $payload['period']['days']);
        self::assertCount(90, $payload['points']);
    }

    public function testRejectsInvalidPeriodAndCurrencyWithStableErrorShape(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withEmail('balance-dynamics-errors@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());

        foreach (['period=14', 'period[]=30', 'currency=BTC'] as $query) {
            $client->request('GET', '/api/finance/dashboard/balance-dynamics?'.$query);

            self::assertResponseStatusCodeSame(422);
            $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame('validation_error', $payload['error']['code']);
            self::assertIsString($payload['error']['message']);
        }
    }
}
