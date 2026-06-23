<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Service;

/**
 * Computes an order-independent SHA-256 hash of a source-data row.
 *
 * Associative arrays are recursively key-sorted before encoding so that the
 * same logical content always yields the same hash regardless of key order.
 * Mirrors the normalization used by RawNdjsonCodec, but targets a single row
 * rather than an NDJSON document.
 */
final readonly class SourceDataHasher
{
    /**
     * @param array<string, mixed>|array<int, mixed> $sourceData
     *
     * @return string lowercase 64-char hex digest
     */
    public function hash(array $sourceData): string
    {
        $json = json_encode(
            $this->normalizeValue($sourceData),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION,
        );

        return hash('sha256', $json);
    }

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
