<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Service\Storage;

use App\Shared\Service\Storage\LoggingObjectStorage;
use App\Shared\Service\Storage\ObjectStorageException;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\StoredObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LoggingObjectStorageTest extends TestCase
{
    public function testDelegatesAndReturnsResultWithoutLoggingOnFastSuccess(): void
    {
        $stored = new StoredObject('a/b.txt', 5);

        $inner = $this->createMock(ObjectStorageInterface::class);
        $inner->expects(self::once())->method('write')->with('a/b.txt', 'hello')->willReturn($stored);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::never())->method('warning');

        $result = (new LoggingObjectStorage($inner, $logger, 1000))->write('a/b.txt', 'hello');

        self::assertSame($stored, $result);
    }

    public function testFailureLogsErrorWithContextAndRethrows(): void
    {
        $inner = $this->createMock(ObjectStorageInterface::class);
        $inner->method('read')->willThrowException(new ObjectStorageException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Object storage operation failed', self::callback(
                static fn (array $context): bool => 'read' === $context['operation']
                    && 'x/y.txt' === $context['path']
                    && $context['exception'] instanceof ObjectStorageException
                    && array_key_exists('duration_ms', $context),
            ));

        $this->expectException(ObjectStorageException::class);

        (new LoggingObjectStorage($inner, $logger, 1000))->read('x/y.txt');
    }

    public function testSlowOperationLogsWarning(): void
    {
        $inner = $this->createMock(ObjectStorageInterface::class);
        $inner->method('exists')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Object storage operation slow', self::callback(
                static fn (array $context): bool => 'exists' === $context['operation'] && 'k' === $context['path'],
            ));

        // slowMs=0 → любая операция считается «медленной» (duration_ms >= 0), детерминированно.
        $result = (new LoggingObjectStorage($inner, $logger, 0))->exists('k');

        self::assertTrue($result);
    }
}
