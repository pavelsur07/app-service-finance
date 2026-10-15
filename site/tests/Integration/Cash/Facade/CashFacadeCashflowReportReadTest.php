<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Facade;

use App\Cash\Application\DTO\CashCategoryTurnover;
use App\Cash\Application\DTO\CashDailyAmount;
use App\Cash\Application\DTO\CashflowCategoryNode;
use App\Cash\Application\DTO\CashflowReportRow;
use App\Cash\Application\DTO\CashflowReportRowFilter;
use App\Cash\Application\DTO\MoneyAccountOpeningBalance;
use App\Cash\Application\DTO\PlannedPaymentSummary;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanStatus;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Read-методы CashFacade для отчёта ДДС, Telegram и AI-агента (этап 7.3 плана Cash).
 */
final class CashFacadeCashflowReportReadTest extends IntegrationTestCase
{
    private CashFacade $facade;
    private Company $company;
    private Company $foreign;
    private MoneyAccount $rub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->facade = self::getContainer()->get(CashFacade::class);
        $this->company = $this->company('cf-report@example.com');
        $this->foreign = $this->company('cf-foreign@example.com');
        $this->rub = $this->account($this->company, 'Рубли', 'RUB', '2026-01-01', '10.00');
        $this->em->flush();
    }

    public function testCategoryTreeIsPreorderWithSharedChildNodes(): void
    {
        $root = $this->category($this->company, 'Операционные', CashflowFlowKind::OPERATING, sort: 1);
        $childB = $this->category($this->company, 'Б', CashflowFlowKind::OPERATING, $root, 2);
        $childA = $this->category($this->company, 'А', CashflowFlowKind::OPERATING, $root, 1);
        $grand = $this->category($this->company, 'Внук', CashflowFlowKind::OPERATING, $childA, 1);
        $tech = $this->category($this->company, 'Тех', CashflowFlowKind::TECHNICAL, sort: 0, systemCode: 'CUSTOM_TECH');
        $this->category($this->foreign, 'Чужая', CashflowFlowKind::OPERATING);
        $this->em->flush();
        $this->em->clear();

        $tree = $this->facade->getCashflowCategoryTree((string) $this->company->getId());

        self::assertSame(['Тех', 'Операционные', 'А', 'Внук', 'Б'], array_map(static fn (CashflowCategoryNode $n): string => $n->name, $tree));
        self::assertSame([1, 1, 2, 3, 2], array_map(static fn (CashflowCategoryNode $n): int => $n->level, $tree));
        self::assertSame($tree[2], $tree[1]->children[0]);
        self::assertSame($tree[3], $tree[2]->children[0]);
        self::assertSame((string) $childA->getId(), $tree[3]->parentId);
        self::assertSame('CUSTOM_TECH', $tree[0]->systemCode);
        self::assertSame(CashflowFlowKind::TECHNICAL, $tree[0]->flowKind);
        self::assertSame([(string) $grand->getId()], array_map(static fn (CashflowCategoryNode $n): string => $n->id, $tree[2]->children));
        self::assertNull($tree[1]->parentId);
        self::assertSame((string) $childB->getId(), $tree[4]->id);
        self::assertSame((string) $tech->getId(), $tree[0]->id);
    }

    public function testReportRowsApplyFiltersAndExcludeDeleted(): void
    {
        $op = $this->category($this->company, 'Аренда', CashflowFlowKind::OPERATING);
        $tech = $this->category($this->company, 'Тех', CashflowFlowKind::TECHNICAL);
        $usd = $this->account($this->company, 'Доллары', 'USD', '2026-01-01', '0.00');
        $center = new FinancialResponsibilityCenter((string) $this->company->getId(), 'SALES', 'Продажи');
        $this->em->persist($center);
        $centerId = (string) $center->getId();
        $this->tx($this->rub, CashDirection::OUTFLOW, '30.00', '2026-03-03', $op)->setResponsibilityCenterId($centerId);
        $this->tx($this->rub, CashDirection::INFLOW, '20.00', '2026-03-04', $tech);
        $this->tx($this->rub, CashDirection::INFLOW, '7.00', '2026-03-04', $op)->setIsTransfer(true);
        $this->tx($usd, CashDirection::INFLOW, '5.00', '2026-03-05', $op);
        $this->tx($this->rub, CashDirection::INFLOW, '9.00', '2026-03-05');
        $this->tx($this->rub, CashDirection::INFLOW, '99.00', '2026-03-05', $op)->markDeleted(null, 'test');
        $this->tx($this->rub, CashDirection::INFLOW, '99.00', '2026-04-01', $op);
        $this->tx($this->account($this->foreign, 'Чужой', 'RUB', '2026-01-01', '0.00'), CashDirection::INFLOW, '99.00', '2026-03-05');
        $this->em->flush();
        $id = (string) $this->company->getId();
        $amounts = fn (CashflowReportRowFilter $f): array => $this->sorted(array_map(
            static fn (CashflowReportRow $r): string => $r->currency.' '.($r->amount ?? 'null'),
            $this->facade->listCashflowReportRows($id, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'), $f),
        ));

        self::assertSame(['RUB 20.00', 'RUB 30.00', 'RUB 7.00', 'RUB null', 'USD 5.00'], $amounts(new CashflowReportRowFilter()));
        self::assertSame(['RUB 30.00'], $amounts(new CashflowReportRowFilter(dashboardScope: true, dashboardCurrency: 'RUB')));
        self::assertSame(['RUB 30.00'], $amounts(new CashflowReportRowFilter(dashboardScope: true, dashboardCurrency: 'RUB', dashboardFlowKind: CashflowFlowKind::OPERATING)));
        self::assertSame([], $amounts(new CashflowReportRowFilter(dashboardScope: true, dashboardCurrency: 'RUB', dashboardFlowKind: CashflowFlowKind::FINANCING)));
        self::assertSame(['USD 5.00'], $amounts(new CashflowReportRowFilter(currency: 'USD')));
        self::assertSame(['RUB 30.00'], $amounts(new CashflowReportRowFilter(responsibilityCenterId: $centerId)));
        self::assertSame(['RUB 30.00'], $amounts(new CashflowReportRowFilter(responsibilityCenterIds: [$centerId])));
        self::assertSame([], $amounts(new CashflowReportRowFilter(projectDirectionIds: [Uuid::uuid4()->toString()])));

        $row = $this->facade->listCashflowReportRows($id, new \DateTimeImmutable('2026-03-03'), new \DateTimeImmutable('2026-03-03'), new CashflowReportRowFilter())[0];
        self::assertEquals(new CashflowReportRow((string) $op->getId(), null, null, $centerId, CashDirection::OUTFLOW, '30.00', 'RUB', new \DateTimeImmutable('2026-03-03')), $row);
    }

    public function testReportOpeningBalancesFollowRegisterThenAccount(): void
    {
        $sameDay = $this->account($this->company, 'В-день', 'RUB', '2026-03-01', '1.00');
        $before = $this->account($this->company, 'Б-раньше', 'RUB', '2026-01-01', '2.00');
        $none = $this->account($this->company, 'А-пусто', 'USD', '2026-01-01', '3.00');
        $preOpening = $this->account($this->company, 'Г-до-открытия', 'RUB', '2026-03-05', '4.00');
        $this->balance($sameDay, '2026-03-10', '100.00', '110.00');
        $this->balance($before, '2026-03-02', '50.00', '60.00');
        $this->balance($before, '2026-03-11', '999.00', '999.00');
        // Строка в день отчёта берётся и раньше даты открытия, а строки до него — нет (как findOneBy/findLastBefore).
        $this->balance($preOpening, '2026-03-10', '70.00', '80.00');
        $this->balance($preOpening, '2026-03-04', '0.00', '555.00');
        $this->em->flush();
        $id = (string) $this->company->getId();
        $date = new \DateTimeImmutable('2026-03-10');

        $all = $this->facade->getReportOpeningBalances($id, $date);
        $byId = [];
        foreach ($all as $opening) {
            $byId[$opening->accountId] = $opening->openingBalance;
        }
        self::assertSame('100.00', $byId[(string) $sameDay->getId()]);
        self::assertSame('60.00', $byId[(string) $before->getId()]);
        self::assertSame('3.00', $byId[(string) $none->getId()]);
        self::assertSame('70.00', $byId[(string) $preOpening->getId()]);
        self::assertSame('10.00', $byId[(string) $this->rub->getId()]);
        self::assertCount(5, $all);

        self::assertEquals(
            [new MoneyAccountOpeningBalance((string) $none->getId(), 'USD', '3.00')],
            $this->facade->getReportOpeningBalances($id, $date, 'USD'),
        );
        self::assertSame(
            ['Б-раньше', 'В-день', 'Г-до-открытия', 'Рубли'],
            array_map(fn (MoneyAccountOpeningBalance $o): string => $this->em->find(MoneyAccount::class, $o->accountId)?->getName() ?? '', $this->facade->getReportOpeningBalances($id, $date, 'RUB')),
        );
    }

    public function testAgentAggregatesIncludeDeletedAndTransfers(): void
    {
        $op = $this->category($this->company, 'Аренда', CashflowFlowKind::OPERATING);
        $this->tx($this->rub, CashDirection::INFLOW, '100.00', '2026-03-02', $op);
        $this->tx($this->rub, CashDirection::OUTFLOW, '30.00', '2026-03-02');
        $this->tx($this->rub, CashDirection::OUTFLOW, '5.00', '2026-03-03', $op)->markDeleted(null, 'test');
        $this->tx($this->rub, CashDirection::INFLOW, '1.00', '2026-03-03')->setIsTransfer(true);
        $this->tx($this->account($this->foreign, 'Чужой', 'RUB', '2026-01-01', '0.00'), CashDirection::INFLOW, '999.00', '2026-03-02');
        $this->em->flush();
        $id = (string) $this->company->getId();
        $from = new \DateTimeImmutable('2026-03-01');
        $to = new \DateTimeImmutable('2026-03-31');

        self::assertEquals([
            new CashCategoryTurnover('Аренда', '100.00', '5.00'),
            new CashCategoryTurnover('Без категории', '1.00', '30.00'),
        ], $this->facade->sumTurnoverByCategoryNameIncludingDeleted($id, $from, $to));
        self::assertEquals([
            new CashDailyAmount('2026-03-02', '70.00'),
            new CashDailyAmount('2026-03-03', '-4.00'),
        ], $this->facade->sumNetByDayIncludingDeleted($id, $from, $to));
        self::assertSame(['inflow' => '101.00', 'outflow' => '35.00'], $this->facade->sumTurnoverIncludingDeleted($id, $from, $to));
    }

    public function testPlannedPaymentsAreFilteredByStatusAndPeriod(): void
    {
        $op = $this->category($this->company, 'Аренда', CashflowFlowKind::OPERATING);
        foreach ([['2026-03-05', PaymentPlanStatus::APPROVED, '20.00'], ['2026-03-02', PaymentPlanStatus::PLANNED, '10.00'], ['2026-03-03', PaymentPlanStatus::DRAFT, '99.00'], ['2026-04-02', PaymentPlanStatus::PLANNED, '99.00']] as [$date, $status, $amount]) {
            $plan = new PaymentPlan(Uuid::uuid4()->toString(), $this->company, $op, new \DateTimeImmutable($date), $amount);
            $plan->setStatus($status);
            $this->em->persist($plan);
        }
        $this->em->flush();

        self::assertEquals([
            new PlannedPaymentSummary(new \DateTimeImmutable('2026-03-02'), '10.00', 'Аренда', null),
            new PlannedPaymentSummary(new \DateTimeImmutable('2026-03-05'), '20.00', 'Аренда', null),
        ], $this->facade->listPlannedPayments((string) $this->company->getId(), new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31')));
        self::assertSame([], $this->facade->listPlannedPayments((string) $this->foreign->getId(), new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31')));
    }

    public function testUnknownCompanyIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->facade->getCashflowCategoryTree(Uuid::uuid4()->toString());
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function company(string $email): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail($email)->withPasswordHash('p')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function account(Company $company, string $name, string $currency, string $openingDate, string $openingBalance): MoneyAccount
    {
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, $name, $currency);
        $account->setOpeningBalance($openingBalance);
        $account->setOpeningBalanceDate(new \DateTimeImmutable($openingDate));
        $this->em->persist($account);

        return $account;
    }

    private function category(Company $company, string $name, CashflowFlowKind $kind, ?CashflowCategory $parent = null, int $sort = 0, ?string $systemCode = null): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $category->setFlowKind($kind);
        $category->setSort($sort);
        $category->setSystemCode($systemCode);
        $category->setParent($parent);
        $this->em->persist($category);

        return $category;
    }

    private function tx(MoneyAccount $account, CashDirection $direction, string $amount, string $date, ?CashflowCategory $category = null): CashTransaction
    {
        $tx = new CashTransaction(Uuid::uuid4()->toString(), $account->getCompany(), $account, $direction, $amount, $account->getCurrency(), new \DateTimeImmutable($date));
        if (null !== $category) {
            $tx->replaceSplits([new CashTransactionSplit($tx, $category, $amount, CashTransactionSplitSource::MANUAL)]);
        }
        $this->em->persist($tx);

        return $tx;
    }

    private function balance(MoneyAccount $account, string $date, string $opening, string $closing): void
    {
        $this->em->persist(new MoneyAccountDailyBalance(Uuid::uuid4()->toString(), $account->getCompany(), $account, new \DateTimeImmutable($date), $opening, '0', '0', $closing, $account->getCurrency()));
    }
}
