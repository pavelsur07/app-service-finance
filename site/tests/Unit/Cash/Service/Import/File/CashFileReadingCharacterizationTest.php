<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\File;

use App\Cash\Application\Service\Import\File\CashFileImportService;
use App\Cash\Application\Service\Import\File\CashFileRowNormalizer;
use App\Cash\Infrastructure\Import\File\FileTabularReader;
use App\Shared\Service\Storage\LegacyXlsConverter;
use App\Shared\Service\Storage\TemporaryFileFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashFileReadingCharacterizationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function delimiters(): iterable
    {
        yield 'semicolon' => [';'];
        yield 'comma' => [','];
        yield 'tab' => ["\t"];
    }

    #[DataProvider('delimiters')]
    public function testHeaderSampleAndImportGolden(string $delimiter): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cash-golden-');
        self::assertIsString($path);
        rename($path, $path.'.csv');
        $path .= '.csv';
        $handle = fopen($path, 'w');
        self::assertIsResource($handle);
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Дата', 'Сумма', '', 'Описание'], $delimiter, '"', '');
        fputcsv($handle, ['2026-09-01', '10.00', '', ' Text, with; delimiters '], $delimiter, '"', '');
        fclose($handle);
        try {
            $reader = new FileTabularReader(new LegacyXlsConverter(new TemporaryFileFactory(), \dirname(__DIR__, 6)));
            self::assertSame(['Дата', 'Сумма', null, 'Описание'], $reader->readHeader($path));
            self::assertSame([['2026-09-01', '10.00', null, ' Text, with; delimiters ']], $reader->readSampleRows($path));
            $service = $this->importer();
            $rows = iterator_to_array($service->normalizedRows($path, ['date' => 'Дата', 'amount' => 'Сумма', 'description' => 'Описание'], 'RUB'));
            self::assertSame(2, $rows[0]['rowNumber']);
            self::assertSame(['Дата' => '2026-09-01', 'Сумма' => '10.00', 'Колонка 3' => null, 'Описание' => ' Text, with; delimiters '], $rows[0]['row']);
            self::assertSame(['rowId' => 'r2', 'date' => '2026-09-01', 'amount' => '10.00', 'direction' => 'INFLOW', 'currency' => 'RUB', 'docNumber' => null, 'description' => 'Text, with; delimiters', 'counterparty' => null, 'ok' => true, 'errors' => []], CashFileImportService::planInput($rows[0]['normalized'], 2));
        } finally {
            unlink($path);
        }
    }

    public function testLegacyCsvModesIntentionallyChooseDifferentDelimiters(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cash-modes-');
        self::assertIsString($path);
        rename($path, $path.'.csv');
        $path .= '.csv';
        file_put_contents($path, "Дата;Описание\n2026-09-01;one,two,three,four\n");
        try {
            $reader = new FileTabularReader(new LegacyXlsConverter(new TemporaryFileFactory(), \dirname(__DIR__, 6)));
            self::assertSame(['Дата', 'Описание'], $reader->readHeader($path));
            $rows = iterator_to_array($this->importer()->normalizedRows($path, [], 'RUB'));
            self::assertSame(['Дата;Описание' => '2026-09-01;one'], $rows[0]['row']);
        } finally {
            unlink($path);
        }
    }

    public function testAllowedRowsAreExplicitAndDoNotLeakToTheNextIteration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cash-allowed-');
        self::assertIsString($path);
        rename($path, $path.'.csv');
        $path .= '.csv';
        file_put_contents($path, "Дата,Сумма\n2026-09-01,10.00\n2026-09-02,20.00\n");
        try {
            $rows = new \App\Cash\Application\Service\Import\File\CashFileNormalizedRows(new FileTabularReader(new LegacyXlsConverter(new TemporaryFileFactory(), '/app')), new CashFileRowNormalizer());
            $mapping = ['date' => 'Дата', 'amount' => 'Сумма'];
            $all = iterator_to_array($rows->iterate($path, $mapping, 'RUB'));
            $allowed = [3 => CashFileImportService::planInput($all[1]['normalized'], 3)];
            $selected = iterator_to_array($rows->iterate($path, $mapping, 'RUB', $allowed));
            self::assertEquals([$all[1]], $selected);
            self::assertSame([], iterator_to_array($rows->iterate($path, $mapping, 'RUB', [])));
            self::assertEquals($all, iterator_to_array($rows->iterate($path, $mapping, 'RUB')));
        } finally {
            unlink($path);
        }
    }

    private function importer(): CashFileImportService
    {
        $reflection = new \ReflectionClass(CashFileImportService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('rows')->setValue($service, new \App\Cash\Application\Service\Import\File\CashFileNormalizedRows(new FileTabularReader(new LegacyXlsConverter(new TemporaryFileFactory(), \dirname(__DIR__, 6))), new CashFileRowNormalizer()));

        return $service;
    }
}
