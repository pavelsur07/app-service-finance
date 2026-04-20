<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Service\Storage;

use App\Shared\Service\Storage\StorageService;
use PHPUnit\Framework\TestCase;

final class StorageServiceTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/storage-service-test-'.bin2hex(random_bytes(6));
        if (!mkdir($this->storageRoot, 0o775, true) && !is_dir($this->storageRoot)) {
            self::fail('Failed to prepare test storage root');
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $this->removeDir($this->storageRoot);
        }
        parent::tearDown();
    }

    public function testStoreBytesCreatesFileAndReturnsMetadata(): void
    {
        $service = new StorageService($this->storageRoot);
        $bytes = "raw-payload-бинарные-данные";

        $result = $service->storeBytes($bytes, 'companies/abc/file.bin');

        self::assertSame('companies/abc/file.bin', $result['storagePath']);
        self::assertSame(hash('sha256', $bytes), $result['fileHash']);
        self::assertSame(strlen($bytes), $result['sizeBytes']);
        self::assertFileExists($this->storageRoot.'/companies/abc/file.bin');
        self::assertSame($bytes, file_get_contents($this->storageRoot.'/companies/abc/file.bin'));
    }

    public function testStoreBytesCreatesDirectoryIfMissing(): void
    {
        $service = new StorageService($this->storageRoot);

        self::assertDirectoryDoesNotExist($this->storageRoot.'/a/b/c');

        $service->storeBytes('x', 'a/b/c/file.txt');

        self::assertDirectoryExists($this->storageRoot.'/a/b/c');
        self::assertFileExists($this->storageRoot.'/a/b/c/file.txt');
    }

    public function testStoreBytesDetectsBinaryMimeType(): void
    {
        $service = new StorageService($this->storageRoot);
        // ZIP magic bytes: PK\x03\x04 + минимальный end-of-central-dir
        $zip = "PK\x03\x04\x0a\x00\x00\x00\x00\x00".str_repeat("\x00", 18)."PK\x05\x06".str_repeat("\x00", 18);

        $result = $service->storeBytes($zip, 'bronze/archive.zip');

        self::assertNotNull($result['mimeType']);
        self::assertSame('application/zip', $result['mimeType']);
    }

    public function testStoreBytesDetectsTextMimeType(): void
    {
        $service = new StorageService($this->storageRoot);
        $csv = "campaign_id,sku,spend\n123,ABC,45.67\n";

        $result = $service->storeBytes($csv, 'bronze/report.csv');

        self::assertNotNull($result['mimeType']);
        self::assertStringStartsWith('text/', $result['mimeType']);
    }

    public function testStoreBytesRejectsPathTraversal(): void
    {
        $service = new StorageService($this->storageRoot);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal');

        $service->storeBytes('x', 'companies/../../etc/passwd');
    }

    public function testStoreBytesThrowsWhenWriteFails(): void
    {
        // storageRoot указывает на путь, где создать целевой подкаталог нельзя,
        // потому что в пути уже существует файл вместо директории.
        $blocker = $this->storageRoot.'/blocker';
        file_put_contents($blocker, 'occupies-path-as-file');

        $service = new StorageService($this->storageRoot);

        $this->expectException(\RuntimeException::class);

        // 'blocker/file.bin' → ensureDir('blocker') упадёт, потому что 'blocker' — файл.
        $service->storeBytes('x', 'blocker/file.bin');
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
