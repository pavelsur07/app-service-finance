<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Command;

use App\Shared\Command\StorageHealthCheckCommand;
use App\Shared\Service\Storage\ObjectStorageException;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\StoredObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StorageHealthCheckCommandTest extends TestCase
{
    public function testHealthyRoundTripReturnsSuccess(): void
    {
        $written = null;

        $storage = $this->createMock(ObjectStorageInterface::class);
        $storage->method('write')->willReturnCallback(
            function (string $key, string $contents) use (&$written): StoredObject {
                $written = $contents;

                return new StoredObject($key, \strlen($contents));
            },
        );
        // read возвращает ровно то, что записали → payload совпадает → healthcheck OK.
        // Захват $written ПО ССЫЛКЕ — иначе увидим null (значение на момент определения).
        $storage->method('read')->willReturnCallback(function () use (&$written): string {
            return (string) $written;
        });
        $storage->expects(self::atLeastOnce())->method('delete');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $tester = new CommandTester(new StorageHealthCheckCommand($storage, $logger));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('OK', $tester->getDisplay());
    }

    public function testStorageFailureLogsErrorAndReturnsFailure(): void
    {
        $storage = $this->createMock(ObjectStorageInterface::class);
        $storage->method('write')->willThrowException(new ObjectStorageException('S3 unreachable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Object storage healthcheck FAILED', self::callback(
                static fn (array $context): bool => array_key_exists('key', $context)
                    && array_key_exists('duration_ms', $context)
                    && $context['exception'] instanceof ObjectStorageException,
            ));

        $tester = new CommandTester(new StorageHealthCheckCommand($storage, $logger));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testReadBackMismatchIsTreatedAsFailure(): void
    {
        $storage = $this->createMock(ObjectStorageInterface::class);
        $storage->method('write')->willReturnCallback(
            static fn (string $key, string $contents): StoredObject => new StoredObject($key, \strlen($contents)),
        );
        // read возвращает НЕ то, что записали → повреждение → FAILURE.
        $storage->method('read')->willReturn('corrupted-payload');
        $storage->method('delete');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Object storage healthcheck FAILED', self::anything());

        $tester = new CommandTester(new StorageHealthCheckCommand($storage, $logger));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }
}
