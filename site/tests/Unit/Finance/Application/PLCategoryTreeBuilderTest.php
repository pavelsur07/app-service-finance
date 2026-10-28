<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Application;

use App\Company\Entity\Company;
use App\Finance\Application\Service\PLCategoryTreeBuilder;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use PHPUnit\Framework\TestCase;

final class PLCategoryTreeBuilderTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = CompanyBuilder::aCompany()->build();
    }

    public function testNestsFourGroupsAndALeafAndOnlyLeavesCarryFlow(): void
    {
        $l1 = $this->group('Расходы');
        $l2 = $this->group('Управленческие расходы', $l1);
        $l3 = $this->group('Производство', $l2);
        $l4 = $this->group('Цех № 1', $l3);
        $leaf = $this->category('Электроэнергия цеха', PLFlow::EXPENSE, $l4);

        $tree = (new PLCategoryTreeBuilder())->build([$l1, $l2, $l3, $l4, $leaf]);

        self::assertSame(5, $leaf->getLevel());
        self::assertSame([[
            'id' => $l1->getId(),
            'name' => 'Расходы',
            'children' => [[
                'id' => $l2->getId(),
                'name' => 'Управленческие расходы',
                'children' => [[
                    'id' => $l3->getId(),
                    'name' => 'Производство',
                    'children' => [[
                        'id' => $l4->getId(),
                        'name' => 'Цех № 1',
                        'children' => [['id' => $leaf->getId(), 'name' => 'Электроэнергия цеха', 'flow' => 'out']],
                    ]],
                ]],
            ]],
        ]], $tree);
    }

    public function testLeafFlowIsItsOwnPlFlowNotTheParents(): void
    {
        $revenue = $this->group('Выручка');
        $sales = $this->category('Продажи', PLFlow::INCOME, $revenue);
        $returns = $this->category('Возвраты', PLFlow::EXPENSE, $revenue);
        $other = $this->category('Прочие доходы и расходы', PLFlow::NONE, $revenue);

        $tree = (new PLCategoryTreeBuilder())->build([$revenue, $sales, $returns, $other]);

        self::assertSame(
            ['in', 'out', 'both'],
            array_map(static fn (array $n): ?string => $n['flow'] ?? null, $tree[0]['children'] ?? []),
        );
    }

    public function testKeepsTheGivenOrder(): void
    {
        $b = $this->category('Б', PLFlow::EXPENSE);
        $a = $this->category('А', PLFlow::EXPENSE);

        $tree = (new PLCategoryTreeBuilder())->build([$b, $a]);

        self::assertSame(['Б', 'А'], array_column($tree, 'name'));
    }

    public function testCategoryWithoutChildrenIsALeafAndOrphanGoesToTheTop(): void
    {
        $emptyGroup = $this->group('Пустая');
        $hiddenParent = $this->group('Не в списке');
        $orphan = $this->category('Сирота', PLFlow::INCOME, $hiddenParent);

        $tree = (new PLCategoryTreeBuilder())->build([$emptyGroup, $orphan]);

        self::assertSame(
            [
                ['id' => $emptyGroup->getId(), 'name' => 'Пустая', 'flow' => 'both'],
                ['id' => $orphan->getId(), 'name' => 'Сирота', 'flow' => 'in'],
            ],
            $tree,
        );
    }

    public function testNamesStayRawTextForTheWidgetToEscape(): void
    {
        $category = $this->category('<script>alert(1)</script> & Ко', PLFlow::EXPENSE);

        $tree = (new PLCategoryTreeBuilder())->build([$category]);

        self::assertSame('<script>alert(1)</script> & Ко', $tree[0]['name']);
    }

    private function group(string $name, ?PLCategory $parent = null): PLCategory
    {
        return PLCategoryBuilder::aPLCategory()
            ->forCompany($this->company)
            ->withName($name)
            ->withFlow(PLFlow::NONE)
            ->withType(PLCategoryType::SUBTOTAL)
            ->withParent($parent)
            ->build();
    }

    private function category(string $name, PLFlow $flow, ?PLCategory $parent = null): PLCategory
    {
        return PLCategoryBuilder::aPLCategory()
            ->forCompany($this->company)
            ->withName($name)
            ->withFlow($flow)
            ->withParent($parent)
            ->build();
    }
}
