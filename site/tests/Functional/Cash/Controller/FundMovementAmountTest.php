<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class FundMovementAmountTest extends WebTestCaseBase
{
    #[DataProvider('amounts')]
    public function testSubmittedAmountIsStoredExactly(string $currency, string $input, ?string $expected): void
    {
        $_ENV['FEATURE_FUNDS_AND_WIDGET'] = $_SERVER['FEATURE_FUNDS_AND_WIDGET'] = '1';
        try {
            $client = static::createClient();
            $user = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7().'@example.test')->build();
            $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($user)->build();
            $fund = new MoneyFund(Uuid::uuid7()->toString(), $company, 'Reserve', $currency);
            foreach ([$user, $company, $fund] as $entity) {
                $this->em()->persist($entity);
            }
            $this->em()->flush();
            $client->loginUser($user);
            $this->setClientSessionValue($client, 'active_company_id', $company->getId());
            $crawler = $client->request('GET', '/finance/funds/'.$fund->getId().'/movement');
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form[name="money_fund_movement"]')->form();
            $form['money_fund_movement[amount]'] = $input;
            $client->submit($form);
            if (null === $expected) {
                self::assertResponseStatusCodeSame(422);
            } else {
                self::assertResponseRedirects('/finance/funds');
            }
            $stored = $this->em()->getConnection()->fetchFirstColumn('SELECT amount_minor FROM money_fund_movement WHERE fund_id = :fund', ['fund' => $fund->getId()]);
            self::assertSame(null === $expected ? [] : [$expected], array_map('strval', $stored));
        } finally {
            $_ENV['FEATURE_FUNDS_AND_WIDGET'] = $_SERVER['FEATURE_FUNDS_AND_WIDGET'] = '0';
        }
    }

    /** @return iterable<array{string, string, ?string}> */
    public static function amounts(): iterable
    {
        yield 'RUB half up' => ['RUB', '1,239', '124'];
        yield 'negative half up' => ['RUB', '-1.235', '-124'];
        yield 'JPY beyond float precision' => ['JPY', '9007199254740993', '9007199254740993'];
        yield 'three decimals' => ['BHD', '1.2345', '1235'];
        yield 'empty is zero' => ['RUB', '', '0'];
        yield 'narrow locale grouping' => ['RUB', "1\u{202F}234,56", '123456'];
        yield 'zero' => ['RUB', '0', '0'];
        yield 'invalid' => ['RUB', 'abc', null];
        yield 'overflow' => ['RUB', '9223372036854775808', null];
    }
}
