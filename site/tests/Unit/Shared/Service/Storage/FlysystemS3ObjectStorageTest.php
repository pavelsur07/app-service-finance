<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Service\Storage;

use App\Shared\Service\Storage\FlysystemS3ObjectStorage;
use App\Shared\Service\Storage\ObjectStorageInterface;
use PHPUnit\Framework\TestCase;

final class FlysystemS3ObjectStorageTest extends TestCase
{
    /**
     * Гард на конфиг S3-клиента (в т.ч. http.decode_content=false, чтобы Guzzle не
     * авто-декодировал .gz и не падал с cURL error 61). Конструирование network-free —
     * S3Client ленивый, креды используются только на запросе.
     */
    public function testConstructsWithoutNetwork(): void
    {
        $storage = new FlysystemS3ObjectStorage(
            bucket: 'test-bucket',
            region: 'ru-1',
            endpoint: 'https://s3.twcstorage.ru',
            accessKey: 'dummy-key',
            secretKey: 'dummy-secret',
            pathStyleEndpoint: '0',
        );

        self::assertInstanceOf(ObjectStorageInterface::class, $storage);
    }
}
