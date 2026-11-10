<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Infrastructure\Export;

use App\Finance\Infrastructure\Export\PlReportXlsxExporter;
use PHPUnit\Framework\TestCase;

final class PlReportXlsxExporterTest extends TestCase
{
    /**
     * Названия проектов (заголовки колонок в разрезе по проектам) и статей ОПиУ задаёт
     * пользователь: строка на «=» должна остаться текстом. Проверка по XML листа —
     * OpenSpout Reader сам превращает такую строку в FormulaCell.
     */
    public function testUserTextStartingWithEqualsIsWrittenAsText(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pl_report_export_test_');
        self::assertNotFalse($path);

        try {
            (new PlReportXlsxExporter())->export(
                'По проектам',
                [['id' => 'p1', 'label' => '=HYPERLINK("https://example.test","проект")'], ['id' => '_total', 'label' => 'Итого']],
                [['id' => 'r1', 'code' => '=1+1', 'name' => '=SUM(1,2)', 'level' => 1, 'type' => 'income']],
                ['r1' => ['p1' => 100.0, '_total' => 100.0]],
                [],
                true,
                $path,
            );

            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $shared = (string) $zip->getFromName('xl/sharedStrings.xml');
            $zip->close();
        } finally {
            unlink($path);
        }

        self::assertStringNotContainsString('<f>', $sheet);
        self::assertStringContainsString('HYPERLINK', $sheet.$shared);
        self::assertStringContainsString('SUM(1,2)', $sheet.$shared);
    }
}
