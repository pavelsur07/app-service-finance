<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Service\Storage;

use App\Shared\Infrastructure\Performance\PerformanceOutcome;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use App\Shared\Service\Storage\ObjectStorageException;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\PerformanceObjectStorage;
use App\Shared\Service\Storage\StoredObject;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class PerformanceObjectStorageTest extends TestCase
{
    public function testReadAndWriteAreMeasuredWithBytesButWithoutPaths(): void
    {
        $handler = new TestHandler();
        $recorder = new PerformanceRecorder(new Logger('performance', [$handler]), true);
        $inner = $this->createMock(ObjectStorageInterface::class);
        $inner->method('read')->with('companies/x/raw/secret-name.json')->willReturn('0123456789');
        $inner->method('write')->willReturn(new StoredObject('companies/x/raw/a.json', 4));
        $storage = new PerformanceObjectStorage($inner, $recorder, 's3');

        $recorder->beginScope('RunSyncChunkMessage');
        self::assertSame('0123456789', $storage->read('companies/x/raw/secret-name.json'));
        $storage->write('companies/x/raw/a.json', 'abcd');
        $recorder->endScope(PerformanceOutcome::Ok);

        $events = array_column(array_map(static fn ($r): array => $r->context, $handler->getRecords()), null, 'stage');
        self::assertSame(['s3', 10, 1], [$events['storage_read']['backend'], $events['storage_read']['bytes'], $events['storage_read']['rows']]);
        self::assertSame(['s3', 4], [$events['storage_write']['backend'], $events['storage_write']['bytes']]);
        self::assertStringNotContainsString('secret-name', (string) json_encode($events));
    }

    public function testFailureIsRethrownUnchangedAndCountedAsError(): void
    {
        $handler = new TestHandler();
        $recorder = new PerformanceRecorder(new Logger('performance', [$handler]), true);
        $inner = $this->createMock(ObjectStorageInterface::class);
        $failure = new ObjectStorageException('boom');
        $inner->method('read')->willThrowException($failure);
        $storage = new PerformanceObjectStorage($inner, $recorder, 's3');

        $recorder->beginScope('RunSyncChunkMessage');
        try {
            $storage->read('a');
            self::fail('Exception must propagate');
        } catch (ObjectStorageException $e) {
            self::assertSame($failure, $e);
        }
        $recorder->endScope(PerformanceOutcome::Error);

        self::assertSame(1, $handler->getRecords()[0]->context['errors']);
    }

    public function testDisabledDiagnosticsIsAPlainPassThrough(): void
    {
        $handler = new TestHandler();
        $recorder = new PerformanceRecorder(new Logger('performance', [$handler]), false);
        $inner = $this->createMock(ObjectStorageInterface::class);
        $inner->expects(self::once())->method('exists')->with('a')->willReturn(true);
        $inner->expects(self::once())->method('delete')->with('a');

        $storage = new PerformanceObjectStorage($inner, $recorder, 's3');
        $recorder->beginScope('X');
        self::assertTrue($storage->exists('a'));
        $storage->delete('a');
        $recorder->endScope(PerformanceOutcome::Ok);

        self::assertSame([], $handler->getRecords());
    }
}
