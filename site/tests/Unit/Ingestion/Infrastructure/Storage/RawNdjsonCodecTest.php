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

    public function testDecodeCompressedRowsReadsPayloadSpanningManyInflateChunks(): void
    {
        $codec = new RawNdjsonCodec();
        $rows = [];
        for ($i = 0; $i < 3000; ++$i) {
            // Несжимаемое поле: сжатый снимок больше одной порции распаковки.
            $rows[] = ['id' => $i, 'noise' => bin2hex(random_bytes(128))];
        }

        $compressed = gzencode($codec->encodeRows($rows));

        self::assertIsString($compressed);
        self::assertGreaterThan(65536 * 4, \strlen($compressed));
        self::assertSame($rows, iterator_to_array($codec->decodeCompressedRows($compressed), false));
    }

    public function testDecodeCompressedRowsReadsLastLineWithoutTrailingNewline(): void
    {
        $compressed = gzencode("{\"a\":1}\n\n{\"b\":2}");

        self::assertIsString($compressed);
        self::assertSame([['a' => 1], ['b' => 2]], iterator_to_array((new RawNdjsonCodec())->decodeCompressedRows($compressed), false));
    }

    public function testDecodeCompressedRowsIgnoresBytesAfterStreamEndLikeGzdecode(): void
    {
        $codec = new RawNdjsonCodec();
        $rows = [['sku' => 'A-1'], ['sku' => 'B-2']];
        $compressed = gzencode($codec->encodeRows($rows));
        self::assertIsString($compressed);

        // Хвост длиннее порции распаковки: конец потока внутри первой порции.
        $withTrailer = $compressed.str_repeat("\0", 200000);

        self::assertNotFalse(gzdecode($withTrailer));
        self::assertSame($rows, iterator_to_array($codec->decodeCompressedRows($withTrailer)));
    }

    public function testDecodeCompressedRowsKeysRowsSequentially(): void
    {
        $compressed = gzencode("{\"a\":1}\n\n{\"b\":2}\n");
        self::assertIsString($compressed);

        self::assertSame([0 => ['a' => 1], 1 => ['b' => 2]], iterator_to_array((new RawNdjsonCodec())->decodeCompressedRows($compressed)));
    }

    public function testDecodeCompressedRowsRejectsCorruptPayload(): void
    {
        $this->expectException(RawStorageException::class);
        $this->expectExceptionMessage('Failed to decode gzip raw payload.');

        iterator_to_array((new RawNdjsonCodec())->decodeCompressedRows('not a gzip payload'));
    }

    public function testDecodeCompressedRowsRejectsTruncatedPayload(): void
    {
        $compressed = gzencode((new RawNdjsonCodec())->encodeRows([['sku' => 'A-1'], ['sku' => 'B-2']]));
        self::assertIsString($compressed);

        $this->expectException(RawStorageException::class);
        $this->expectExceptionMessage('Failed to decode gzip raw payload.');

        iterator_to_array((new RawNdjsonCodec())->decodeCompressedRows(substr($compressed, 0, -12)));
    }

    /**
     * GlitchTip #422: gzdecode() целиком плюс explode() держали распакованный
     * снимок в памяти дважды, и сверка Ozon падала по memory_limit на крупном снимке.
     */
    public function testDecodeCompressedRowsDoesNotHoldDecompressedPayloadInMemory(): void
    {
        $codec = new RawNdjsonCodec();
        $payload = '';
        for ($i = 0; $i < 40000; ++$i) {
            // Сжимаемость как у настоящего снимка (~5×), а не вырожденные повторы.
            $payload .= json_encode(['id' => $i, 'noise' => bin2hex(random_bytes(100)), 'pad' => str_repeat('a', 800)], \JSON_THROW_ON_ERROR)."\n";
        }
        $compressed = gzencode($payload);
        unset($payload);
        self::assertIsString($compressed);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $count = 0;
        foreach ($codec->decodeCompressedRows($compressed) as $_row) {
            ++$count;
        }
        $growth = memory_get_peak_usage() - $before;

        self::assertSame(40000, $count);
        // Распакованный снимок ~40 МБ; потоковая распаковка держит порции.
        self::assertLessThan(8 * 1024 * 1024, $growth);
    }

    public function testEncodeRowsRejectsEmptyBatch(): void
    {
        $codec = new RawNdjsonCodec();

        $this->expectException(RawStorageException::class);
        $this->expectExceptionMessage('Raw batch must contain at least one row.');

        $codec->encodeRows([]);
    }
}
