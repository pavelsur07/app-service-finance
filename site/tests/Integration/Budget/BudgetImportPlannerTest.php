<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\DTO\BudgetPlanFile;
use App\Budget\Application\DTO\BudgetPlanFileRow;
use App\Budget\Application\Service\BudgetImportPlanner;
use App\Budget\Application\View\BudgetImportPlan;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetLineSource;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Exception\InvalidBudgetPairException;
use App\Tests\Builders\Budget\BudgetLineBuilder;
use App\Tests\Builders\Budget\BudgetVersionBuilder;

/**
 * Загрузка плана из файла: строка по ID или коду, знак расхода по правилу ввода, пустое = 0,
 * sales_plan и комментарии не трогаются, итоги и неизвестные строки пропускаются.
 */
final class BudgetImportPlannerTest extends BudgetIntegrationTestCase
{
    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000fa001';
    private const APPROVED_ID = '0193cccc-0000-7000-8000-0000000fa002';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $version = BudgetVersionBuilder::aVersion()->withId(self::VERSION_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->build();
        $approved = BudgetVersionBuilder::aVersion()->withId(self::APPROVED_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->withStatus(BudgetVersionStatus::APPROVED)->build();
        $this->em->persist($version);
        $this->em->persist($approved);
        $this->line($version, self::REVENUE_ID, '2027-01', '1000.00');
        $this->line($version, self::RENT_ID, '2027-02', '-300.00', comment: 'аренда офиса');
        $this->line($version, self::REVENUE_ID, '2027-05', '7777.00', source: BudgetLineSource::SALES_PLAN);
        $this->em->flush();
        $this->em->clear();
    }

    public function testMatchesByIdOrCodeSignsExpenseAndCountsChanges(): void
    {
        $plan = $this->plan(new BudgetPlanFile(2027, self::GENERAL_PROJECT_ID, $this->generalCenterId, [
            $this->row(7, self::RENT_ID, null, 'Аренда', [2 => '300.00', 3 => '150.50']),
            $this->row(8, null, 'REV', 'Выручка', [1 => '1000.00', 2 => '2000.00', 5 => '1.00']),
        ]));

        self::assertSame([
            [self::RENT_ID, '2027-03', '-150.50'],
            [self::REVENUE_ID, '2027-02', '2000.00'],
        ], $this->cells($plan));
        self::assertSame([2, 0, 1], [$plan->fill->changed, $plan->fill->overwrite, $plan->fill->skipped], 'февраль аренды не меняется, май выручки — план продаж');
        self::assertSame([], $plan->warnings);
        self::assertSame([], $plan->ignored);
    }

    public function testEmptyCellClearsAndKeepsComment(): void
    {
        $plan = $this->plan(new BudgetPlanFile(2027, self::GENERAL_PROJECT_ID, $this->generalCenterId, [
            $this->row(7, self::RENT_ID, 'RENT', 'Аренда', []),
        ]));

        self::assertSame([[self::RENT_ID, '2027-02', '0.00']], $this->cells($plan));
        self::assertSame('аренда офиса', $plan->fill->cells[0]->comment);
        self::assertSame(1, $plan->fill->overwrite);
    }

    public function testIgnoresTotalsAndUnknownRowsAndWarnsAboutOtherPairAndYear(): void
    {
        $plan = $this->plan(new BudgetPlanFile(2026, self::SHOP_PROJECT_ID, $this->salesCenterId, [
            $this->row(7, self::SUBTOTAL_ID, 'PROFIT', 'Прибыль', [1 => '5.00']),
            $this->row(8, self::OTHER_COMPANY_LEAF_ID, null, 'Чужая', [1 => '5.00']),
            $this->row(9, null, 'NOPE', 'Неизвестная', [1 => '5.00']),
            $this->row(10, '0193cccc-0000-7000-8000-0000000fa0de', 'RENT', 'Удалённая', [1 => '5.00']),
            $this->row(11, self::SUBTOTAL_ID, 'PROFIT', 'KPI с дробью', [1 => null]),
        ]));

        self::assertSame([], $plan->fill->cells);
        self::assertSame(['строка 8 «Чужая» — статья не найдена', 'строка 9 «Неизвестная» — статья не найдена', 'строка 10 «Удалённая» — статья не найдена'], $plan->ignored, 'неизвестный ID не ищется по коду');
        self::assertCount(3, $plan->warnings);
        self::assertStringContainsString('2026', $plan->warnings[0]);
        self::assertStringContainsString('другой пары', $plan->warnings[1]);
        self::assertStringContainsString('Итоги и показатели', $plan->warnings[2]);
    }

    public function testRejectsNonAmountInLoadedRowWithCellReference(): void
    {
        $this->expectException(InvalidBudgetInputException::class);
        $this->expectExceptionMessage('строка 7, Февраль — не число');
        $this->plan(new BudgetPlanFile(2027, self::GENERAL_PROJECT_ID, $this->generalCenterId, [
            $this->row(7, self::RENT_ID, null, 'Аренда', [2 => null]),
        ]));
    }

    public function testRejectsDuplicateCategoryMissingPairAndApprovedVersion(): void
    {
        try {
            $this->plan(new BudgetPlanFile(null, null, null, [
                $this->row(7, self::RENT_ID, null, 'Аренда', []),
                $this->row(9, null, 'RENT', 'Аренда', []),
            ]));
            self::fail('ожидался отказ на дубль');
        } catch (InvalidBudgetInputException $e) {
            self::assertStringContainsString('строки 7 и 9', $e->getMessage());
        }

        try {
            $this->planner()->plan($this->version(self::VERSION_ID), '', '', new BudgetPlanFile(null, null, null, []));
            self::fail('ожидался отказ без пары');
        } catch (InvalidBudgetPairException) {
        }

        $this->expectException(BudgetVersionNotEditableException::class);
        $this->planner()->plan($this->version(self::APPROVED_ID), self::GENERAL_PROJECT_ID, $this->generalCenterId, new BudgetPlanFile(null, null, null, []));
    }

    private function plan(BudgetPlanFile $file): BudgetImportPlan
    {
        return $this->planner()->plan($this->version(self::VERSION_ID), self::GENERAL_PROJECT_ID, $this->generalCenterId, $file);
    }

    /**
     * @param array<int, ?string> $amounts месяц 1–12 → сумма (null — не сумма); остальные — 0
     */
    private function row(int $line, ?string $id, ?string $code, string $name, array $amounts): BudgetPlanFileRow
    {
        $full = [];
        for ($month = 1; $month <= 12; ++$month) {
            $full[$month] = \array_key_exists($month, $amounts) ? $amounts[$month] : '0.00';
        }

        return new BudgetPlanFileRow($line, $id, $code, $name, $full);
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function cells(BudgetImportPlan $plan): array
    {
        return array_map(static fn ($c): array => [$c->plCategoryId, $c->month, $c->amount], $plan->fill->cells);
    }

    private function planner(): BudgetImportPlanner
    {
        return self::getContainer()->get(BudgetImportPlanner::class);
    }

    private function version(string $id): BudgetVersion
    {
        $version = $this->em->find(BudgetVersion::class, $id);
        self::assertNotNull($version);

        return $version;
    }

    private function line(BudgetVersion $version, string $categoryId, string $month, string $amount, ?string $comment = null, BudgetLineSource $source = BudgetLineSource::MANUAL): void
    {
        $this->em->persist(BudgetLineBuilder::aLine()->withId(sprintf('0193cccc-0000-7000-8000-0000000fa%03d', 100 + ++$this->n))->inVersion($version)->forCategory($categoryId)->forMonth($month)->forPair(self::GENERAL_PROJECT_ID, $this->generalCenterId)->withAmount($amount)->withComment($comment)->withSource($source)->build());
    }
}
