<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Storage;

use App\Shared\Service\Storage\StorageService;
use PHPUnit\Framework\TestCase;

/**
 * Полный цикл storeBytes → файл на диске → getAbsolutePath → чтение.
 * Интеграционный, без Symfony kernel — работает с реальным временным каталогом.
 */
final class StorageServiceIntegrationTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/storage-service-int-'.bin2hex(random_bytes(6));
        if (!mkdir($this->storageRoot, 0o775, true) && !is_dir($this->storageRoot)) {
            self::fail('Failed to prepare integration storage root');
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $this->removeDir($this->storageRoot);
        }
        parent::tearDown();
    }

    public function testStoreBytesRoundTripThroughGetAbsolutePath(): void
    {
        $service = new StorageService($this->storageRoot);
        $bytes = "integration-payload\n".random_bytes(256);
        $relativePath = 'companies/11111111-1111-1111-1111-111111111111/marketplace-ads/ozon/bronze/2026/04/20/report.bin';

        $result = $service->storeBytes($bytes, $relativePath);

        self::assertSame($relativePath, $result['storagePath']);

        $absolute = $service->getAbsolutePath($result['storagePath']);
        self::assertSame($this->storageRoot.'/'.$relativePath, $absolute);
        self::assertFileExists($absolute);

        $readBack = file_get_contents($absolute);
        self::assertSame($bytes, $readBack);
        self::assertSame(hash('sha256', $readBack), $result['fileHash']);
        self::assertSame(strlen($readBack), $result['sizeBytes']);
    }

    private function removeDir(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
