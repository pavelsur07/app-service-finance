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
 * Карточка операции сравнивала enum направления со строкой и показывала любую
 * операцию как «Отток». Приток и отток должны различаться и по бейджу, и по знаку.
 */
final class CashTransactionShowDirectionTest extends WebTestCaseBase
{
    public function testInflowIsShownAsInflowWithPlusSign(): void
    {
        $card = $this->openShowPageFor(CashDirection::INFLOW, 7651);

        self::assertSame('Приток', trim($card->filter('.badge')->first()->text()));
        self::assertStringStartsWith('+1', trim($card->filter('.vf-amount')->first()->text()));
    }

    public function testOutflowIsShownAsOutflowWithMinusSign(): void
    {
        $card = $this->openShowPageFor(CashDirection::OUTFLOW, 7652);

        self::assertSame('Отток', trim($card->filter('.badge')->first()->text()));
        self::assertStringStartsWith("\u{2212}1", trim($card->filter('.vf-amount')->first()->text()));
    }

    private function openShowPageFor(CashDirection $direction, int $index): Crawler
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->forCompany($company)
            ->build();
        $tx = new CashTransaction(
            sprintf('66666666-6666-6666-8666-%012d', $index),
            $company,
            $account,
            $direction,
            '1500.00',
            'RUB',
            new \DateTimeImmutable('2026-07-18'),
        );
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->persist($account);
        $this->em()->persist($tx);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/finance/cash-transactions/'.$tx->getId());
        self::assertResponseIsSuccessful();

        return $crawler->filter('.card-body dl')->first();
    }
}
