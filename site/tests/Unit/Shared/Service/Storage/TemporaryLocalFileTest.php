<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Service\Storage;

use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\TemporaryLocalFile;
use PHPUnit\Framework\TestCase;

final class TemporaryLocalFileTest extends TestCase
{
    public function testConsumerReceivesLocalPathWithObjectContents(): void
    {
        $temporaryLocalFile = new TemporaryLocalFile($this->storageReturning('spreadsheet-bytes'));

        $seenContents = null;
        $result = $temporaryLocalFile->with('imports/company/x.xlsx', function (string $localPath) use (&$seenContents): string {
            self::assertFileExists($localPath);
            $seenContents = file_get_contents($localPath);

            return 'parsed';
        });

        self::assertSame('spreadsheet-bytes', $seenContents);
        self::assertSame('parsed', $result);
    }

    public function testTemporaryFileRemovedAfterSuccess(): void
    {
        $temporaryLocalFile = new TemporaryLocalFile($this->storageReturning('payload'));

        $capturedPath = null;
        $temporaryLocalFile->with('a/b', function (string $localPath) use (&$capturedPath): void {
            $capturedPath = $localPath;
        });

        self::assertIsString($capturedPath);
        self::assertFileDoesNotExist($capturedPath);
    }

    public function testTemporaryFileRemovedEvenWhenConsumerThrows(): void
    {
        $temporaryLocalFile = new TemporaryLocalFile($this->storageReturning('payload'));

        $capturedPath = null;
        try {
            $temporaryLocalFile->with('a/b', function (string $localPath) use (&$capturedPath): void {
                $capturedPath = $localPath;
                throw new \RuntimeException('parser blew up');
            });
            self::fail('Expected exception to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('parser blew up', $exception->getMessage());
        }

        self::assertIsString($capturedPath);
        self::assertFileDoesNotExist($capturedPath);
    }

    private function storageReturning(string $contents): ObjectStorageInterface
    {
        return new class($contents) implements ObjectStorageInterface {
            public function __construct(private readonly string $contents)
            {
            }

            public function write(string $path, string $contents): \App\Shared\Service\Storage\StoredObject
            {
                throw new \LogicException('not needed');
            }

            public function read(string $path): string
            {
                return $this->contents;
            }

            public function readStream(string $path)
            {
                $stream = fopen('php://memory', 'r+b');
                fwrite($stream, $this->contents);
                rewind($stream);

                return $stream;
            }

            public function exists(string $path): bool
            {
                return true;
            }

            public function delete(string $path): void
            {
            }
        };
    }
}
