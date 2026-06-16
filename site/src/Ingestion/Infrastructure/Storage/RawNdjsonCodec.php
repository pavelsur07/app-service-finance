<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Storage;

use App\Ingestion\Exception\RawStorageException;

final class RawNdjsonCodec
{
    /**
     * @param iterable<array<string, mixed>> $rows
     */
    public function encodeRows(iterable $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = json_encode(
                $this->normalizeValue($row),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION,
            );
        }

        if ([] === $lines) {
            throw new RawStorageException('Raw batch must contain at least one row.');
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function decodeCompressedRows(string $compressedPayload): iterable
    {
        $payload = gzdecode($compressedPayload);
        if (false === $payload) {
            throw new RawStorageException('Failed to decode gzip raw payload.');
        }

        foreach (explode("\n", $payload) as $line) {
            if ('' === $line) {
                continue;
            }

            $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (!is_array($row)) {
                throw new RawStorageException('Decoded raw payload row is not an object.');
            }

            yield $row;
        }
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeValue(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->normalizeValue($nestedValue);
        }

        return $value;
    }
}
