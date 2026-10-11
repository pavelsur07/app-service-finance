<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Finance\Entity\Document;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

/**
 * Документ ОПиУ копирует сумму операции без пересчёта, поэтому колонка суммы
 * на странице правки валютной операции не может быть подписана рублём.
 */
final class CashTransactionEditCurrencyTest extends WebTestCaseBase
{
    public function testPnlDocumentsOfForeignCurrencyTransactionAreNotLabelledInRubles(): void
    {
        $crawler = $this->openEditPageFor('USD', 7631);

        self::assertSame('Сумма', trim($crawler->filter('table thead th.text-end')->first()->text()));
        self::assertStringContainsString('$', $crawler->filter('table tbody td.text-end')->first()->text());
    }

    public function testPnlDocumentsOfRubleTransactionKeepTheRubleInTheHeader(): void
    {
        $crawler = $this->openEditPageFor('RUB', 7632);

        self::assertSame('Сумма, ₽', trim($crawler->filter('table thead th.text-end')->first()->text()));
        self::assertCount(0, $crawler->filter('table tbody td.text-end .vf-amount__currency'));
    }

    private function openEditPageFor(string $currency, int $index): \Symfony\Component\DomCrawler\Crawler
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->forCompany($company)
            ->withCurrency($currency)
            ->build();
        $tx = new CashTransaction(
            sprintf('66666666-6666-6666-8666-%012d', $index),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '50.00',
            $currency,
            new \DateTimeImmutable('2026-07-18'),
        );
        $document = new Document(sprintf('77777777-7777-7777-8777-%012d', $index), $company);
        $document->setCashTransaction($tx)->setNumber('ОПиУ-1');

        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->persist($account);
        $this->em()->persist($tx);
        $this->em()->persist($document);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/finance/cash-transactions/'.$tx->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table tbody tr'));

        return $crawler;
    }
}
