<?php

namespace App\Cash\Service\Import\File;

use InvalidArgumentException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLS\Reader as XlsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class FileTabularReader
{
    /**
     * @return list<string|null>
     */
    public function readHeader(string $filePath): array
    {
        $reader = $this->openReaderByExtension($filePath);
        try {
            $reader->open($filePath);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();
                    return array_map(fn ($value) => $this->normalizeValue($value, true), $cells);
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return [];
    }

    /**
     * @return list<list<string|null>>
     */
    public function readSampleRows(string $filePath, int $limit = 20): array
    {
        if ($limit <= 0) {
            return [];
        }

        $rows = [];
        $reader = $this->openReaderByExtension($filePath);
        try {
            $reader->open($filePath);
            foreach ($reader->getSheetIterator() as $sheet) {
                $isHeaderRow = true;
                foreach ($sheet->getRowIterator() as $row) {
                    if ($isHeaderRow) {
                        $isHeaderRow = false;
                        continue;
                    }

                    $cells = $row->toArray();
                    $rows[] = array_map(fn ($value) => $this->normalizeValue($value, false), $cells);

                    if (count($rows) >= $limit) {
                        break 2;
                    }
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    public function openReader(string $filePath): ReaderInterface
    {
        return $this->openReaderByExtension($filePath);
    }

    private function openReaderByExtension(string $filePath): ReaderInterface
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->openCsvReader($filePath),
            'xlsx' => new XlsxReader(),
            'xls' => new XlsReader(),
            default => throw new InvalidArgumentException(sprintf('Unsupported file extension: %s', $extension)),
        };
    }

    private function openCsvReader(string $filePath): CsvReader
    {
        $options = new CsvOptions();
        $options->FIELD_DELIMITER = $this->detectCsvDelimiter($filePath);

        return new CsvReader($options);
    }

    private function detectCsvDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'rb');
        if (false === $handle) {
            return ';';
        }

        try {
            $line = '';
            while (!feof($handle)) {
                $line = fgets($handle);
                if (false === $line) {
                    break;
                }
                $line = trim($line);
                if ($line !== '') {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($line === '' || $line === false) {
            return ';';
        }

        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $candidates = [';', "\t", ','];
        $bestDelimiter = ';';
        $bestCount = 1;

        foreach ($candidates as $delimiter) {
            $columns = str_getcsv($line, $delimiter);
            $count = count($columns);
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }

    private function normalizeValue(mixed $value, bool $trim): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $stringValue = $value->format('Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            $stringValue = $value ? '1' : '0';
        } elseif (is_scalar($value) || $value instanceof \Stringable) {
            $stringValue = (string) $value;
        } else {
            $stringValue = (string) $value;
        }

        if ('' === trim($stringValue)) {
            return null;
        }

        return $trim ? trim($stringValue) : $stringValue;
    }
}
