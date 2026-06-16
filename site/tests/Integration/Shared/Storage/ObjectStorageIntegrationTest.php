<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Storage;

use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\StorageService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ObjectStorageIntegrationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $_ENV['APP_OBJECT_STORAGE_DRIVER'] = 'local';
        $_SERVER['APP_OBJECT_STORAGE_DRIVER'] = 'local';

        self::bootKernel();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        unset($_ENV['APP_OBJECT_STORAGE_DRIVER'], $_SERVER['APP_OBJECT_STORAGE_DRIVER']);

        parent::tearDown();
    }

    public function testLocalDriverWritesThroughStorageServicePath(): void
    {
        /** @var ObjectStorageInterface $objectStorage */
        $objectStorage = self::getContainer()->get(ObjectStorageInterface::class);
        /** @var StorageService $storageService */
        $storageService = self::getContainer()->get(StorageService::class);

        $relativePath = sprintf('object-storage-test/%s/payload.ndjson.gz', bin2hex(random_bytes(6)));
        $payload = gzencode("{\"id\":1}\n");
        self::assertIsString($payload);

        $stored = $objectStorage->write($relativePath, $payload);
        $absolutePath = $storageService->getAbsolutePath($stored->path);

        self::assertSame($relativePath, $stored->path);
        self::assertFileExists($absolutePath);
        self::assertSame($payload, file_get_contents($absolutePath));
        self::assertTrue($objectStorage->exists($relativePath));
    }
}
