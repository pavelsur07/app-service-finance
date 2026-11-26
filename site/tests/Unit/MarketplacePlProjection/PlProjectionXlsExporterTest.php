<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplacePlProjection;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use App\MarketplacePlProjection\Infrastructure\Export\PlProjectionXlsExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PHPUnit\Framework\TestCase;

final class PlProjectionXlsExporterTest extends TestCase
{
    public function testPreservesNamesHierarchyAndNumericPercentage(): void
    {
        $rows = [
            new PlPreviewRow('1', 'CM3', 'Маржа', 1, PLCategoryType::KPI, PLValueFormat::MONEY, ['new' => 100.0]),
            new PlPreviewRow('2', 'CM3K', '=Маржинальность', 2, PLCategoryType::KPI, PLValueFormat::PERCENT, ['new' => 0.125]),
        ];
        $file = tempnam(sys_get_temp_dir(), 'pl-xls');
        self::assertNotFalse($file);
        try {
            (new PlProjectionXlsExporter())->write($rows, $file);
            $sheet = (new Xls())->load($file)->getActiveSheet();
            self::assertSame('=Маржинальность', $sheet->getCell('A3')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A3')->getDataType());
            self::assertSame(1, $sheet->getStyle('A3')->getAlignment()->getIndent());
            self::assertSame(0.125, $sheet->getCell('B3')->getValue());
            self::assertSame('12.5%', $sheet->getCell('B3')->getFormattedValue());
        } finally {
            unlink($file);
        }
    }
}
