<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Facade;

use App\Cash\Application\DTO\CashCategoryAmount;
use App\Cash\Application\DTO\CashDailyAmount;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Cash\MoneyFundBuilder;
use App\Tests\Builders\Cash\MoneyFundMovementBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Read-методы CashFacade для дашборда Analytics (этап 7.1 плана Cash): суммы по
 * компании, без переводов и удалённых, в границах периода и валюты.
 */
final class CashFacadeDashboardReadTest extends IntegrationTestCase
{
    private CashFacade $facade;
    private Company $company;
    private MoneyAccount $rubAccount;
    private CashflowCategory $rent;
    private CashflowCategory $capex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->facade = self::getContainer()->get(CashFacade::class);

        $owner = UserBuilder::aUser()->withEmail('dashboard-read@example.com')->withPasswordHash('pass')->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($owner)->withName('Dashboard Read')->build();
        $this->rubAccount = $this->account($this->company, 'Б-рубли', 'RUB');
        $this->rent = $this->category($this->company, 'Аренда', CashflowFlowKind::OPERATING, null);
        $this->capex = $this->category($this->company, 'CAPEX', CashflowFlowKind::INVESTING, 'CAPEX');
        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testFlowSumsExcludeTransfersDeletedOtherPeriodsCurrenciesAndCompanies(): void
    {
        $this->tx(CashDirection::INFLOW, '100.00', '2026-03-03');
        $this->tx(CashDirection::INFLOW, '50.50', '2026-03-03');
        $this->tx(CashDirection::INFLOW, '20.00', '2026-03-31');
        $this->tx(CashDirection::OUTFLOW, '30.00', '2026-03-04', $this->rent);
        $this->tx(CashDirection::OUTFLOW, '45.00', '2026-03-05', $this->capex);
        $this->tx(CashDirection::OUTFLOW, '5.00', '2026-03-05');
        $this->tx(CashDirection::INFLOW, '999.00', '2026-03-10', null, transfer: true);
        $this->tx(CashDirection::INFLOW, '999.00', '2026-03-10', null, deleted: true);
        $this->tx(CashDirection::INFLOW, '999.00', '2026-04-01');
        $this->tx(CashDirection::INFLOW, '999.00', '2026-03-10', null, account: $this->account($this->company, 'А-доллары', 'USD'));
        $foreignOwner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('dashboard-foreign@example.com')->withPasswordHash('p')->build();
        $foreign = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($foreignOwner)->build();
        $this->em->persist($foreignOwner);
        $this->em->persist($foreign);
        $this->tx(CashDirection::INFLOW, '999.00', '2026-03-10', null, account: $this->account($foreign, 'Чужой', 'RUB'));
        $this->em->flush();

        $id = (string) $this->company->getId();
        $from = new \DateTimeImmutable('2026-03-01');
        $to = new \DateTimeImmutable('2026-03-31');

        self::assertSame('170.50', $this->facade->sumInflowExcludingTransfers($id, $from, $to, 'RUB'));
        self::assertEquals(
            [new CashDailyAmount('2026-03-03', '150.50'), new CashDailyAmount('2026-03-31', '20.00')],
            $this->facade->sumInflowByDayExcludingTransfers($id, $from, $to, 'RUB'),
        );
        self::assertSame('80.00', ltrim($this->facade->sumOutflowExcludingTransfers($id, $from, $to, 'RUB'), '-'));
        self::assertEquals(
            ['2026-03-04' => '30.00', '2026-03-05' => '50.00'],
            array_column(array_map(static fn (CashDailyAmount $d): array => [$d->date, ltrim($d->amount, '-')], $this->facade->sumOutflowByDayExcludingTransfers($id, $from, $to, 'RUB')), 1, 0),
        );
        self::assertSame('45.00', ltrim($this->facade->sumCapexOutflowExcludingTransfers($id, $from, $to, 'RUB'), '-'));
        self::assertEquals([
            new CashCategoryAmount((string) $this->capex->getId(), 'CAPEX', '45.00'),
            new CashCategoryAmount((string) $this->rent->getId(), 'Аренда', '30.00'),
            new CashCategoryAmount(null, 'Без категории', '5.00'),
        ], $this->facade->sumOutflowByCategoryExcludingTransfers($id, $from, $to, 'RUB'));
        $net = $this->facade->sumNetByFlowKindExcludingTransfers($id, $from, $to, 'RUB');
        self::assertSame('0.00', $net['FINANCING']);
        self::assertSame('30.00', ltrim($net['OPERATING'], '-'));
        self::assertSame('45.00', ltrim($net['INVESTING'], '-'));
        self::assertSame('0.00', $this->facade->sumInflowExcludingTransfers((string) $foreign->getId(), new \DateTimeImmutable('2026-04-02'), new \DateTimeImmutable('2026-04-30'), 'RUB'));
    }

    public function testAccountsBalancesAndFundsAreScopedToCompanyAndCurrency(): void
    {
        $usd = $this->account($this->company, 'А-доллары', 'USD');
        $inactive = $this->account($this->company, 'В-закрытый', 'RUB');
        $inactive->setIsActive(false);
        $second = $this->account($this->company, 'А-рубли', 'RUB');
        $this->balance($this->rubAccount, '2026-03-01', '100.10');
        $this->balance($this->rubAccount, '2026-03-10', '250.25');
        $this->balance($second, '2026-03-05', '10.00');
        $this->balance($usd, '2026-03-05', '7.00');

        $fund = MoneyFundBuilder::aFund()->withCompany($this->company)->withCurrency('RUB')->build();
        $usdFund = MoneyFundBuilder::aFund()->withIndex(2)->withCompany($this->company)->withCurrency('USD')->build();
        $this->em->persist($fund);
        $this->em->persist($usdFund);
        $this->em->persist(MoneyFundMovementBuilder::aMovement()->withIndex(1)->withCompany($this->company)->withFund($fund)->withOccurredAt(new \DateTimeImmutable('2026-03-02 12:00'))->withAmountMinor(12345)->build());
        $this->em->persist(MoneyFundMovementBuilder::aMovement()->withIndex(2)->withCompany($this->company)->withFund($fund)->withOccurredAt(new \DateTimeImmutable('2026-03-20 23:00'))->withAmountMinor(-345)->build());
        $this->em->persist(MoneyFundMovementBuilder::aMovement()->withIndex(3)->withCompany($this->company)->withFund($usdFund)->withOccurredAt(new \DateTimeImmutable('2026-03-02'))->withAmountMinor(777)->build());
        $this->em->flush();

        $id = (string) $this->company->getId();
        $ids = $this->facade->listActiveMoneyAccountIds($id, 'RUB');

        self::assertSame([(string) $second->getId(), (string) $this->rubAccount->getId()], $ids);
        self::assertSame('110.10', $this->facade->sumClosingBalancesUpToDate($id, new \DateTimeImmutable('2026-03-05'), $ids));
        self::assertSame('260.25', $this->facade->sumClosingBalancesUpToDate($id, new \DateTimeImmutable('2026-03-31'), $ids));
        self::assertSame('0.00', $this->facade->sumClosingBalancesUpToDate($id, new \DateTimeImmutable('2026-03-31'), []));
        self::assertSame(12345, $this->facade->sumFundBalancesMinorUpToDate($id, new \DateTimeImmutable('2026-03-19'), 'RUB'));
        self::assertSame(12000, $this->facade->sumFundBalancesMinorUpToDate($id, new \DateTimeImmutable('2026-03-20'), 'RUB'));
        self::assertSame(0, $this->facade->sumFundBalancesMinorUpToDate($id, new \DateTimeImmutable('2026-03-01'), 'RUB'));
    }

    public function testUnknownCompanyIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->facade->listActiveMoneyAccountIds(Uuid::uuid4()->toString(), 'RUB');
    }

    private function account(Company $company, string $name, string $currency): MoneyAccount
    {
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName($name)->withCurrency($currency)->build();
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($account);

        return $account;
    }

    private function category(Company $company, string $name, CashflowFlowKind $kind, ?string $systemCode): CashflowCategory
    {
        $category = CashflowCategoryBuilder::aCashflowCategory()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName($name)->build();
        $category->setFlowKind($kind);
        $category->setSystemCode($systemCode);
        $this->em->persist($category);

        return $category;
    }

    private function tx(
        CashDirection $direction,
        string $amount,
        string $date,
        ?CashflowCategory $category = null,
        bool $transfer = false,
        bool $deleted = false,
        ?MoneyAccount $account = null,
    ): void {
        $account ??= $this->rubAccount;
        $tx = new CashTransaction(Uuid::uuid4()->toString(), $account->getCompany(), $account, $direction, $amount, $account->getCurrency(), new \DateTimeImmutable($date.' 10:00'));
        $tx->setIsTransfer($transfer);
        if (null !== $category) {
            $tx->setCashflowCategory($category);
            $tx->replaceSplits([new CashTransactionSplit($tx, $category, $amount, CashTransactionSplitSource::MANUAL)]);
        }
        if ($deleted) {
            $tx->markDeleted(null, 'test');
        }
        $this->em->persist($tx);
    }

    private function balance(MoneyAccount $account, string $date, string $closing): void
    {
        $this->em->persist(new MoneyAccountDailyBalance(Uuid::uuid4()->toString(), $account->getCompany(), $account, new \DateTimeImmutable($date), '0', '0', '0', $closing, $account->getCurrency()));
    }
}
