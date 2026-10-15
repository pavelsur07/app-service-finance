<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Facade;

use App\Cash\Application\DTO\CashStatementLine;
use App\Cash\Application\DTO\CreateDocumentCommand;
use App\Cash\Application\DTO\MoneyAccountDailyBalanceRow;
use App\Cash\Application\DTO\MoneyAccountSummary;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Finance\Facade\FinanceFacade;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Read-методы CashFacade для отчётов Finance (этап 7.2 плана Cash).
 */
final class CashFacadeReportReadTest extends IntegrationTestCase
{
    private CashFacade $facade;
    private Company $company;
    private Company $foreign;
    private MoneyAccount $bank;
    private MoneyAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->facade = self::getContainer()->get(CashFacade::class);

        $this->company = $this->company('report-read@example.com');
        $this->foreign = $this->company('report-foreign@example.com');
        $this->bank = $this->account($this->company, 'Банк', MoneyAccountType::BANK, 'RUB');
        $this->cash = $this->account($this->company, 'Альфа-касса', MoneyAccountType::CASH, 'RUB');
        $this->em->flush();
    }

    public function testListsAccountsByFiltersAndOrder(): void
    {
        $usd = $this->account($this->company, 'Валютный', MoneyAccountType::BANK, 'USD');
        $closed = $this->account($this->company, 'Архив', MoneyAccountType::BANK, 'RUB');
        $closed->setIsActive(false);
        $this->account($this->foreign, 'Чужой', MoneyAccountType::BANK, 'RUB');
        $this->em->flush();
        $id = (string) $this->company->getId();
        $names = static fn (array $accounts): array => array_map(static fn (MoneyAccountSummary $a): string => $a->name, $accounts);

        self::assertSame(['Альфа-касса', 'Архив', 'Банк', 'Валютный'], $names($this->facade->listMoneyAccounts($id, activeOnly: false)));
        self::assertSame(['Банк', 'Валютный', 'Альфа-касса'], $names($this->facade->listMoneyAccounts($id, activeOnly: true, typeFirst: true)));
        self::assertSame(['Альфа-касса', 'Банк'], $names($this->facade->listMoneyAccounts($id, activeOnly: true, currency: 'RUB')));

        $summary = $this->facade->listMoneyAccounts($id, activeOnly: true, currency: 'USD')[0];
        self::assertEquals(new MoneyAccountSummary((string) $usd->getId(), 'Валютный', MoneyAccountType::BANK, 'USD', '10.00', new \DateTimeImmutable('2026-01-01'), '0.00'), $summary);
    }

    public function testBalancesTurnoversAndDailyRowsIgnoreForeignAccounts(): void
    {
        $foreignAccount = $this->account($this->foreign, 'Чужой', MoneyAccountType::BANK, 'RUB');
        $this->balance($this->bank, '2026-03-01', '100.00', '150.00');
        $this->balance($this->bank, '2026-03-05', '150.00', '120.00');
        $this->balance($foreignAccount, '2026-03-05', '1.00', '999.00');
        $this->tx($this->bank, CashDirection::INFLOW, '50.00', '2026-03-01');
        $this->tx($this->bank, CashDirection::OUTFLOW, '30.00', '2026-03-05');
        $this->tx($this->bank, CashDirection::INFLOW, '7.00', '2026-03-05', transfer: true);
        $this->tx($foreignAccount, CashDirection::INFLOW, '999.00', '2026-03-05');
        $this->em->flush();

        $id = (string) $this->company->getId();
        $ids = [(string) $this->bank->getId(), (string) $foreignAccount->getId()];

        self::assertSame([(string) $this->bank->getId() => '150.00'], $this->facade->getOpeningBalancesUpToDate($id, new \DateTimeImmutable('2026-03-05'), $ids));
        self::assertSame([(string) $this->bank->getId() => '150.00'], $this->facade->getOpeningBalancesUpToDate($id, new \DateTimeImmutable('2026-03-04'), $ids));
        self::assertSame([(string) $this->bank->getId() => '120.00'], $this->facade->getClosingBalancesUpToDate($id, new \DateTimeImmutable('2026-03-31'), $ids));
        self::assertSame(
            [(string) $this->bank->getId() => ['inflow' => '57.00', 'outflow' => '30.00']],
            $this->facade->sumTurnoversByAccount($id, $ids, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31')),
        );
        self::assertEquals([
            new MoneyAccountDailyBalanceRow('2026-03-01', (string) $this->bank->getId(), '100.00', '150.00'),
            new MoneyAccountDailyBalanceRow('2026-03-05', (string) $this->bank->getId(), '150.00', '120.00'),
        ], $this->facade->listDailyBalances($id, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'), []));
        self::assertSame([], $this->facade->listDailyBalances($id, new \DateTimeImmutable('2026-03-31'), new \DateTimeImmutable('2026-03-01'), []));
    }

    public function testGrossTurnoverExcludesTransfersAndFiltersFlowKind(): void
    {
        $rent = $this->category('Аренда', CashflowFlowKind::OPERATING);
        $loan = $this->category('Кредит', CashflowFlowKind::FINANCING);
        $this->tx($this->bank, CashDirection::INFLOW, '100.00', '2026-03-02', $loan);
        $this->tx($this->bank, CashDirection::OUTFLOW, '40.00', '2026-03-03', $rent);
        $this->tx($this->bank, CashDirection::OUTFLOW, '999.00', '2026-03-03', $rent, transfer: true);
        $this->em->flush();
        $id = (string) $this->company->getId();
        $from = new \DateTimeImmutable('2026-03-01');
        $to = new \DateTimeImmutable('2026-03-31');

        self::assertSame(['inflow' => '100.00', 'outflow' => '40.00'], $this->facade->sumGrossTurnoverExcludingTransfers($id, $from, $to, 'RUB', null));
        self::assertSame(['inflow' => '0.00', 'outflow' => '40.00'], $this->facade->sumGrossTurnoverExcludingTransfers($id, $from, $to, 'RUB', CashflowFlowKind::OPERATING));
    }

    public function testStatementLinesSplitTransactionsAndFilter(): void
    {
        $rent = $this->category('Аренда', CashflowFlowKind::OPERATING);
        $tax = $this->category('Налоги', CashflowFlowKind::OPERATING);
        $split = $this->tx($this->bank, CashDirection::OUTFLOW, '100.00', '2026-03-03');
        $split->setExternalId('PP-1');
        $split->setDescription('Аренда и налог');
        $split->replaceSplits([
            new CashTransactionSplit($split, $rent, '70.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($split, $tax, '30.00', CashTransactionSplitSource::MANUAL),
        ]);
        $this->tx($this->cash, CashDirection::INFLOW, '5.00', '2026-03-04');
        $this->tx($this->cash, CashDirection::INFLOW, '9.00', '2026-03-04', deleted: true);
        $foreignAccount = $this->account($this->foreign, 'Чужой', MoneyAccountType::BANK, 'RUB');
        $foreignCategory = CashflowCategoryBuilder::aCashflowCategory()->withId(Uuid::uuid4()->toString())->withCompany($this->foreign)->withName('Чужая')->build();
        $this->em->persist($foreignCategory);
        $foreignTx = $this->tx($foreignAccount, CashDirection::OUTFLOW, '1.00', '2026-03-03');
        $foreignTx->replaceSplits([new CashTransactionSplit($foreignTx, $foreignCategory, '1.00', CashTransactionSplitSource::MANUAL)]);
        $this->em->flush();
        $id = (string) $this->company->getId();
        $from = new \DateTimeImmutable('2026-03-01');
        $to = new \DateTimeImmutable('2026-03-31');

        $lines = $this->facade->listStatementLines($id, $from, $to, null, null);
        self::assertCount(3, $lines);
        self::assertEquals(
            new CashStatementLine(new \DateTimeImmutable('2026-03-03'), 'PP-1', null, 'Аренда и налог', CashDirection::OUTFLOW, '70.00', (string) $rent->getId(), 'Аренда'),
            $lines[0],
        );
        self::assertSame(['30.00', '5.00'], [$lines[1]->amount, $lines[2]->amount]);
        self::assertNull($lines[2]->categoryId);
        self::assertSame('', $lines[2]->description);

        // Фильтр по статье гидрирует коллекцию разбивок частично — только на «чистом» EntityManager,
        // как в отдельном HTTP-запросе отчёта.
        $this->em->clear();
        $byCategory = $this->facade->listStatementLines($id, $from, $to, null, (string) $tax->getId());
        self::assertSame([['30.00', 'Налоги']], array_map(static fn (CashStatementLine $l): array => [$l->amount, (string) $l->categoryName], $byCategory));
        self::assertCount(1, $this->facade->listStatementLines($id, $from, $to, (string) $this->cash->getId(), null));
        self::assertCount(1, $this->facade->listStatementLines((string) $this->foreign->getId(), $from, $to, null, null));

        $this->em->clear();
        self::assertSame([], $this->facade->listStatementLines($id, $from, $to, (string) $foreignAccount->getId(), null));
        self::assertSame([], $this->facade->listStatementLines($id, $from, $to, null, (string) $foreignCategory->getId()));
        self::assertCount(3, $this->facade->listStatementLines($id, $from, $to, '0', ''));
    }

    public function testRecalculateDailyBalancesBuildsRegister(): void
    {
        $this->tx($this->bank, CashDirection::INFLOW, '25.00', '2026-03-02');
        $this->em->flush();
        $id = (string) $this->company->getId();

        $this->facade->recalculateDailyBalances($id, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-03'));

        self::assertSame(
            [(string) $this->bank->getId() => '35.00'],
            $this->facade->getClosingBalancesUpToDate($id, new \DateTimeImmutable('2026-03-03'), [(string) $this->bank->getId()]),
        );
    }

    public function testUnknownCompanyIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->facade->listStatementLines(Uuid::uuid4()->toString(), new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'), null, null);
    }

    public function testFinanceDocumentRejectsTransactionOfAnotherCompanyOrCommand(): void
    {
        $tx = $this->tx($this->bank, CashDirection::OUTFLOW, '10.00', '2026-03-02');
        $this->em->flush();
        $finance = self::getContainer()->get(FinanceFacade::class);
        $command = static fn (string $txId): CreateDocumentCommand => new CreateDocumentCommand($txId, new \DateTimeImmutable('2026-03-02'), '10.00', null, null, null, null, true);

        foreach ([
            [(string) $this->foreign->getId(), (string) $tx->getId()],
            [(string) $this->company->getId(), Uuid::uuid4()->toString()],
        ] as [$companyId, $txId]) {
            try {
                $finance->createDocumentFromCashTransaction($companyId, $tx, $command($txId));
                self::fail('DomainException expected.');
            } catch (\DomainException) {
            }
        }
        self::assertCount(0, $tx->getDocuments());
    }

    private function company(string $email): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail($email)->withPasswordHash('p')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function account(Company $company, string $name, MoneyAccountType $type, string $currency): MoneyAccount
    {
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, $type, $name, $currency);
        $account->setOpeningBalance('10.00');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($account);

        return $account;
    }

    private function category(string $name, CashflowFlowKind $kind): CashflowCategory
    {
        $category = CashflowCategoryBuilder::aCashflowCategory()->withId(Uuid::uuid4()->toString())->withCompany($this->company)->withName($name)->build();
        $category->setFlowKind($kind);
        $this->em->persist($category);

        return $category;
    }

    private function tx(
        MoneyAccount $account,
        CashDirection $direction,
        string $amount,
        string $date,
        ?CashflowCategory $category = null,
        bool $transfer = false,
        bool $deleted = false,
    ): CashTransaction {
        $tx = new CashTransaction(Uuid::uuid4()->toString(), $account->getCompany(), $account, $direction, $amount, $account->getCurrency(), new \DateTimeImmutable($date));
        $tx->setIsTransfer($transfer);
        if (null !== $category) {
            $tx->setCashflowCategory($category);
            $tx->replaceSplits([new CashTransactionSplit($tx, $category, $amount, CashTransactionSplitSource::MANUAL)]);
        }
        if ($deleted) {
            $tx->markDeleted(null, 'test');
        }
        $this->em->persist($tx);

        return $tx;
    }

    private function balance(MoneyAccount $account, string $date, string $opening, string $closing): void
    {
        $this->em->persist(new MoneyAccountDailyBalance(Uuid::uuid4()->toString(), $account->getCompany(), $account, new \DateTimeImmutable($date), $opening, '0', '0', $closing, $account->getCurrency()));
    }
}
