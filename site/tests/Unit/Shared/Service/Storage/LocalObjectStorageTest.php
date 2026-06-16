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
}
