<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\Service\BudgetGridBuilder;
use App\Budget\Application\View\BudgetGridRow;
use App\Budget\Application\View\BudgetGridView;
use App\Budget\Entity\BudgetVersion;
use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use App\Tests\Builders\Budget\BudgetLineBuilder;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;

final class BudgetGridBuilderTest extends BudgetIntegrationTestCase
{
    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000f4001';
    private const NO_CODE_ID = '0193cccc-0000-7000-8000-00000000d004';
    private const HIDDEN_ID = '0193cccc-0000-7000-8000-00000000d005';
    private const RATIO_ID = '0193cccc-0000-7000-8000-00000000d006';

    private BudgetVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->version = BudgetVersionBuilder::aVersion()->withId(self::VERSION_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->build();
        $this->em->persist($this->version);
        $this->line(1, self::REVENUE_ID, '2027-01', '1000.00', self::GENERAL_PROJECT_ID, $this->generalCenterId, 'план');
        $this->line(2, self::REVENUE_ID, '2027-01', '500.00', self::SHOP_PROJECT_ID, $this->salesCenterId);
        $this->line(3, self::RENT_ID, '2027-02', '-300.00', self::GENERAL_PROJECT_ID, $this->generalCenterId);
        $this->em->flush();
        $this->em->clear();
    }

    public function testGridOfPairHasLeafAmountsSubtotalsTotalsAndComments(): void
    {
        $grid = $this->build(self::GENERAL_PROJECT_ID, $this->generalCenterId);

        self::assertSame(2027, $grid->year);
        self::assertSame(['2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06', '2027-07', '2027-08', '2027-09', '2027-10', '2027-11', '2027-12'], $grid->months);
        self::assertSame([self::SUBTOTAL_ID, self::REVENUE_ID, self::RENT_ID], array_map(static fn (BudgetGridRow $row): string => $row->id, $grid->rows));

        [$profit, $revenue, $rent] = $grid->rows;
        self::assertFalse($profit->editable);
        self::assertTrue($revenue->editable);
        self::assertSame('income', $revenue->flow);
        self::assertSame('expense', $rent->flow);
        self::assertSame(2, $revenue->level);

        self::assertSame('1000.00', $revenue->values['2027-01']);
        self::assertSame('0.00', $revenue->values['2027-02']);
        self::assertSame('1000.00', $revenue->total);
        self::assertSame('-300.00', $rent->values['2027-02']);
        self::assertSame('1000.00', $profit->values['2027-01']);
        self::assertSame('-300.00', $profit->values['2027-02']);
        self::assertSame('700.00', $profit->total);
        self::assertSame(['2027-01' => 'план'], $revenue->comments);
        self::assertSame([], $grid->warnings);
    }

    public function testGridWithoutPairSumsAllPairsWithoutComments(): void
    {
        $grid = $this->build(null, null);

        $revenue = $this->row($grid, self::REVENUE_ID);
        self::assertSame('1500.00', $revenue->values['2027-01']);
        self::assertSame([], $revenue->comments);
        self::assertSame('1200.00', $this->row($grid, self::SUBTOTAL_ID)->total);
    }

    public function testLeafWithoutCodeIsShownButLeftOutOfSubtotalsAndHiddenRowIsSkipped(): void
    {
        $company = $this->em->find(Company::class, self::COMPANY_ID);
        self::assertNotNull($company);
        $subtotal = $this->em->find(PLCategory::class, self::SUBTOTAL_ID);
        $noCode = PLCategoryBuilder::aPLCategory()->withId(self::NO_CODE_ID)->forCompany($company)->withName('Прочее')->withParent($subtotal)->build();
        $noCode->setSortOrder(2);
        $hidden = PLCategoryBuilder::aPLCategory()->withId(self::HIDDEN_ID)->forCompany($company)->withName('Скрытая')->withCode('HIDDEN')->withParent($subtotal)->build();
        $hidden->setSortOrder(3);
        $hidden->setIsVisible(false);
        $this->em->persist($noCode);
        $this->em->persist($hidden);
        $this->em->flush();
        $this->em->clear();
        $this->line(4, self::NO_CODE_ID, '2027-03', '-50.00', self::GENERAL_PROJECT_ID, $this->generalCenterId);
        $this->em->flush();

        $grid = $this->build(self::GENERAL_PROJECT_ID, $this->generalCenterId);

        self::assertSame('-50.00', $this->row($grid, self::NO_CODE_ID)->values['2027-03']);
        self::assertSame('0.00', $this->row($grid, self::SUBTOTAL_ID)->values['2027-03']);
        self::assertNotContains(self::HIDDEN_ID, array_map(static fn (BudgetGridRow $row): string => $row->id, $grid->rows));
        self::assertCount(1, $grid->warnings);
        self::assertStringContainsString('Прочее', $grid->warnings[0]);
    }

    public function testRowsCarryValueFormatAndKpiKeepsItsPrecision(): void
    {
        $company = $this->em->find(Company::class, self::COMPANY_ID);
        self::assertNotNull($company);
        $ratio = PLCategoryBuilder::aPLCategory()->withId(self::RATIO_ID)->forCompany($company)->withName('Доля')->withCode('SHARE')->withType(PLCategoryType::KPI)->build();
        $ratio->setFormula('REV / 3');
        $ratio->setFormat(PLValueFormat::RATIO);
        $ratio->setSortOrder(5);
        $this->em->persist($ratio);
        $this->em->flush();
        $this->em->clear();

        $grid = $this->build(self::GENERAL_PROJECT_ID, $this->generalCenterId);

        self::assertSame('MONEY', $this->row($grid, self::REVENUE_ID)->format);
        self::assertSame('MONEY', $this->row($grid, self::SUBTOTAL_ID)->format);
        self::assertSame('1000.00', $this->row($grid, self::SUBTOTAL_ID)->values['2027-01']);
        self::assertSame('RATIO', $this->row($grid, self::RATIO_ID)->format);
        self::assertSame('333.3333', $this->row($grid, self::RATIO_ID)->values['2027-01']);
    }

    private function build(?string $projectId, ?string $centerId): BudgetGridView
    {
        $version = $this->em->find(BudgetVersion::class, self::VERSION_ID);
        self::assertNotNull($version);

        return self::getContainer()->get(BudgetGridBuilder::class)->build($version, $projectId, $centerId);
    }

    private function row(BudgetGridView $grid, string $id): BudgetGridRow
    {
        foreach ($grid->rows as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }
        self::fail('row not found: '.$id);
    }

    private function line(int $n, string $categoryId, string $month, string $amount, string $projectId, string $centerId, ?string $comment = null): void
    {
        $version = $this->em->find(BudgetVersion::class, self::VERSION_ID) ?? $this->version;
        $this->em->persist(BudgetLineBuilder::aLine()->withId(sprintf('0193cccc-0000-7000-8000-0000000f5%03d', $n))->inVersion($version)->forCategory($categoryId)->forMonth($month)->forPair($projectId, $centerId)->withAmount($amount)->withComment($comment)->build());
    }
}
