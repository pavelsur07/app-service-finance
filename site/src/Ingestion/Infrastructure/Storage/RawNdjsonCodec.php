<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Storage;

use App\Ingestion\Exception\RawStorageException;

final class RawNdjsonCodec
{
    private const INFLATE_CHUNK_BYTES = 65536;

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
        // Распаковка порциями: gzdecode() целиком плюс explode() держали крупный
        // снимок Ozon by-day в памяти дважды, и сверка падала по memory_limit
        // (GlitchTip #422). В памяти теперь только порция и недочитанная строка.
        $context = inflate_init(\ZLIB_ENCODING_GZIP);
        if (false === $context) {
            throw new RawStorageException('Failed to decode gzip raw payload.');
        }

        // Ключ — номер строки снимка, как у прежнего explode(): пустые строки
        // пропускаются, но номер занимают.
        $lineNumber = 0;
        $tail = '';
        $length = \strlen($compressedPayload);
        $offset = 0;
        do {
            $input = substr($compressedPayload, $offset, self::INFLATE_CHUNK_BYTES);
            $offset += self::INFLATE_CHUNK_BYTES;
            $finish = $offset >= $length;
            $chunk = @inflate_add($context, $input, $finish ? \ZLIB_FINISH : \ZLIB_SYNC_FLUSH);
            if (false === $chunk) {
                throw new RawStorageException('Failed to decode gzip raw payload.');
            }

            $lines = explode("\n", $tail.$chunk);
            $tail = (string) array_pop($lines);
            foreach ($lines as $line) {
                $row = $this->decodeLine($line);
                if (null !== $row) {
                    yield $lineNumber => $row;
                }
                ++$lineNumber;
            }
        } while (!$finish);

        if (\ZLIB_STREAM_END !== inflate_get_status($context)) {
            throw new RawStorageException('Failed to decode gzip raw payload.');
        }

        $row = $this->decodeLine($tail);
        if (null !== $row) {
            yield $lineNumber => $row;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeLine(string $line): ?array
    {
        if ('' === $line) {
            return null;
        }

        $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($row)) {
            throw new RawStorageException('Decoded raw payload row is not an object.');
        }

        return $row;
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
