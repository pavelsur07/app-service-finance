<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Storage;

use App\Ingestion\Exception\RawStorageException;
use App\Ingestion\Infrastructure\Storage\RawNdjsonCodec;
use PHPUnit\Framework\TestCase;

final class RawNdjsonCodecTest extends TestCase
{
    public function testEncodeRowsIsCanonicalForAssociativeKeyOrder(): void
    {
        $codec = new RawNdjsonCodec();

        $first = $codec->encodeRows([
            ['b' => 2, 'a' => ['z' => 1, 'm' => 2]],
        ]);
        $second = $codec->encodeRows([
            ['a' => ['m' => 2, 'z' => 1], 'b' => 2],
        ]);

        self::assertSame($first, $second);
        self::assertSame(hash('sha256', $first), hash('sha256', $second));
    }

    public function testDecodeCompressedRowsReturnsOriginalRows(): void
    {
        $codec = new RawNdjsonCodec();
        $rows = [
            ['sku' => 'A-1', 'qty' => 3],
            ['sku' => 'B-2', 'qty' => 0, 'price' => 10.5],
        ];

        $compressed = gzencode($codec->encodeRows($rows));

        self::assertIsString($compressed);
        self::assertEquals($rows, iterator_to_array($codec->decodeCompressedRows($compressed)));
    }

    public function testEncodeRowsRejectsEmptyBatch(): void
    {
        $codec = new RawNdjsonCodec();

        $this->expectException(RawStorageException::class);
        $this->expectExceptionMessage('Raw batch must contain at least one row.');

        $codec->encodeRows([]);
    }
}
