<?php

namespace App\Cash\Service\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLS\Reader as XlsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Отвечает ТОЛЬКО за чтение файла и превращение его в итератор массивов
 * (ассоциативный массив, где ключи - это заголовки колонок).
 */
final class CashFileImportReader
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return iterable<array<string, string|null>>
     */
    public function read(CashFileImportJob $job): iterable
    {
        $filePath = $this->resolveFilePath($job);
        $reader = $this->createReader($filePath);
        $reader->open($filePath);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                // Работаем только с первым листом
                $headers = [];
                $headersFound = false;
                $rowsScanned = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowsScanned++;
                    // Защита от зависания на пустых файлах
                    if (!$headersFound && $rowsScanned > 100) break;

                    $cells = $row->toArray();

                    // 1. Пропускаем пустые строки
                    if ($this->isEmptyRow($cells)) continue;

                    // 2. Ищем заголовок (эвристика: минимум 2 заполненные ячейки)
                    if (!$headersFound) {
                        if ($this->countNonEmpty($cells) < 2) continue;

                        $headers = $this->normalizeHeaders($cells);
                        $headersFound = true;
                        continue;
                    }

                    // 3. Возвращаем ассоциативный массив (Заголовок => Значение)
                    $rowAssoc = [];
                    foreach ($headers as $index => $label) {
                        $val = $cells[$index] ?? null;
                        $rowAssoc[$label] = $this->cleanValue($val);
                    }

                    yield $rowAssoc;
                }
                // Читаем только первый лист
                break;
            }
        } finally {
            $reader->close();
        }
    }

    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell instanceof \DateTimeInterface) return false;
            if (trim((string)$cell) !== '') return false;
        }
        return true;
    }

    private function countNonEmpty(array $cells): int
    {
        $cnt = 0;
        foreach ($cells as $c) {
            if (trim((string)$c) !== '') $cnt++;
        }
        return $cnt;
    }

    private function normalizeHeaders(array $cells): array
    {
        $headers = [];
        foreach ($cells as $index => $cell) {
            $val = $this->cleanValue($cell);
            $headers[$index] = ($val === '' || $val === null) ? "Col_{$index}" : $val;
        }
        return $headers;
    }

    private function cleanValue(mixed $val): ?string
    {
        if ($val === null) return null;
        if ($val instanceof \DateTimeInterface) return $val->format('Y-m-d H:i:s');
        if (is_bool($val)) return $val ? '1' : '0';
        return trim((string)$val);
    }

    private function createReader(string $filePath): ReaderInterface
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'csv' => (function() use ($filePath) {
                $opt = new CsvOptions();
                $opt->FIELD_DELIMITER = $this->detectCsvDelimiter($filePath);
                return new CsvReader($opt);
            })(),
            'xlsx' => new XlsxReader(),
            'xls' => new XlsReader(),
            default => throw new \RuntimeException("Unsupported format: $ext")
        };
    }

    private function detectCsvDelimiter(string $path): string
    {
        $h = fopen($path, 'r');
        if (!$h) return ';';
        $lines = [];
        for ($i=0; $i<5; $i++) { $l = fgets($h); if ($l) $lines[] = $l; }
        fclose($h);

        $delims = [';' => 0, ',' => 0, "\t" => 0];
        foreach ($lines as $line) {
            foreach (array_keys($delims) as $d) {
                $delims[$d] += substr_count($line, $d);
            }
        }
        arsort($delims);
        return array_key_first($delims) ?: ';';
    }

    private function resolveFilePath(CashFileImportJob $job): string
    {
        $storageDir = sprintf('%s/var/storage/cash-file-imports', $this->projectDir);
        $hash = $job->getFileHash();

        $candidates = array_merge(
            [$storageDir . '/' . $hash],
            glob($storageDir . '/' . $hash . '.*') ?: []
        );

        foreach ($candidates as $path) {
            if (is_file($path)) return $path;
        }
        throw new \RuntimeException("File not found: $hash");
    }
}
