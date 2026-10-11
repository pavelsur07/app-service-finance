<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * DS-Money: «₽» в заголовке колонки — только когда вся колонка в рублях. Суммы
 * операций не пересчитываются, поэтому при смешанных валютах валюта стоит в строке.
 */
final class CashTransactionListCurrencyTest extends WebTestCaseBase
{
    public function testRubleOnlyPageCarriesTheRubleInTheHeader(): void
    {
        $crawler = $this->openListWith(['RUB'], 7641);

        self::assertSame('Сумма, ₽', $this->amountHeader($crawler));
        self::assertCount(0, $crawler->filter('table tbody .vf-amount__currency'));
    }

    public function testMixedCurrencyPageShowsTheCurrencyInEveryRow(): void
    {
        $crawler = $this->openListWith(['RUB', 'USD'], 7642);

        self::assertSame('Сумма', $this->amountHeader($crawler));
        $symbols = $crawler->filter('table tbody .vf-amount__currency')->each(static fn (Crawler $node): string => $node->text());
        sort($symbols);
        self::assertSame(['$', '₽'], $symbols);
    }

    /**
     * @param list<string> $currencies
     */
    private function openListWith(array $currencies, int $index): Crawler
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);

        foreach ($currencies as $i => $currency) {
            $account = MoneyAccountBuilder::aMoneyAccount()
                ->withId(sprintf('33333333-3333-3333-3333-%08d%04d', $index, $i))
                ->forCompany($company)
                ->withName('Счёт '.$currency)
                ->withCurrency($currency)
                ->build();
            $this->em()->persist($account);
            $this->em()->persist(new CashTransaction(
                sprintf('66666666-6666-6666-8666-%08d%04d', $index, $i),
                $company,
                $account,
                CashDirection::INFLOW,
                '100.00',
                $currency,
                new \DateTimeImmutable('2026-07-18'),
            ));
        }
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/finance/cash-transactions/');
        self::assertResponseIsSuccessful();
        self::assertCount(\count($currencies), $crawler->filter('table tbody .vf-amount'));

        return $crawler;
    }

    private function amountHeader(Crawler $crawler): string
    {
        return trim($crawler->filter('table thead th.text-end')->first()->text());
    }
}
