<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\Category\ApplyCashflowCoreTemplateAction;
use App\Cash\Application\Category\EnsureCashflowSystemCategoriesAction;
use App\Cash\Application\Category\SyncCashflowGroupChartAction;
use App\Cash\Application\DTO\CashflowGroupChartSyncPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\ConsolidationMembershipBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/** Синхронизация дерева ДДС компании с эталоном группы (cashflow-group-chart, этап 2). */
final class SyncCashflowGroupChartActionTest extends IntegrationTestCase
{
    private const CORE_NODES = 37;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('sync-'.Uuid::uuid4()->toString().'@example.test')->build();
        $this->em->persist($this->owner);
        $this->em->flush();
    }

    public function testMemberWithEmptyTreeGetsHeadChart(): void
    {
        $head = $this->headWithCore();
        $extra = $this->addCategory($head, 'Маркетплейсы', 'Поступления');
        $member = $this->company();
        $this->group($head, $member);

        $plan = $this->sync($member);

        self::assertSame('head', $plan->source);
        self::assertSame(1, $plan->headCodes);
        self::assertCount(self::CORE_NODES + 1, $plan->create);
        self::assertSame($extra, $this->db()->fetchOne('SELECT group_code FROM cashflow_categories WHERE id = ?', [$extra]), 'статья головной без кода получает код = id');
        self::assertSame($this->codes($head), $this->codes($member));
        self::assertSame('Маркетплейсы', $this->db()->fetchOne('SELECT name FROM cashflow_categories WHERE company_id = ? AND group_code = ?', [$member->getId(), $extra]));
    }

    public function testBindsSameNameInBranchAndKeepsTransactions(): void
    {
        $head = $this->headWithCore();
        $member = $this->company();
        $outflows = $this->addCategory($member, 'выплаты', null, CashflowCategory::CODE_OPERATING);
        $rent = $this->addCategory($member, 'Аренда', null, null, $outflows);
        $this->transactionOn($member, $rent);
        $this->group($head, $member);

        $plan = $this->sync($member);

        self::assertContains('CF_OP/выплаты → OP_OUT', $plan->bind);
        self::assertSame('OP_OUT_RENT', $this->db()->fetchOne('SELECT group_code FROM cashflow_categories WHERE id = ?', [$rent]));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM cashflow_categories WHERE company_id = ? AND name = 'Аренда'", [$member->getId()]), 'вторая «Аренда» не создана');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE cashflow_category_id = ?', [$rent]));
        self::assertSame($this->codes($head), $this->codes($member));
    }

    public function testDuplicateNamesAreConflictAndChildrenSkipped(): void
    {
        $head = $this->headWithCore();
        $member = $this->company();
        $this->addCategory($member, 'Выплаты', null, CashflowCategory::CODE_OPERATING);
        $this->addCategory($member, 'Выплаты', null, CashflowCategory::CODE_OPERATING);
        $this->group($head, $member);

        $plan = $this->sync($member);

        self::assertNotEmpty(array_filter($plan->conflicts, static fn (string $c): bool => str_starts_with($c, 'OP_OUT ')));
        self::assertNotEmpty(array_filter($plan->conflicts, static fn (string $c): bool => str_starts_with($c, 'OP_OUT_RENT ')));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM cashflow_categories WHERE company_id = ? AND (group_code = 'OP_OUT' OR group_code LIKE 'OP_OUT\\_%')", [$member->getId()]));
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM cashflow_categories c JOIN cashflow_categories p ON p.id = c.parent_id WHERE c.company_id = ? AND c.name = 'Выплаты' AND p.system_code = 'CF_OP'", [$member->getId()]), 'третья «Выплаты» в операционной не создана');
        self::assertSame('OP_IN_CUSTOMERS', $this->db()->fetchOne("SELECT group_code FROM cashflow_categories WHERE company_id = ? AND name = 'Поступления от покупателей'", [$member->getId()]), 'другие ветки синхронизированы');
    }

    public function testRepeatIsEmptyAndJournalDoesNotGrow(): void
    {
        $head = $this->headWithCore();
        $member = $this->company();
        $this->group($head, $member);
        $this->sync($member);
        self::assertSame(1, $this->journalCount($member));

        $plan = $this->sync($member);

        self::assertTrue($plan->isEmpty());
        self::assertSame(1, $this->journalCount($member));
    }

    public function testTwoGroupsGetOwnCharts(): void
    {
        $headA = $this->headWithCore();
        $headB = $this->headWithCore();
        $onlyA = $this->addCategory($headA, 'Только группа A', 'Выплаты');
        $onlyB = $this->addCategory($headB, 'Только группа B', 'Выплаты');
        $memberA = $this->company();
        $memberB = $this->company();
        $this->group($headA, $memberA);
        $this->group($headB, $memberB);

        $this->sync($memberA);
        $this->sync($memberB);

        self::assertContains($onlyA, $this->codes($memberA));
        self::assertNotContains($onlyB, $this->codes($memberA));
        self::assertContains($onlyB, $this->codes($memberB));
        self::assertNotContains($onlyA, $this->codes($memberB));
    }

    public function testOutsiderAndHeadSyncWithCore(): void
    {
        $outsider = $this->company();
        $head = $this->headWithCore();
        $this->addCategory($head, 'Своя статья головной', 'Выплаты');
        $this->group($head);

        $outsiderPlan = $this->sync($outsider);
        $headPlan = $this->sync($head);

        self::assertSame('core', $outsiderPlan->source);
        self::assertCount(self::CORE_NODES, $this->codes($outsider));
        self::assertSame('core', $headPlan->source);
        self::assertTrue($headPlan->isEmpty(), 'головная на ядре — с core.yaml расхождений нет');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM cashflow_categories WHERE company_id = ? AND group_code IS NULL AND NOT is_system', [$head->getId()]), 'свою статью головной синхронизация с ядром не трогает');
    }

    public function testDryRunWritesNothing(): void
    {
        $head = $this->headWithCore();
        $extra = $this->addCategory($head, 'Маркетплейсы', 'Поступления');
        $member = $this->company();
        $this->group($head, $member);

        $plan = self::getContainer()->get(SyncCashflowGroupChartAction::class)->plan((string) $member->getId());

        self::assertCount(self::CORE_NODES + 1, $plan->create);
        self::assertSame(1, $plan->headCodes);
        self::assertSame([], $this->codes($member));
        self::assertNull($this->db()->fetchOne('SELECT group_code FROM cashflow_categories WHERE id = ?', [$extra]));
        self::assertSame(0, $this->journalCount($member));
    }

    private function sync(Company $company): CashflowGroupChartSyncPlan
    {
        $action = self::getContainer()->get(SyncCashflowGroupChartAction::class);
        /** @var CashflowGroupChartSyncPlan $plan */
        $plan = $this->em->wrapInTransaction(fn (): CashflowGroupChartSyncPlan => $action((string) $company->getId()));
        $this->em->clear();

        return $plan;
    }

    private function company(): Company
    {
        $owner = $this->em->find(User::class, $this->owner->getId());
        \assert($owner instanceof User);
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($company);
        $this->em->flush();
        self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class)((string) $company->getId());
        $this->em->flush();

        return $company;
    }

    private function headWithCore(): Company
    {
        $company = $this->company();
        self::getContainer()->get(ApplyCashflowCoreTemplateAction::class)((string) $company->getId());
        $this->em->flush();

        return $company;
    }

    /** Головная и участники; без участников — только строка головной. */
    private function group(Company $head, Company ...$members): void
    {
        $this->em->persist(ConsolidationMembershipBuilder::aMembership()->forCompany((string) $head->getId())->withHead((string) $head->getId())->build());
        foreach ($members as $member) {
            $this->em->persist(ConsolidationMembershipBuilder::aMembership()->forCompany((string) $member->getId())->withHead((string) $head->getId())->build());
        }
        $this->em->flush();
    }

    /**
     * Статья под группой ядра (по названию группы в CF_OP), системной секцией или своей статьёй.
     */
    private function addCategory(Company $company, string $name, ?string $coreGroup, ?string $systemCode = null, ?string $parentId = null): string
    {
        $company = $this->em->find(Company::class, $company->getId());
        \assert($company instanceof Company);
        $repo = $this->em->getRepository(CashflowCategory::class);
        if (null !== $parentId) {
            $parent = $repo->find($parentId);
        } elseif (null !== $systemCode) {
            $parent = $repo->findOneBy(['company' => $company, 'systemCode' => $systemCode]);
        } else {
            $parent = $repo->findOneBy(['company' => $company, 'groupCode' => 'Поступления' === $coreGroup ? 'OP_IN' : 'OP_OUT']);
        }
        \assert($parent instanceof CashflowCategory);
        $category = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName($name)->setParent($parent);
        $category->syncFlowKindWithParent();
        $this->em->persist($category);
        $this->em->flush();

        return (string) $category->getId();
    }

    private function transactionOn(Company $company, string $categoryId): void
    {
        $category = $this->em->find(CashflowCategory::class, $categoryId);
        \assert($category instanceof CashflowCategory);
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($company)->build();
        $this->em->persist($account);
        $this->em->persist(CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid4()->toString())->forCompany($company)->withMoneyAccount($account)->withCashflowCategory($category)->build());
        $this->em->flush();
    }

    /** @return list<string> */
    private function codes(Company $company): array
    {
        return $this->db()->fetchFirstColumn('SELECT group_code FROM cashflow_categories WHERE company_id = ? AND group_code IS NOT NULL ORDER BY group_code', [$company->getId()]);
    }

    private function journalCount(Company $company): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM cashflow_group_chart_applications WHERE company_id = ?', [$company->getId()]);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }
}
