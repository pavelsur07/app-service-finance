<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\Service\CashflowCategoryTreeBuilder;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;

final class CashflowCategoryTreeBuilderTest extends TestCase
{
    private Company $company;
    private int $index = 0;

    protected function setUp(): void
    {
        $this->company = CompanyBuilder::aCompany()->build();
    }

    public function testNestsFiveLevelsAndOnlyLeavesCarryFlow(): void
    {
        $l1 = $this->category('Выбытие');
        $l2 = $this->category('Заработная плата', $l1);
        $l3 = $this->category('Производство', $l2);
        $l4 = $this->category('Цех №1', $l3);
        $leaf = $this->category('Сварщики', $l4);

        $tree = (new CashflowCategoryTreeBuilder())->build([$l1, $l2, $l3, $l4, $leaf]);

        self::assertSame([[
            'id' => $l1->getId(),
            'name' => 'Выбытие',
            'children' => [[
                'id' => $l2->getId(),
                'name' => 'Заработная плата',
                'children' => [[
                    'id' => $l3->getId(),
                    'name' => 'Производство',
                    'children' => [[
                        'id' => $l4->getId(),
                        'name' => 'Цех №1',
                        'children' => [['id' => $leaf->getId(), 'name' => 'Сварщики', 'flow' => 'both']],
                    ]],
                ]],
            ]],
        ]], $tree);
    }

    public function testFlowIsInheritedFromTheNearestOperationType(): void
    {
        $income = $this->category('Поступления', null, PaymentPlanType::INFLOW);
        $sales = $this->category('Продажи', $income);
        $refund = $this->category('Возврат поставщику', $income, PaymentPlanType::OUTFLOW);
        $transfer = $this->category('Перемещения', null, PaymentPlanType::TRANSFER);
        $loose = $this->category('Прочее');

        $tree = (new CashflowCategoryTreeBuilder())->build([$income, $sales, $refund, $transfer, $loose]);

        self::assertSame('in', $tree[0]['children'][0]['flow'] ?? null);
        self::assertSame('out', $tree[0]['children'][1]['flow'] ?? null);
        self::assertSame('both', $tree[1]['flow'] ?? null);
        self::assertSame('both', $tree[2]['flow'] ?? null);
    }

    public function testTechnicalInflowAndOutflowFollowTheirSystemCodes(): void
    {
        $technical = $this->category('Технические операции');
        $in = $this->category('Поступления', $technical);
        $out = $this->category('Выбытия', $technical);
        $technical->markAsSystem(CashflowCategory::CODE_TECHNICAL);
        $in->markAsSystem(CashflowCategory::CODE_TECHNICAL_IN);
        $out->markAsSystem(CashflowCategory::CODE_TECHNICAL_OUT);

        $tree = (new CashflowCategoryTreeBuilder())->build([$technical, $in, $out]);

        self::assertSame(['in', 'out'], array_map(static fn (array $n) => $n['flow'] ?? null, $tree[0]['children'] ?? []));
    }

    public function testCategoryWithoutChildrenIsALeafAndOrphanGoesToTheTop(): void
    {
        $emptyGroup = $this->category('Пустая');
        $hiddenParent = $this->category('Не в списке');
        $orphan = $this->category('Сирота', $hiddenParent);

        $tree = (new CashflowCategoryTreeBuilder())->build([$emptyGroup, $orphan]);

        self::assertSame(
            [
                ['id' => $emptyGroup->getId(), 'name' => 'Пустая', 'flow' => 'both'],
                ['id' => $orphan->getId(), 'name' => 'Сирота', 'flow' => 'both'],
            ],
            $tree,
        );
    }

    public function testNamesStayRawTextForTheWidgetToEscape(): void
    {
        $category = $this->category('<script>alert(1)</script> & Ко');

        $tree = (new CashflowCategoryTreeBuilder())->build([$category]);

        self::assertSame('<script>alert(1)</script> & Ко', $tree[0]['name']);
    }

    private function category(string $name, ?CashflowCategory $parent = null, ?PaymentPlanType $type = null): CashflowCategory
    {
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withIndex(++$this->index)
            ->withCompany($this->company)
            ->withName($name)
            ->build();
        $category->setParent($parent)->setOperationType($type);

        return $category;
    }
}
