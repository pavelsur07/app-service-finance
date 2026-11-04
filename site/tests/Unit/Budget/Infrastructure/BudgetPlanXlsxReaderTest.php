<?php

declare(strict_types=1);

namespace App\Tests\Unit\Budget\Infrastructure;

use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Infrastructure\Xlsx\BudgetPlanSheet;
use App\Budget\Infrastructure\Xlsx\BudgetPlanXlsxReader;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

final class BudgetPlanXlsxReaderTest extends TestCase
{
    private const string ID = '0193eeee-0000-7000-8000-00000000f013';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testReadsMetaRowsByIdAndCodeAndNormalizesNumbers(): void
    {
        $file = $this->xlsx('План', [
            ['Бюджет ОПиУ: Базовый, 2027'],
            [],
            [],
            [BudgetPlanSheet::META_LABEL, 'year=2027;project=p-1;center=c-1'],
            [],
            $this->header(),
            ['RENT', '    Аренда', 300, 300.5, '1 200,50', '−15', '', null, 0, 0, 0, 0, 0, 100, 1916, self::ID],
            ['REV', 'Выручка', 1000],
            ['', 'Итог', 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 60, ''],
        ]);

        $plan = (new BudgetPlanXlsxReader())->read($file);

        self::assertSame(2027, $plan->year);
        self::assertSame('p-1', $plan->projectDirectionId);
        self::assertSame('c-1', $plan->responsibilityCenterId);
        self::assertCount(3, $plan->rows);
        $rent = $plan->rows[0];
        self::assertSame(7, $rent->line);
        self::assertSame(self::ID, $rent->plCategoryId);
        self::assertSame('RENT', $rent->code);
        self::assertSame('Аренда', $rent->name);
        self::assertSame(['300.00', '300.50', '1200.50', '-15.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '100.00'], array_values($rent->amounts));
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], array_keys($rent->amounts));
        self::assertNull($plan->rows[1]->plCategoryId, 'без ID — ищется по коду');
        self::assertSame('1000.00', $plan->rows[1]->amounts[1]);
        self::assertSame('0.00', $plan->rows[1]->amounts[12]);
    }

    public function testSkipsBlankRowsAndWorksWithoutMeta(): void
    {
        $file = $this->xlsx('Лист1', [$this->header(), [], ['RENT', 'Аренда', 1]]);

        $plan = (new BudgetPlanXlsxReader())->read($file);

        self::assertNull($plan->year);
        self::assertNull($plan->projectDirectionId);
        self::assertCount(1, $plan->rows);
        self::assertSame(3, $plan->rows[0]->line);
    }

    public function testRejectsFileWithoutHeader(): void
    {
        $file = $this->xlsx('План', [['что-то'], ['ещё']]);

        $this->expectException(InvalidBudgetInputException::class);
        $this->expectExceptionMessage('ID статьи');
        (new BudgetPlanXlsxReader())->read($file);
    }

    public function testNonAmountsBecomeNullForPlannerToDecide(): void
    {
        $file = $this->xlsx('План', [$this->header(), ['RENT', 'Аренда', 'сто', 1.234, 333.3333]]);

        $plan = (new BudgetPlanXlsxReader())->read($file);

        self::assertSame([null, null, null, '0.00'], \array_slice(array_values($plan->rows[0]->amounts), 0, 4));
    }

    public function testRejectsNotXlsx(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'budget_reader_');
        $this->files[] = $file;
        file_put_contents($file, 'не xlsx');

        $this->expectException(InvalidBudgetInputException::class);
        (new BudgetPlanXlsxReader())->read($file);
    }

    /**
     * @return list<string>
     */
    private function header(): array
    {
        return ['Код', 'Статья', ...BudgetPlanSheet::MONTH_NAMES, 'Год', 'ID статьи'];
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function xlsx(string $sheetName, array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'budget_reader_').'.xlsx';
        $this->files[] = $file;
        $writer = new Writer();
        $writer->openToFile($file);
        $writer->getCurrentSheet()->setName($sheetName);
        foreach ($rows as $values) {
            $writer->addRow([] === $values ? new Row([new StringCell('', null)]) : Row::fromValues($values));
        }
        $writer->close();

        return $file;
    }
}
