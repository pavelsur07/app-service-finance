<?php
// Скрипт запускают и смонтированным в контейнер (/app), и из репозитория.
foreach (['/app/vendor/autoload.php', __DIR__.'/../../../site/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;

echo "memory_limit=", ini_get('memory_limit'), "\n";

$rows = (int) ($argv[1] ?? 2000);
$cols = (int) ($argv[2] ?? 20);

// плотный лист: каждая ячейка заполнена
$src = new Spreadsheet();
$sheet = $src->getActiveSheet();
for ($r = 1; $r <= $rows; $r++) {
    for ($c = 1; $c <= $cols; $c++) {
        $sheet->setCellValue([$c, $r], $r * $c + 0.55);
    }
}
$path = tempnam(sys_get_temp_dir(), 'dense-').'.xls';
(new XlsWriter($src))->save($path);
$src->disconnectWorksheets();
unset($src);
gc_collect_cycles();

$fileSize = filesize($path);
$base = memory_get_usage(true);
memory_reset_peak_usage();

$reader = IOFactory::createReader('Xls');
$info = $reader->listWorksheetInfo($path);
$reader->setLoadSheetsOnly($info[0]['worksheetName']);
$reader->setReadEmptyCells(false);
$book = $reader->load($path);
$out = tempnam(sys_get_temp_dir(), 'dense-out-').'.xlsx';
(new XlsxWriter($book))->save($out);
$peak = memory_get_peak_usage(true);
$book->disconnectWorksheets();

$cells = $rows * $cols;
printf("cells=%d file=%.2fMB peak=%.1fMB bytes_per_cell=%.0f\n",
    $cells, $fileSize / 1048576, $peak / 1048576, ($peak - $base) / $cells);

@unlink($path); @unlink($out);
