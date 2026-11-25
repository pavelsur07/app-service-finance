<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\Category\ApplyCashflowCoreTemplateAction;
use App\Cash\Application\Category\EnsureCashflowSystemCategoriesAction;
use App\Cash\Application\Category\SyncCashflowGroupChartAction;
use App\Cash\Application\DTO\CashflowGroupReport;
use App\Cash\Application\DTO\CashflowGroupReportLine;
use App\Cash\Application\DTO\CashflowReportRowFilter;
use App\Cash\Application\Report\BuildCashflowGroupReport;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\ConsolidationMembershipBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/** Консолидированный отчёт ДДС группы (cashflow-group-chart, этап 4). */
final class BuildCashflowGroupReportTest extends IntegrationTestCase
{
    private const HEAD_INN = '7700000001';
    private const MEMBER_INN = '7700000002';

    private User $owner;
    private Company $head;
    private Company $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('report-'.Uuid::uuid4()->toString().'@example.test')->build();
        $this->em->persist($this->owner);
        $this->em->flush();

        $this->head = $this->company('Головная', self::HEAD_INN);
        self::getContainer()->get(ApplyCashflowCoreTemplateAction::class)((string) $this->head->getId());
        $this->em->flush();
        $this->member = $this->company('Дочерняя', self::MEMBER_INN);
        $this->em->persist(ConsolidationMembershipBuilder::aMembership()->forCompany((string) $this->head->getId())->withHead((string) $this->head->getId())->build());
        $this->em->persist(ConsolidationMembershipBuilder::aMembership()->forCompany((string) $this->member->getId())->withHead((string) $this->head->getId())->build());
        $this->em->flush();
        $sync = self::getContainer()->get(SyncCashflowGroupChartAction::class);
        $this->em->wrapInTransaction(fn () => $sync((string) $this->member->getId()));
        $this->em->clear();
    }

    public function testConsolidatesByChartWithIntragroupAndUnmatched(): void
    {
        $warehouse = $this->addCategory($this->member, 'Склад', 'OP_OUT_RENT');
        $own = $this->addCategory($this->member, 'Своё', null);
        $this->transaction($this->head, 'OP_IN_CUSTOMERS', CashDirection::INFLOW, '1000.00', self::MEMBER_INN);
        $this->transaction($this->member, 'OP_OUT_SUPPLIERS', CashDirection::OUTFLOW, '1000.00', self::HEAD_INN);
        $this->transaction($this->member, $warehouse, CashDirection::OUTFLOW, '300.00', '5000000000');
        $this->transaction($this->member, $own, CashDirection::OUTFLOW, '50.00', null);
        $this->transaction($this->member, 'OP_IN_CUSTOMERS', CashDirection::INFLOW, '70.00', null, 'USD');
        $this->transaction($this->member, 'OP_IN_CUSTOMERS', CashDirection::INFLOW, '500.00', null, 'RUB', '2026-10-31');

        $report = $this->report($this->member);

        $h = (string) $this->head->getId();
        $m = (string) $this->member->getId();
        self::assertSame([$h => 'Головная', $m => 'Дочерняя'], $report->companies, 'головная первой');
        $this->assertLine($report, 'OP_IN_CUSTOMERS', [$h => '1000.00', $m => '0.00'], '-1000.00', '0.00');
        $this->assertLine($report, 'OP_OUT_SUPPLIERS', [$h => '0.00', $m => '-1000.00'], '1000.00', '0.00');
        $this->assertLine($report, 'OP_OUT_RENT', [$h => '0.00', $m => '-300.00'], '0.00', '-300.00');
        $this->assertLine($report, 'OP_OUT', [$h => '0.00', $m => '-1300.00'], '1000.00', '-300.00');
        self::assertSame([$h => '0.00', $m => '-50.00'], $report->unmatched->amounts, '«Своё» без кода — «Не сопоставлено»');
        self::assertSame('-50.00', $report->unmatched->total);
        self::assertSame([$h => '1000.00', $m => '-1350.00'], $report->totals->amounts);
        self::assertSame('0.00', $report->totals->elimination);
        self::assertSame('-350.00', $report->totals->total);
        self::assertSame(['USD' => [$h => '0.00', $m => '70.00']], $report->otherCurrencies);
        self::assertSame([], $report->companiesWithoutInn);
    }

    public function testOneSidedIntragroupPaymentStaysInTheGroupTotal(): void
    {
        $this->transaction($this->head, 'OP_OUT_SUPPLIERS', CashDirection::OUTFLOW, '90.01', self::MEMBER_INN);
        $this->transaction($this->member, 'OP_IN_CUSTOMERS', CashDirection::INFLOW, '90.00', self::HEAD_INN);
        $report = $this->report($this->head);
        self::assertSame('-0.01', $report->totals->total);
        self::assertSame('0.00', $report->totals->elimination);
        self::assertTrue($this->line($report, 'OP_OUT')->hasUnpaired);
        self::assertSame('-0.01', $report->reconciliation->unpairedNet);
        self::assertCount(2, $report->reconciliation->unpaired);
        self::assertTrue($report->reconciliation->balanced);
    }

    public function testUncodedSubcategoryOfHeadDoesNotDoubleParent(): void
    {
        $this->addCategory($this->head, 'Подстатья без кода', 'OP_OUT_RENT');
        $this->transaction($this->head, 'OP_OUT_RENT', CashDirection::OUTFLOW, '100.00', null);

        $report = $this->report($this->head);

        $h = (string) $this->head->getId();
        self::assertSame('-100.00', $this->line($report, 'OP_OUT_RENT')->amounts[$h]);
        self::assertSame('-100.00', $this->line($report, 'OP_OUT')->amounts[$h]);
        self::assertSame('-100.00', $report->totals->amounts[$h]);
    }

    public function testSharedInnStillMarksBothSidesIntragroup(): void
    {
        $this->db()->executeStatement('UPDATE companies SET inn = ? WHERE id = ?', [self::HEAD_INN, $this->member->getId()]);
        $this->transaction($this->head, 'OP_IN_CUSTOMERS', CashDirection::INFLOW, '100.00', self::HEAD_INN);
        $this->transaction($this->member, 'OP_OUT_SUPPLIERS', CashDirection::OUTFLOW, '100.00', self::HEAD_INN);

        $report = $this->report($this->head);

        self::assertSame('0.00', $report->totals->elimination);
        self::assertSame('0.00', $report->totals->total, 'обе встречные операции исключены');
        self::assertSame('-100.00', $this->line($report, 'OP_IN_CUSTOMERS')->elimination);
        self::assertSame('100.00', $this->line($report, 'OP_OUT_SUPPLIERS')->elimination);
    }

    public function testCompanyColumnEqualsItsCashflowReport(): void
    {
        $this->transaction($this->member, 'OP_OUT_RENT', CashDirection::OUTFLOW, '120.45', null);
        $this->transaction($this->member, $this->addCategory($this->member, 'Своё', null), CashDirection::INFLOW, '10.10', null);
        $this->transaction($this->member, 'FIN_IN', CashDirection::INFLOW, '999.99', self::HEAD_INN);

        $report = $this->report($this->member);

        $rows = self::getContainer()->get(CashFacade::class)->listCashflowReportRows((string) $this->member->getId(), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), new CashflowReportRowFilter(currency: 'RUB'));
        $expected = '0.00';
        foreach ($rows as $row) {
            if (null !== $row->categoryId && null !== $row->amount) {
                $expected = bcadd($expected, CashDirection::OUTFLOW === $row->direction ? bcmul($row->amount, '-1', 2) : $row->amount, 2);
            }
        }
        self::assertSame('889.64', $expected);
        self::assertSame($expected, $report->totals->amounts[(string) $this->member->getId()]);
    }

    public function testReportUsesCalendarDatesWithTimeInTheRequest(): void
    {
        $this->transaction($this->head, 'OP_OUT_OTHER', CashDirection::OUTFLOW, '10.00', self::MEMBER_INN, date: '2026-11-01');
        $this->transaction($this->member, 'OP_IN_OTHER', CashDirection::INFLOW, '10.00', self::HEAD_INN, date: '2026-11-02');
        $report = self::getContainer()->get(BuildCashflowGroupReport::class)((string) $this->head->getId(), new \DateTimeImmutable('2026-11-01 12:00'), new \DateTimeImmutable('2026-11-02 12:00'));
        self::assertNotNull($report);
        self::assertCount(1, $report->reconciliation->pairs);
        self::assertSame([], $report->reconciliation->inTransit);
    }

    public function testWarnsAboutCompaniesWithoutInn(): void
    {
        $this->db()->executeStatement('UPDATE companies SET inn = NULL WHERE id = ?', [$this->member->getId()]);

        self::assertSame(['Дочерняя'], $this->report($this->head)->companiesWithoutInn);
    }

    public function testCompanyOutsideGroupHasNoReport(): void
    {
        self::assertNull(self::getContainer()->get(BuildCashflowGroupReport::class)((string) $this->company('Одиночка', null)->getId(), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30')));
    }

    /** @param array<string, string> $amounts */
    private function assertLine(CashflowGroupReport $report, string $key, array $amounts, string $elimination, string $total): void
    {
        $line = $this->line($report, $key);
        self::assertSame($amounts, $line->amounts, $key);
        self::assertSame($elimination, $line->elimination, $key);
        self::assertSame($total, $line->total, $key);
    }

    private function line(CashflowGroupReport $report, string $key): CashflowGroupReportLine
    {
        foreach ($report->lines as $line) {
            if ($line->key === $key) {
                return $line;
            }
        }
        self::fail('Нет строки '.$key);
    }

    private function report(Company $company): CashflowGroupReport
    {
        $report = self::getContainer()->get(BuildCashflowGroupReport::class)((string) $company->getId(), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'));
        self::assertNotNull($report);

        return $report;
    }

    private function company(string $name, ?string $inn): Company
    {
        $owner = $this->em->find(User::class, $this->owner->getId());
        \assert($owner instanceof User);
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withName($name)->withOwner($owner)->build();
        $company->setInn($inn);
        $this->em->persist($company);
        $this->em->flush();
        self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class)((string) $company->getId());
        $this->em->flush();

        return $company;
    }

    /** Статья без кода под статьёй с кодом $parentCode или под «Операционной деятельностью». */
    private function addCategory(Company $company, string $name, ?string $parentCode): string
    {
        $company = $this->em->find(Company::class, $company->getId());
        \assert($company instanceof Company);
        $repo = $this->em->getRepository(CashflowCategory::class);
        $parent = null === $parentCode
            ? $repo->findOneBy(['company' => $company, 'systemCode' => CashflowCategory::CODE_OPERATING])
            : $repo->findOneBy(['company' => $company, 'groupCode' => $parentCode]);
        \assert($parent instanceof CashflowCategory);
        $category = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName($name)->setParent($parent);
        $category->syncFlowKindWithParent();
        $this->em->persist($category);
        $this->em->flush();

        return (string) $category->getId();
    }

    /** $category — код эталона или id статьи. */
    private function transaction(Company $company, string $category, CashDirection $direction, string $amount, ?string $counterpartyInn, string $currency = 'RUB', string $date = '2026-11-10'): void
    {
        $company = $this->em->find(Company::class, $company->getId());
        \assert($company instanceof Company);
        $repo = $this->em->getRepository(CashflowCategory::class);
        $entity = Uuid::isValid($category) ? $repo->find($category) : $repo->findOneBy(['company' => $company, 'groupCode' => $category]);
        \assert($entity instanceof CashflowCategory);
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->withName('Счёт '.Uuid::uuid4()->toString())->withCurrency($currency)->forCompany($company)->build();
        $tx = CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid4()->toString())->forCompany($company)->withMoneyAccount($account)
            ->withCashflowCategory($entity)->withDirection($direction)->withAmount($amount)->withCurrency($currency)->build();
        $tx->setOccurredAt(new \DateTimeImmutable($date));
        if (null !== $counterpartyInn) {
            $counterparty = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName('Контрагент '.$counterpartyInn)->withInn($counterpartyInn)->build();
            $this->em->persist($counterparty);
            $tx->setCounterparty($counterparty);
        }
        $this->em->persist($account);
        $this->em->persist($tx);
        $this->em->flush();
        $this->em->clear();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }
}
