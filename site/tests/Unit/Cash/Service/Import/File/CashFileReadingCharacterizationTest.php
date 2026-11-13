<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\File;

use App\Cash\Service\Import\File\CashFileImportService;
use App\Cash\Service\Import\File\CashFileRowNormalizer;
use App\Cash\Service\Import\File\FileTabularReader;
use App\Shared\Service\Storage\LegacyXlsConverter;
use App\Shared\Service\Storage\TemporaryFileFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashFileReadingCharacterizationTest extends TestCase
{
    public static function delimiters(): iterable
    {
        yield 'semicolon' => [';'];
        yield 'comma' => [','];
        yield 'tab' => ["\t"];
    }

    #[DataProvider('delimiters')]
    public function testHeaderSampleAndImportGolden(string $delimiter): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cash-golden-').'.csv';
        $handle = fopen($path, 'w');
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
        $path = tempnam(sys_get_temp_dir(), 'cash-modes-').'.csv';
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

    public function testLegacyQuotaGoldenAllocationIsIndependentOfNumberedRowOrder(): void
    {
        $service = $this->importer();
        $method = new \ReflectionMethod(CashFileImportService::class, 'allocateExistingMatches');
        $existing = ['A' => 1, '' => 2, 'OTHER' => 1];
        self::assertSame(['B' => 1, 'A' => 2, '' => 1], $method->invoke($service, ['B' => 1, 'A' => 2, '' => 3], $existing));
        self::assertSame(['A' => 2, 'B' => 1, '' => 1], $method->invoke($service, ['' => 3, 'A' => 2, 'B' => 1], $existing));
    }

    private function importer(): CashFileImportService
    {
        $reflection = new \ReflectionClass(CashFileImportService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('rowNormalizer')->setValue($service, new CashFileRowNormalizer());

        return $service;
    }
}
