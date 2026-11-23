<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Facade;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Exception\ExternalCashReferenceUnavailableException;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class ExternalCashReadTest extends IntegrationTestCase
{
    public function testReferencesAreScopedSearchableAndOrderedById(): void
    {
        $company = $this->company(1);
        $other = $this->company(2);
        $rub = $this->account($company, 'Резерв 100%', 'RUB');
        $btc = $this->account($company, 'Кошелёк', 'BTC');
        $inactive = $this->account($company, 'Закрытый', 'RUB');
        $inactive->setIsActive(false);
        $this->account($other, 'Резерв другой компании', 'RUB');
        $root = $this->category($company, 'Расходы');
        $child = $this->category($company, 'Оплата аренды', $root);
        $this->category($other, 'Оплата чужой аренды');
        $this->em->flush();

        $cash = self::getContainer()->get(CashFacade::class);
        $companyId = (string) $company->getId();
        self::assertSame([['id' => $rub->getId(), 'name' => 'Резерв 100%', 'currency' => 'RUB']], $cash->listExternalAccounts($companyId, 'РЕЗЕРВ 100%', null, 2));
        self::assertSame([], $cash->listExternalAccounts($companyId, '_', null, 2));
        self::assertSame([['id' => $btc->getId(), 'name' => 'Кошелёк', 'currency' => 'BTC']], $cash->listExternalAccounts($companyId, null, $rub->getId(), 2));
        self::assertSame([['id' => $child->getId(), 'name' => 'Оплата аренды', 'parentId' => $root->getId()]], $cash->listExternalCategories($companyId, 'АРЕНДЫ', null, 2));
        self::assertSame([['id' => $root->getId(), 'name' => 'Расходы', 'parentId' => null]], $cash->listExternalCategories($companyId, null, null, 1));
    }

    public function testFactsRetainAllSplitsAndLegacyValuesAndPaginateWithinCompany(): void
    {
        $company = $this->company(1);
        $other = $this->company(2);
        $account = $this->account($company, 'Основной', 'RUB');
        $wallet = $this->account($company, 'Кошелёк', 'BTC');
        $foreignAccount = $this->account($other, 'Другой', 'RUB');
        $first = $this->category($company, 'Аренда');
        $second = $this->category($company, 'Услуги');
        $split = $this->transaction($company, $account, '2026-10-01', '1000.00');
        $split->replaceSplits([
            new CashTransactionSplit($split, $first, '700.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($split, $second, '300.00', CashTransactionSplitSource::MANUAL),
        ]);
        $legacy = $this->transaction($company, $wallet, '2026-10-01', '-1.00');
        $legacy->setCashflowCategory($first);
        $this->transaction($company, $account, '2026-10-11', '3.00');
        $deleted = $this->transaction($company, $account, '2026-10-10', '1.00');
        $deleted->markDeleted(null);
        $last = $this->transaction($company, $account, '2026-10-10', '0.00');
        $last->setIsTransfer(true);
        $this->transaction($other, $foreignAccount, '2026-10-01', '55.00');

        $this->em->flush();
        $cash = self::getContainer()->get(CashFacade::class);
        $companyId = (string) $company->getId();
        $from = new \DateTimeImmutable('2026-10-01');
        $to = new \DateTimeImmutable('2026-10-10');
        $rows = $cash->listExternalTransactions($companyId, $from, $to, null, (string) $first->getId(), CashDirection::OUTFLOW, null, 3);
        self::assertSame([$split->getId(), $legacy->getId()], array_column($rows, 'id'));
        self::assertSame('1000.00', $rows[0]['amount']);
        self::assertSame([
            ['categoryId' => $first->getId(), 'amount' => '700.00'],
            ['categoryId' => $second->getId(), 'amount' => '300.00'],
        ], $rows[0]['splits']);
        self::assertSame('BTC', $rows[1]['currency']);
        self::assertSame([['categoryId' => $first->getId(), 'amount' => '-1.00']], $rows[1]['splits']);

        $rows = $cash->listExternalTransactions($companyId, $from, $to, null, null, null, ['date' => '2026-10-01', 'id' => (string) $split->getId()], 2);
        self::assertSame([$legacy->getId(), $last->getId()], array_column($rows, 'id'));
        self::assertSame('0.00', $rows[1]['amount']);
        self::assertTrue($rows[1]['isTransfer']);
        self::assertSame([], $rows[1]['splits']);
        $rows = $cash->listExternalTransactions($companyId, $from, $to, $account->getId(), null, CashDirection::OUTFLOW, null, 3);
        self::assertSame([$split->getId(), $last->getId()], array_column($rows, 'id'));

        $this->expectException(ExternalCashReferenceUnavailableException::class);
        $cash->listExternalTransactions($companyId, $from, $to, $foreignAccount->getId(), null, null, null, 3);
    }

    private function transaction(Company $company, MoneyAccount $account, string $date, string $amount): CashTransaction
    {
        $transaction = new CashTransaction(Uuid::uuid7()->toString(), $company, $account, CashDirection::OUTFLOW, $amount, $account->getCurrency(), new \DateTimeImmutable($date));
        $this->em->persist($transaction);

        return $transaction;
    }

    private function company(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function account(Company $company, string $name, string $currency): MoneyAccount
    {
        $type = 'BTC' === $currency ? MoneyAccountType::CRYPTO_WALLET : MoneyAccountType::BANK;
        $account = new MoneyAccount(Uuid::uuid7()->toString(), $company, $type, $name, $currency);
        $this->em->persist($account);

        return $account;
    }

    private function category(Company $company, string $name, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid7()->toString(), $company);
        $category->setName($name)->setParent($parent);
        $this->em->persist($category);

        return $category;
    }
}
