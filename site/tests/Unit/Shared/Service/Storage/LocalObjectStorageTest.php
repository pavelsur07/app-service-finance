<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Service\Storage;

use App\Shared\Service\Storage\LocalObjectStorage;
use App\Shared\Service\Storage\StorageService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LocalObjectStorageTest extends TestCase
{
    public function testWriteDelegatesToStorageService(): void
    {
        /** @var StorageService&MockObject $storageService */
        $storageService = $this->createMock(StorageService::class);
        $storageService
            ->expects(self::once())
            ->method('storeBytes')
            ->with('payload-bytes', 'company/source/file.ndjson.gz')
            ->willReturn([
                'storagePath' => 'company/source/file.ndjson.gz',
                'fileHash' => hash('sha256', 'payload-bytes'),
                'sizeBytes' => strlen('payload-bytes'),
                'mimeType' => 'application/gzip',
            ]);

        $stored = (new LocalObjectStorage($storageService))->write(
            'company/source/file.ndjson.gz',
            'payload-bytes',
        );

        self::assertSame('company/source/file.ndjson.gz', $stored->path);
        self::assertSame(strlen('payload-bytes'), $stored->byteSize);
    }

    public function testReadStreamOpensAbsolutePath(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'los-');
        self::assertIsString($tmp);
        file_put_contents($tmp, 'hello-bytes');

        /** @var StorageService&MockObject $storageService */
        $storageService = $this->createMock(StorageService::class);
        $storageService->method('getAbsolutePath')->with('a/b.txt')->willReturn($tmp);

        $stream = (new LocalObjectStorage($storageService))->readStream('a/b.txt');

        self::assertIsResource($stream);
        self::assertSame('hello-bytes', stream_get_contents($stream));
        fclose($stream);
        @unlink($tmp);
    }

    public function testDeleteRemovesFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'los-');
        self::assertIsString($tmp);
        file_put_contents($tmp, 'x');

        /** @var StorageService&MockObject $storageService */
        $storageService = $this->createMock(StorageService::class);
        $storageService->method('getAbsolutePath')->willReturn($tmp);

        (new LocalObjectStorage($storageService))->delete('a/b.txt');

        self::assertFileDoesNotExist($tmp);
    }

    public function testDeleteIsIdempotentForMissingFile(): void
    {
        $missing = sprintf('%s/no-such-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));

        /** @var StorageService&MockObject $storageService */
        $storageService = $this->createMock(StorageService::class);
        $storageService->method('getAbsolutePath')->willReturn($missing);

        (new LocalObjectStorage($storageService))->delete('missing');

        $this->addToAssertionCount(1);
    }
}
