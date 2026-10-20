<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Report;

use App\Company\Entity\Company;
use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\Application\DTO\PlPreviewResult;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PlPreviewUnplacedReason;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlPreviewCalculator;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Repository\PLCategoryRepository;
use App\Tests\Unit\Finance\Report\Fixtures\MiniTreeFactory;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PlPreviewCalculatorTest extends TestCase
{
    private Company $company;
    /** @var array<string, PLCategory> */
    private array $byCode = [];
    private PLCategory $leafWithoutCode;
    private PlPreviewCalculator $calculator;

    protected function setUp(): void
    {
        $this->company = $this->createMock(Company::class);

        $tree = MiniTreeFactory::build($this->company);
        foreach ($tree as $category) {
            $this->byCode[(string) $category->getCode()] = $category;
        }
        $this->byCode['REV_WB']->setFlow(PLFlow::INCOME);
        $this->byCode['COGS']->setFlow(PLFlow::EXPENSE);
        // Как в базовом дереве (config/finance/pl_templates/marketplace.json): факт расхода отрицательный,
        // маржа — сумма, а не разность.
        $this->byCode['MARGIN']->setFormula('REV_TOTAL + VAR_COSTS_TOTAL');

        $this->leafWithoutCode = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $this->leafWithoutCode->setName('Лист без кода');
        $this->leafWithoutCode->setType(PLCategoryType::LEAF_INPUT);
        $this->leafWithoutCode->setParent($this->byCode['VAR_COSTS_TOTAL']);
        $tree[] = $this->leafWithoutCode;

        $repo = $this->createMock(PLCategoryRepository::class);
        $repo->method('findBy')->willReturn($tree);

        // Регистр ОПиУ в предпросмотре не читается.
        $registerFacts = $this->createMock(FactsProviderInterface::class);
        $registerFacts->expects(self::never())->method('value');

        $this->calculator = new PlPreviewCalculator($repo, new PlReportCalculator($repo, $registerFacts));
    }

    public function testNatureFollowsMarketplaceDocumentConvention(): void
    {
        // Выручка приходит «+», затраты — начисление «−» и сторно «+», уже сложенные по статье.
        $result = $this->calculate([
            new PlPreviewColumn('total', [
                $this->id('REV_WB') => '1000.00',
                $this->id('COGS') => '-300.00',
            ]),
        ]);

        self::assertSame(1000.0, $this->value($result, 'REV_TOTAL', 'total'));
        self::assertSame(-300.0, $this->value($result, 'VAR_COSTS_TOTAL', 'total'));
        self::assertSame(700.0, $this->value($result, 'MARGIN', 'total'));
        self::assertEqualsWithDelta(0.7, $this->value($result, 'MARGIN_PCT', 'total'), 1e-9);
    }

    public function testExpenseStaysExpenseWhenStornoExceedsCharges(): void
    {
        // Регистр берёт модуль итога статьи: «+50» на статье расхода — всё равно расход 50.
        $result = $this->calculate([new PlPreviewColumn('total', [$this->id('COGS') => '50.00'])]);

        self::assertSame(-50.0, $this->value($result, 'COGS', 'total'));
    }

    public function testIncomeIsPositiveWhateverTheSign(): void
    {
        $result = $this->calculate([new PlPreviewColumn('total', [$this->id('REV_WB') => '-80.00'])]);

        self::assertSame(80.0, $this->value($result, 'REV_WB', 'total'));
    }

    public function testCategoryWithoutFlowKeepsTheSign(): void
    {
        $this->byCode['COGS']->setFlow(PLFlow::NONE);

        $result = $this->calculate([
            new PlPreviewColumn('a', [$this->id('COGS') => '-40.00']),
            new PlPreviewColumn('b', [$this->id('COGS') => '25.00']),
        ]);

        self::assertSame(-40.0, $this->value($result, 'COGS', 'a'));
        self::assertSame(25.0, $this->value($result, 'COGS', 'b'));
    }

    public function testColumnsAreIndependent(): void
    {
        $result = $this->calculate([
            new PlPreviewColumn('winter', [$this->id('REV_WB') => '600.00', $this->id('COGS') => '-100.00']),
            new PlPreviewColumn('summer', [$this->id('REV_WB') => '400.00']),
            new PlPreviewColumn('empty', []),
        ]);

        self::assertSame(500.0, $this->value($result, 'MARGIN', 'winter'));
        self::assertSame(400.0, $this->value($result, 'MARGIN', 'summer'));
        self::assertSame(0.0, $this->value($result, 'MARGIN', 'empty'));
        self::assertCount(7, $result->rows, 'Дерево компании целиком, по строке на статью.');
        self::assertSame(['winter', 'summer', 'empty'], array_keys($result->rows[0]->values));
    }

    public function testAmountsThatWouldNotReachTheReportAreReturnedSeparately(): void
    {
        $unknownId = Uuid::uuid4()->toString();

        $result = $this->calculate([
            new PlPreviewColumn('a', [
                $this->id('REV_TOTAL') => '10.00',
                $this->leafWithoutCode->getId() => '-20.00',
                $unknownId => '5.00',
                $this->id('REV_WB') => '100.00',
            ]),
            new PlPreviewColumn('b', [$unknownId => '7.50']),
        ]);

        $byId = [];
        foreach ($result->unplaced as $item) {
            $byId[$item->categoryId] = $item;
        }

        self::assertSame(PlPreviewUnplacedReason::NOT_LEAF_INPUT, $byId[$this->id('REV_TOTAL')]->reason);
        self::assertSame(['a' => '10.00'], $byId[$this->id('REV_TOTAL')]->values);
        self::assertSame(PlPreviewUnplacedReason::NO_CODE, $byId[$this->leafWithoutCode->getId()]->reason);
        self::assertSame('Лист без кода', $byId[$this->leafWithoutCode->getId()]->categoryName);
        self::assertSame(PlPreviewUnplacedReason::CATEGORY_NOT_FOUND, $byId[$unknownId]->reason);
        self::assertNull($byId[$unknownId]->categoryName);
        self::assertSame(['a' => '5.00', 'b' => '7.50'], $byId[$unknownId]->values);
        // Подытог не получил «свою» сумму поверх суммы потомков.
        self::assertSame(100.0, $this->value($result, 'REV_TOTAL', 'a'));
    }

    public function testShareColumnsFollowTheTotalAndAddUpToIt(): void
    {
        // Расход: итог −300, доли −400 и +100 (доля «Общих» с остатком в обратную сторону).
        $result = $this->calculator->calculate(
            $this->company,
            PlReportPeriod::forMonth(new \DateTimeImmutable('2026-01-01')),
            [
                new PlPreviewColumn('a', [$this->id('COGS') => '-400.00', $this->id('REV_WB') => '700.00']),
                new PlPreviewColumn('b', [$this->id('COGS') => '100.00', $this->id('REV_WB') => '-50.00']),
                new PlPreviewColumn('total', [$this->id('COGS') => '-300.00', $this->id('REV_WB') => '650.00']),
            ],
            'total',
        );

        self::assertSame(-300.0, $this->value($result, 'COGS', 'total'));
        self::assertSame(-400.0, $this->value($result, 'COGS', 'a'));
        self::assertSame(100.0, $this->value($result, 'COGS', 'b'));
        self::assertSame(-50.0, $this->value($result, 'REV_WB', 'b'));
        self::assertSame(
            $this->value($result, 'MARGIN', 'total'),
            $this->value($result, 'MARGIN', 'a') + $this->value($result, 'MARGIN', 'b'),
        );
    }

    public function testStornoDominatedTotalStaysExpenseAndSharesStayLinear(): void
    {
        $result = $this->calculator->calculate(
            $this->company,
            PlReportPeriod::forMonth(new \DateTimeImmutable('2026-01-01')),
            [
                new PlPreviewColumn('a', [$this->id('COGS') => '80.00']),
                new PlPreviewColumn('b', [$this->id('COGS') => '-30.00']),
                new PlPreviewColumn('total', [$this->id('COGS') => '50.00']),
            ],
            'total',
        );

        // Регистр: итог +50 на статье расхода — расход 50; доли: −80 и +30.
        self::assertSame(-50.0, $this->value($result, 'COGS', 'total'));
        self::assertSame(-80.0, $this->value($result, 'COGS', 'a'));
        self::assertSame(30.0, $this->value($result, 'COGS', 'b'));
    }

    public function testZeroOrAbsentTotalKeepsOffsettingSharesOpposite(): void
    {
        // Учёта нет, статистика 600 на теге: доля тега −600, остаток в «Общих» +600, итог 0.
        $result = $this->calculator->calculate(
            $this->company,
            PlReportPeriod::forMonth(new \DateTimeImmutable('2026-01-01')),
            [
                new PlPreviewColumn('tag', [$this->id('COGS') => '-600.00', $this->id('REV_WB') => '40.00']),
                new PlPreviewColumn('common', [$this->id('COGS') => '600.00']),
                new PlPreviewColumn('total', [$this->id('COGS') => '0.00']),
            ],
            'total',
        );

        self::assertSame(-600.0, $this->value($result, 'COGS', 'tag'));
        self::assertSame(600.0, $this->value($result, 'COGS', 'common'));
        self::assertSame(0.0, $this->value($result, 'COGS', 'total'));
        // Статьи нет в итоге вовсе — доля тоже со знаком документа.
        self::assertSame(40.0, $this->value($result, 'REV_WB', 'tag'));
    }

    public function testUnknownBaseColumnIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate(
            $this->company,
            PlReportPeriod::forMonth(new \DateTimeImmutable('2026-01-01')),
            [new PlPreviewColumn('a', [])],
            'total',
        );
    }

    public function testRequiresAtLeastOneColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculate([]);
    }

    public function testRejectsDuplicateColumnKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculate([new PlPreviewColumn('a', []), new PlPreviewColumn('a', [])]);
    }

    /**
     * @param list<PlPreviewColumn> $columns
     */
    private function calculate(array $columns): PlPreviewResult
    {
        return $this->calculator->calculate(
            $this->company,
            PlReportPeriod::forMonth(new \DateTimeImmutable('2026-01-01')),
            $columns,
        );
    }

    private function id(string $code): string
    {
        return (string) $this->byCode[$code]->getId();
    }

    private function value(PlPreviewResult $result, string $code, string $column): float
    {
        foreach ($result->rows as $row) {
            if ($row->code === $code) {
                return $row->values[$column];
            }
        }

        self::fail(sprintf('Нет строки %s', $code));
    }
}
