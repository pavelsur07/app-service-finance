<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class HomeCashCurrencyTest extends WebTestCaseBase
{
    public function testHomeKpisAndReactSnapshotUseSelectedCashCurrency(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $user = UserBuilder::aUser()->withEmail('home-cash-currency@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $rubAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('RUB balance')
            ->withCurrency('RUB')
            ->build()
            ->setOpeningBalance('1000.00');
        $usdAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('USD balance')
            ->withCurrency('USD')
            ->build()
            ->setOpeningBalance('50.00');
        foreach ([$user, $company, $rubAccount, $usdAccount] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $categories = self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        foreach ([[$rubAccount, 'RUB', '100.00'], [$usdAccount, 'USD', '5.00']] as [$account, $currency, $amount]) {
            $transaction = new CashTransaction(
                Uuid::uuid4()->toString(),
                $company,
                $account,
                CashDirection::INFLOW,
                $amount,
                $currency,
                new \DateTimeImmutable('today'),
            );
            $transaction->setCashflowCategory($categories[CashflowCategory::CODE_OPERATING]);
            $transaction->replaceSplits([new CashTransactionSplit(
                $transaction,
                $categories[CashflowCategory::CODE_OPERATING],
                $amount,
                CashTransactionSplitSource::MANUAL,
            )]);
            $this->em()->persist($transaction);
        }
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/finance?currency=USD');

        self::assertResponseIsSuccessful();
        self::assertSame('USD', $crawler->filter('#react-dashboard-started')->attr('data-default-currency'));
        self::assertSame(
            ['50 USD', '5 USD', '0 USD', '5 USD'],
            $crawler->filter('.row-deck .h1')->each(static fn ($node): string => trim($node->text())),
        );
    }

    public function testHomeRejectsUnsupportedCashCurrency(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $user = UserBuilder::aUser()->withEmail('home-cash-currency-invalid@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', '/finance?currency=BTC');

        self::assertResponseRedirects('/finance?currency=RUB');
    }
}
