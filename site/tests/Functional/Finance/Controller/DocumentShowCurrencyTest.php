<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Документ ОПиУ из операции ДДС копирует её сумму без пересчёта: у валютной операции
 * сумма документа не может быть подписана рублём.
 */
final class DocumentShowCurrencyTest extends WebTestCaseBase
{
    public function testDocumentOfForeignCurrencyTransactionIsNotLabelledInRubles(): void
    {
        $crawler = $this->openDocumentFor('USD', 7661);

        self::assertSame('Сумма', trim($crawler->filter('table thead th.text-end')->first()->text()));
        self::assertSame('$', $crawler->filter('table tbody .vf-amount__currency')->first()->text());
    }

    public function testDocumentOfRubleTransactionKeepsTheRubleInTheHeader(): void
    {
        $crawler = $this->openDocumentFor('RUB', 7662);

        self::assertSame('Сумма, ₽', trim($crawler->filter('table thead th.text-end')->first()->text()));
        self::assertCount(0, $crawler->filter('table tbody .vf-amount__currency'));
    }

    private function openDocumentFor(string $currency, int $index): Crawler
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
        $operation = new DocumentOperation(sprintf('88888888-8888-4888-8888-%012d', $index));
        $operation->setAmount('50.00');
        $document->addOperation($operation);

        foreach ([$user, $company, $account, $tx, $document, $operation] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/documents/'.$document->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
