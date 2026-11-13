<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Infrastructure\Import\File;

use App\Cash\Infrastructure\Import\File\FileTabularReader;
use App\Shared\Service\Storage\LegacyXlsConverter;
use App\Shared\Service\Storage\TemporaryFileFactory;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

final class FileTabularReaderTest extends TestCase
{
    public function testOnlyFirstXlsxSheetAndDateValuesAreRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reader-');
        self::assertIsString($path);
        rename($path, $path.'.xlsx');
        $path .= '.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([' Date ', '', 'Flag']));
        $writer->addRow(Row::fromValues([new \DateTimeImmutable('2026-09-01 12:30:00'), 12.5, true]));
        $writer->addNewSheetAndMakeItCurrent();
        $writer->addRow(Row::fromValues(['Ignored']));
        $writer->close();
        try {
            $reader = new FileTabularReader($this->converter());
            self::assertSame(['Date', null, 'Flag'], $reader->readHeader($path));
            self::assertSame([['rowNumber' => 2, 'row' => ['Date' => '46266.520833333', 'Колонка 2' => '12.5', 'Flag' => '1']]], iterator_to_array($reader->readImportRows($path)));
            self::assertSame([], $reader->readSampleRows($path, 0));
        } finally {
            unlink($path);
        }
    }

    private function converter(): LegacyXlsConverter
    {
        return new LegacyXlsConverter(new TemporaryFileFactory(), '/app');
    }
}
