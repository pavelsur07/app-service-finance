<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Infrastructure;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Infrastructure\Query\CashIntragroupQuery;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CashIntragroupQueryTest extends IntegrationTestCase
{
    public function testManualIdsExtendOnlyTheDateWindowAndSplitsStayAttributedToOneOperation(): void
    {
        $a = $this->company(710);
        $b = $this->company(711);
        $outsider = $this->company(712);
        $account = $this->account($a, 'Банк')->setAccountNumber('12345678901234567890');
        $first = $this->category($a, 'Первая');
        $second = $this->category($a, 'Вторая');
        $start = $this->transaction($a, $account, '2026-11-01', $first, $b->getInn());
        $start->replaceSplits([
            new CashTransactionSplit($start, $first, '90.25', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($start, $second, '9.75', CashTransactionSplitSource::MANUAL),
        ]);
        $end = $this->transaction($a, $account, '2026-11-30', $first, $b->getInn());
        $manual = $this->transaction($a, $account, '2026-09-10', $first, $b->getInn());
        $this->transaction($a, $account, '2026-10-31', $first, $b->getInn());
        $this->transaction($a, $account, '2026-11-03', $first, $a->getInn());
        $this->transaction($a, $account, '2026-11-04', $first, $outsider->getInn());
        $this->transaction($a, $account, '2026-11-05', $first, null);
        $this->transaction($a, $account, '2026-11-06', $first, $b->getInn())->markDeleted(null);
        $this->transaction($a, $this->account($a, 'Валюта', 'USD'), '2026-11-07', $first, $b->getInn());
        $foreign = $this->transaction($outsider, $this->account($outsider, 'Чужой'), '2026-09-10', $this->category($outsider, 'Чужая'), $a->getInn());
        $this->em->flush();

        $query = new CashIntragroupQuery($this->connection);
        $members = [(string) $a->getId(), (string) $b->getId()];
        $from = new \DateTimeImmutable('2026-11-01');
        $to = new \DateTimeImmutable('2026-11-30');
        $rows = $query->transactions($members, $from, $to);
        self::assertEqualsCanonicalizing([$start->getId(), $end->getId()], array_keys($rows));
        $leg = $rows[(string) $start->getId()];
        self::assertSame('Банк ****7890', $leg->accountLabel);
        self::assertSame('100.00', $leg->amount->toDecimalString());
        self::assertEquals([$first->getId() => '90.25', $second->getId() => '9.75'], $leg->splits);
        self::assertEqualsCanonicalizing(
            [$start->getId(), $end->getId(), $manual->getId()],
            array_keys($query->transactions($members, $from, $to, [(string) $manual->getId(), (string) $foreign->getId()])),
        );
    }

    public function testLatestDatesIncludeEmptyAccountsAndExcludeDeletedOperations(): void
    {
        $a = $this->company(713);
        $b = $this->company(714);
        $outside = $this->company(715);
        $latest = $this->account($a, 'Последний')->setAccountNumber('00000000000000987654');
        $threeDays = $this->account($b, 'Три дня');
        $fourDays = $this->account($a, 'Четыре дня');
        $empty = $this->account($a, 'Пустой');
        $this->account($b, 'Архив')->setIsActive(false);
        $this->account($b, 'Валюта', 'USD');
        $this->account($outside, 'Другой');
        $this->transaction($a, $latest, '2027-01-01');
        $this->transaction($b, $threeDays, '2026-12-29');
        $this->transaction($a, $fourDays, '2026-12-28');
        $this->transaction($a, $empty, '2027-01-05')->markDeleted(null);
        $this->em->flush();

        $query = new CashIntragroupQuery($this->connection);
        $statuses = array_column($query->accounts([(string) $a->getId(), (string) $b->getId()]), null, 'id');
        self::assertCount(4, $statuses);
        self::assertSame('Последний ****7654', $statuses[$latest->getId()]->label);
        self::assertSame('2027-01-01', $statuses[$latest->getId()]->lastDate);
        self::assertFalse($statuses[$threeDays->getId()]->lagging);
        self::assertTrue($statuses[$fourDays->getId()]->lagging);
        self::assertNull($statuses[$empty->getId()]->lastDate);
        self::assertFalse($statuses[$empty->getId()]->lagging);
    }

    private function company(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build()->setInn((string) (7700000000 + $index));
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function category(Company $company, string $name): CashflowCategory
    {
        $category = CashflowCategoryBuilder::aCashflowCategory()->withId(Uuid::uuid7()->toString())->withCompany($company)->withName($name)->build();
        $this->em->persist($category);

        return $category;
    }

    private function account(Company $company, string $name, string $currency = 'RUB'): MoneyAccount
    {
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->withName($name)->withCurrency($currency)->build();
        $this->em->persist($account);

        return $account;
    }

    private function transaction(Company $company, MoneyAccount $account, string $date, ?CashflowCategory $category = null, ?string $peerInn = null): CashTransaction
    {
        $tx = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)
            ->withCashflowCategory($category)->withAmount('100.00')->withOccurredAt(new \DateTimeImmutable($date))->withCurrency($account->getCurrency())->build();
        if (null !== $peerInn) {
            $cp = CounterpartyBuilder::aCounterparty()->withCompany($company)->withId(Uuid::uuid7()->toString())->withName('Контрагент '.Uuid::uuid7())->withInn($peerInn)->build();
            $this->em->persist($cp);
            $tx->setCounterparty($cp);
        }
        $this->em->persist($tx);

        return $tx;
    }
}
