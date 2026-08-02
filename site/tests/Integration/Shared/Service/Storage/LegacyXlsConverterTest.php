<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Service\Storage;

use App\Shared\Service\Storage\LegacyXlsConverter;
use App\Shared\Service\Storage\LegacyXlsTooLargeException;
use App\Shared\Service\Storage\LegacyXlsTooManyCellsException;
use App\Shared\Service\Storage\LegacyXlsUnreadableException;
use App\Shared\Service\Storage\TemporaryFileFactory;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;

/**
 * Тест работает на настоящем бинарном .xls, а не на подделке с нужным расширением:
 * дефект был именно в том, что формат принимался по имени, а прочитать его было нечем.
 */
final class LegacyXlsConverterTest extends TestCase
{
    /** @var list<string> */
    private array $garbage = [];

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->garbage as $path) {
            @unlink($path);
        }

        foreach ($this->directories as $dir) {
            @unlink($dir.'/bin/console');
            @rmdir($dir.'/bin');
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function testLegacyXlsBecomesReadableByOpenSpout(): void
    {
        $source = $this->writeXls([['Дата', 'Сумма'], ['2026-01-15', '1000.00']]);

        $rows = $this->converter()->withReadablePath($source, $this->readRows(...));

        self::assertSame([['Дата', 'Сумма'], ['2026-01-15', '1000.00']], $rows);
    }

    public function testNumbersDatesAndGapsSurviveConversion(): void
    {
        // Строковые ячейки ничего не доказывают: импорт существует ради чисел и дат,
        // и порча именно их прошла бы незамеченной.
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Аренда');
        $sheet->setCellValue('B1', 1234.56);
        $sheet->setCellValue('C1', 42);
        $sheet->setCellValue('D1', Date::PHPToExcel(new \DateTimeImmutable('2026-01-15')));
        $sheet->getStyle('D1')->getNumberFormat()->setFormatCode('YYYY-MM-DD');
        // B2 намеренно пустая: дыра в середине не должна сдвигать колонки.
        $sheet->setCellValue('A2', 'Реклама');
        $sheet->setCellValue('C2', 7);

        $source = $this->saveXls($spreadsheet);

        $rows = $this->converter()->withReadablePath($source, static function (string $path): array {
            $reader = new XlsxReader();
            $reader->open($path);
            $out = [];
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $out[] = $row->toArray();
                    }
                    break;
                }
            } finally {
                $reader->close();
            }

            return $out;
        });

        self::assertSame('Аренда', $rows[0][0]);
        self::assertEqualsWithDelta(1234.56, $rows[0][1], 0.001, 'Дробное число обязано пережить конвертацию.');
        self::assertSame(42, (int) $rows[0][2]);
        self::assertInstanceOf(\DateTimeInterface::class, $rows[0][3], 'Дата обязана остаться датой, а не числом Excel.');
        self::assertSame('2026-01-15', $rows[0][3]->format('Y-m-d'));

        self::assertSame('Реклама', $rows[1][0]);
        // Пустая ячейка приходит пустой строкой, а не null — важно, что она занимает
        // своё место и не сдвигает следующую колонку влево.
        self::assertSame('', $rows[1][1]);
        self::assertSame(7, (int) $rows[1][2], 'Колонка после пустой ячейки обязана остаться на месте.');
    }

    public function testOversizedSourceIsRejectedWithClearReason(): void
    {
        $source = $this->writeXls([['a']]);

        $converter = new LegacyXlsConverter(new TemporaryFileFactory(), self::projectDir(), 1);

        $this->expectException(LegacyXlsTooLargeException::class);
        $this->expectExceptionMessage('Пересохраните его в формате .xlsx');

        $converter->withReadablePath($source, static fn (string $p): string => $p);
    }

    public function testTooManyCellsIsRejectedBeforeLoading(): void
    {
        // Размер файла — плохая мера памяти: этот файл крошечный, но по числу ячеек
        // выходит за предел, и отказ обязан случиться до загрузки книги.
        $source = $this->writeXls([['a', 'b', 'c'], ['d', 'e', 'f']]);

        $converter = new LegacyXlsConverter(new TemporaryFileFactory(), self::projectDir(), maxCells: 3);

        $this->expectException(LegacyXlsTooManyCellsException::class);
        $this->expectExceptionMessage('Пересохраните файл в формате .xlsx');

        $converter->withReadablePath($source, static fn (string $p): string => $p);
    }

    public function testMissingFileIsReportedAsUnreadableRatherThanOversized(): void
    {
        $converter = $this->converter();

        // Пропавший файл — это не «слишком большой файл на 0 МБ»: такая формулировка
        // уводила бы разбор к размерам вместо доставки файла.
        $this->expectException(LegacyXlsUnreadableException::class);
        $this->expectExceptionMessage('недоступен или повреждён');

        $converter->withReadablePath('/tmp/no-such-file-'.uniqid().'.xls', static fn (string $p): string => $p);
    }

    public function testCorruptedFileGivesReasonRatherThanTechnicalError(): void
    {
        // Файл существует и размер у него читается, поэтому проверки размера его
        // пропускают — а PhpSpreadsheet роняет разбор техническим исключением.
        // Наружу оно уходило бы в очередь ровно так же непонятно, как исходный дефект.
        $source = $this->temporaryPath('broken-', '.xls');
        file_put_contents($source, random_bytes(4096));

        try {
            $this->converter()->withReadablePath($source, static fn (string $p): string => $p);
            self::fail('Повреждённый файл обязан быть отклонён.');
        } catch (LegacyXlsUnreadableException $e) {
            self::assertStringContainsString('недоступен или повреждён', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'Техническая причина обязана сохраниться для разбора.');
        }
    }

    public function testTruncatedFileDoesNotKillTheProcess(): void
    {
        // Главный регрессионный тест задачи. Обрезанный .xls с целой сигнатурой —
        // обычный результат прерванной загрузки. PhpSpreadsheet на нём запрашивает
        // сотни мегабайт прямо в разборе OLE-заголовка и убивает процесс фатальной
        // ошибкой, которую не ловит ни один try/catch. До выноса конвертации в
        // отдельный процесс этот тест ронял весь прогон PHPUnit.
        $before = $this->temporaryXlsxFiles();
        $source = $this->truncatedXls();

        try {
            $this->converter()->withReadablePath($source, static fn (string $p): string => $p);
            self::fail('Обрезанный файл обязан быть отклонён.');
        } catch (LegacyXlsUnreadableException $e) {
            self::assertStringContainsString('недоступен или повреждён', $e->getMessage());
        }

        self::assertSame($before, $this->temporaryXlsxFiles(), 'Временная копия обязана убираться и при смерти дочернего процесса.');
    }

    private function truncatedXls(): string
    {
        $source = $this->writeXls([['a']]);
        // Сигнатура и заголовок целы, тело обрезано — ровно то, что даёт оборванная
        // передача файла.
        $intact = (string) file_get_contents($source);
        file_put_contents($source, substr($intact, 0, (int) (\strlen($intact) * 0.6)));

        return $source;
    }

    public function testProcessKilledBySignalGivesReasonRatherThanTechnicalError(): void
    {
        // Убитый сигналом процесс не доходит до кода возврата: Process бросает
        // исключение прямо из wait(). Так выглядит работа OOM-killer, и без отдельной
        // ветки наружу уходило бы техническое исключение Symfony.
        $fakeProjectDir = $this->fakeProjectDirWithSuicidalConsole();
        $converter = new LegacyXlsConverter(new TemporaryFileFactory(), $fakeProjectDir);

        try {
            $converter->withReadablePath($this->writeXls([['a']]), static fn (string $p): string => $p);
            self::fail('Убитый процесс обязан дать отказ.');
        } catch (LegacyXlsUnreadableException $e) {
            self::assertStringContainsString('недоступен или повреждён', $e->getMessage());
            // Именно сигнал, а не просто ненулевой код: иначе тест проходил бы и без
            // ветки, которую он призван закрепить.
            self::assertInstanceOf(ProcessSignaledException::class, $e->getPrevious());
        }
    }

    private function fakeProjectDirWithSuicidalConsole(): string
    {
        $dir = $this->temporaryPath('fake-project-', '.d');
        unlink($dir);
        mkdir($dir.'/bin', 0o777, true);
        file_put_contents($dir.'/bin/console', "<?php\nexec('kill -9 '.getmypid());\n");
        $this->directories[] = $dir;

        return $dir;
    }

    public function testOverageBelowDisplayPrecisionStillReadsAsOverage(): void
    {
        $exception = new LegacyXlsTooLargeException(20 * 1024 * 1024 + 1, 20 * 1024 * 1024);

        // Превышение на байт печаталось как «20,00 МБ при пределе 20,00 МБ» —
        // сообщение, которое само себя опровергает.
        self::assertStringContainsString('20,01 МБ при пределе 20,00 МБ', $exception->getMessage());
    }

    public function testCellLimitFollowsMemoryBudgetRatherThanBeingFixed(): void
    {
        // Один и тот же лист безопасен при memory_limit=1G и убивает процесс при 256M,
        // поэтому фиксированное число ячеек было бы защитой только на бумаге.
        $small = LegacyXlsConverter::maxCellsForBudget(128 * 1024 * 1024);
        $large = LegacyXlsConverter::maxCellsForBudget(512 * 1024 * 1024);

        self::assertSame(87381, $small, 'Бюджет в 128 МБ обязан давать десятки тысяч ячеек.');
        self::assertSame(4 * $small + 1, $large, 'Вчетверо больший бюджет обязан давать вчетверо больший потолок.');
    }

    public function testMemoryBudgetIsDerivedFromProcessLimit(): void
    {
        $limit = \ini_get('memory_limit');
        self::assertIsString($limit);

        try {
            ini_set('memory_limit', '-1');
            // Без предела в процессе брать «половину» не от чего — нужен запасной бюджет.
            self::assertSame(256 * 1024 * 1024, LegacyXlsConverter::defaultMemoryBudgetBytes());
        } finally {
            ini_set('memory_limit', $limit);
        }
    }

    public function testXlsxPathIsPassedThroughUntouched(): void
    {
        $path = '/tmp/whatever.xlsx';

        $seen = $this->converter()->withReadablePath($path, static fn (string $p): string => $p);

        self::assertSame($path, $seen, 'Не-xls конвертировать незачем.');
    }

    public function testFormatHintWinsOverPathExtension(): void
    {
        // Так лежит загруженный файл: путь без расширения, формат знает только имя оригинала.
        $source = $this->writeXls([['Итого', '42']], withExtension: false);

        $rows = $this->converter()->withReadablePath($source, $this->readRows(...), 'xls');

        self::assertSame([['Итого', '42']], $rows);
    }

    public function testTemporaryFileIsRemovedEvenWhenConsumerThrows(): void
    {
        $source = $this->writeXls([['a', 'b']]);
        $captured = null;

        try {
            $this->converter()->withReadablePath($source, static function (string $path) use (&$captured): never {
                $captured = $path;

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // ожидаемо
        }

        self::assertNotNull($captured);
        self::assertFileDoesNotExist($captured, 'Временная копия обязана убираться и при исключении.');
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 5);
    }

    /**
     * Временный путь с нужным суффиксом, без мусорного файла рядом.
     *
     * tempnam() создаёт файл без суффикса: если просто дописать расширение к его
     * имени, исходный файл останется в /tmp навсегда.
     */
    private function temporaryPath(string $prefix, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);

        $withSuffix = $path.$suffix;
        self::assertTrue(rename($path, $withSuffix));
        $this->garbage[] = $withSuffix;

        return $withSuffix;
    }

    /**
     * @return list<string>
     */
    private function temporaryXlsxFiles(): array
    {
        $found = glob(sys_get_temp_dir().'/xls-convert-*.xlsx');

        return false === $found ? [] : $found;
    }

    private function converter(): LegacyXlsConverter
    {
        return new LegacyXlsConverter(new TemporaryFileFactory(), self::projectDir());
    }

    /**
     * @return list<list<string>>
     */
    private function readRows(string $path): array
    {
        $reader = new XlsxReader();
        $reader->open($path);

        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(static fn ($cell): string => (string) $cell, $row->toArray());
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /**
     * @param list<list<string>> $rows
     */
    private function writeXls(array $rows, bool $withExtension = true): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $cells) {
            foreach ($cells as $colIndex => $value) {
                $sheet->setCellValueExplicit(
                    [$colIndex + 1, $rowIndex + 1],
                    $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
            }
        }

        return $this->saveXls($spreadsheet, $withExtension);
    }

    private function saveXls(Spreadsheet $spreadsheet, bool $withExtension = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-xls-');
        self::assertIsString($path);
        if ($withExtension) {
            $withExt = $path.'.xls';
            rename($path, $withExt);
            $path = $withExt;
        }

        (new XlsWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->garbage[] = $path;

        return $path;
    }
}
