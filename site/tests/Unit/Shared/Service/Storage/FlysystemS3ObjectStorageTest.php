<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Service\Storage;

use App\Shared\Service\Storage\FlysystemS3ObjectStorage;
use App\Shared\Service\Storage\ObjectStorageException;
use App\Shared\Service\Storage\ObjectStorageInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class FlysystemS3ObjectStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/flysystem-s3-test-'.bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
        rmdir($this->root);
    }

    /**
     * Гард на конфиг S3-клиента (в т.ч. http.decode_content=false, чтобы Guzzle не
     * авто-декодировал .gz и не падал с cURL error 61). Конструирование network-free —
     * S3Client ленивый, креды используются только на запросе.
     */
    public function testConstructsWithoutNetwork(): void
    {
        self::assertInstanceOf(ObjectStorageInterface::class, $this->storage());
    }

    /**
     * GlitchTip группирует события по тексту исключения: путь объекта в сообщении
     * превращал один сбой хранилища в отдельный issue на каждую запись
     * (25.09.2026 — около двадцати issue). Путь живёт в свойстве, не в тексте.
     */
    public function testFailureMessageDoesNotDependOnObjectPath(): void
    {
        $storage = $this->storageOverLocalRoot();
        mkdir($this->root.'/first');
        mkdir($this->root.'/second');

        $first = $this->captureWriteFailure($storage, 'first');
        $second = $this->captureWriteFailure($storage, 'second');

        self::assertSame($first->getMessage(), $second->getMessage());
        self::assertStringNotContainsString('first', $first->getMessage());
        self::assertSame('first', $first->path);
        self::assertSame('second', $second->path);
        self::assertNotNull($first->getPrevious());
    }

    private function captureWriteFailure(FlysystemS3ObjectStorage $storage, string $path): ObjectStorageException
    {
        try {
            $storage->write($path, 'payload');
        } catch (ObjectStorageException $exception) {
            return $exception;
        }

        self::fail(sprintf('Write to "%s" was expected to fail.', $path));
    }

    private function storage(): FlysystemS3ObjectStorage
    {
        return new FlysystemS3ObjectStorage(
            bucket: 'test-bucket',
            region: 'ru-1',
            endpoint: 'https://s3.twcstorage.ru',
            accessKey: 'dummy-key',
            secretKey: 'dummy-secret',
            pathStyleEndpoint: '0',
        );
    }

    /**
     * Сеть в unit-тесте недоступна, поэтому S3-адаптер подменяется локальным:
     * запись поверх каталога с тем же именем гарантированно падает FilesystemException.
     */
    private function storageOverLocalRoot(): FlysystemS3ObjectStorage
    {
        $storage = $this->storage();
        (new \ReflectionProperty($storage, 'filesystem'))
            ->setValue($storage, new Filesystem(new LocalFilesystemAdapter($this->root)));

        return $storage;
    }
}
