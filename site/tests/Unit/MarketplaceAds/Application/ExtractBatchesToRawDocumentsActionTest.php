<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application;

use App\MarketplaceAds\Application\ExtractBatchesToRawDocumentsAction;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Shared\Service\Storage\StorageService;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit-тесты {@see ExtractBatchesToRawDocumentsAction::extractCsvsFromBatch()}
 * (Task-12-test).
 *
 * Покрываемые инварианты:
 *  - одиночный CSV-файл (batch из 1 кампании) → один элемент по basename;
 *  - zip с 3 CSV → три элемента по именам внутри архива;
 *  - zip с non-CSV (например, readme.txt) → non-CSV игнорируется;
 *  - битый zip → \RuntimeException с понятным сообщением;
 *  - unknown extension → \RuntimeException;
 *  - отсутствие файла на диске → \RuntimeException;
 *  - batch без storage_path → \RuntimeException.
 */
final class ExtractBatchesToRawDocumentsActionTest extends TestCase
{
    private string $tmpDir = '';
    private ExtractBatchesToRawDocumentsAction $action;

    protected function setUp(): void
    {
        // Изолированная storageRoot на /tmp: тесты не пересекаются с реальным
        // storage dev-контейнера и чистятся в tearDown.
        $this->tmpDir = sys_get_temp_dir().'/extract-batches-test-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        $this->action = new ExtractBatchesToRawDocumentsAction(
            $this->createMock(AdScheduledBatchRepository::class),
            $this->createMock(AdRawDocumentRepository::class),
            new StorageService($this->tmpDir),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->rmDirRecursive($this->tmpDir);
    }

    public function testExtractSingleCsvReturnsOneEntryWithBasenameKey(): void
    {
        $relativePath = 'marketplace-ads/11111111-1111-1111-1111-111111111111/22655731_23.04.2026-23.04.2026.csv';
        $csvBody = "\xEF\xBB\xBF;Кампания по продвижению товаров № 22655731, период 23.04.2026-23.04.2026\n"
            ."sku;spend\n"
            ."sku-1;1.00\n";
        $this->writeFile($relativePath, $csvBody);

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withStorage($relativePath, 'hash-1', strlen($csvBody))
            ->build();

        $result = $this->action->extractCsvsFromBatch($batch);

        self::assertCount(1, $result);
        self::assertArrayHasKey('22655731_23.04.2026-23.04.2026.csv', $result);
        self::assertSame($csvBody, $result['22655731_23.04.2026-23.04.2026.csv']);
    }

    public function testExtractZipWithMultipleCsvsReturnsAllEntriesKeyedByNameInZip(): void
    {
        $relativePath = 'marketplace-ads/11111111-1111-1111-1111-111111111111/report.zip';
        $zipAbsolute = $this->tmpDir.'/'.$relativePath;
        $this->makeParentDir($zipAbsolute);

        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($zipAbsolute, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('22655731_23.04.2026-23.04.2026.csv', "csv-1-content");
        $zip->addFromString('22655732_23.04.2026-23.04.2026.csv', "csv-2-content");
        $zip->addFromString('22655733_23.04.2026-23.04.2026.csv', "csv-3-content");
        $zip->close();

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withStorage($relativePath, 'hash-zip', filesize($zipAbsolute))
            ->build();

        $result = $this->action->extractCsvsFromBatch($batch);

        self::assertCount(3, $result);
        self::assertSame('csv-1-content', $result['22655731_23.04.2026-23.04.2026.csv']);
        self::assertSame('csv-2-content', $result['22655732_23.04.2026-23.04.2026.csv']);
        self::assertSame('csv-3-content', $result['22655733_23.04.2026-23.04.2026.csv']);
    }

    public function testExtractZipIgnoresNonCsvEntries(): void
    {
        $relativePath = 'marketplace-ads/11111111-1111-1111-1111-111111111111/mixed.zip';
        $zipAbsolute = $this->tmpDir.'/'.$relativePath;
        $this->makeParentDir($zipAbsolute);

        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($zipAbsolute, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('22655731.csv', "csv-only");
        $zip->addFromString('readme.txt', "this should be skipped");
        $zip->addFromString('manifest.json', "{\"ignored\":true}");
        $zip->close();

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withStorage($relativePath, 'hash-mixed', filesize($zipAbsolute))
            ->build();

        $result = $this->action->extractCsvsFromBatch($batch);

        self::assertSame(['22655731.csv' => 'csv-only'], $result);
    }

    public function testCorruptedZipThrowsRuntimeException(): void
    {
        $relativePath = 'marketplace-ads/11111111-1111-1111-1111-111111111111/broken.zip';
        $this->writeFile($relativePath, 'not-a-real-zip-body');

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withStorage($relativePath, 'hash-broken', 20)
            ->build();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot open zip/');

        $this->action->extractCsvsFromBatch($batch);
    }

    public function testUnknownExtensionThrowsRuntimeException(): void
    {
        $relativePath = 'marketplace-ads/11111111-1111-1111-1111-111111111111/report.xls';
        $this->writeFile($relativePath, 'whatever');

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withStorage($relativePath, 'hash-xls', 8)
            ->build();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown batch file extension/');

        $this->action->extractCsvsFromBatch($batch);
    }

    public function testMissingFileOnDiskThrowsRuntimeException(): void
    {
        $batch = AdScheduledBatchBuilder::aBatch()
            ->withStorage('marketplace-ads/nowhere/ghost.csv', 'hash', 10)
            ->build();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Batch file missing on disk/');

        $this->action->extractCsvsFromBatch($batch);
    }

    public function testBatchWithoutStoragePathThrowsRuntimeException(): void
    {
        // AdScheduledBatchBuilder без withStorage() оставляет storagePath = null.
        $batch = AdScheduledBatchBuilder::aBatch()->build();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no storage_path/');

        $this->action->extractCsvsFromBatch($batch);
    }

    public function testBuildRawPayloadPrefixesCsvWithBatchIdAndFilenameMarker(): void
    {
        $payload = ExtractBatchesToRawDocumentsAction::buildRawPayload(
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            '22655731_23.04.2026-23.04.2026.csv',
            "sku;spend\nsku-1;1.00",
        );

        self::assertStringStartsWith(
            "batch_id=bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb\nfilename=22655731_23.04.2026-23.04.2026.csv\n---\n",
            $payload,
        );
        self::assertStringEndsWith("sku;spend\nsku-1;1.00", $payload);
    }

    private function writeFile(string $relativePath, string $bytes): void
    {
        $absolute = $this->tmpDir.'/'.$relativePath;
        $this->makeParentDir($absolute);
        file_put_contents($absolute, $bytes);
    }

    private function makeParentDir(string $absolutePath): void
    {
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
    }

    private function rmDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->rmDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
