<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Reconciliation;

use App\Marketplace\Application\Reconciliation\BaseSignResolverService;
use App\Marketplace\Application\Reconciliation\OzonReportParserFacade;
use App\Marketplace\Application\Reconciliation\ReportAggregatorService;
use App\Marketplace\Application\Reconciliation\RowClassifierService;
use App\Marketplace\Application\Reconciliation\XlsxReaderService;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\StoredObject;
use App\Shared\Service\Storage\TemporaryLocalFile;
use DG\BypassFinals;
use PHPUnit\Framework\TestCase;

// Коллабораторы фасада — final, нужен BypassFinals для createMock.
BypassFinals::allowPaths([
    '*/src/Marketplace/Application/Reconciliation/*.php',
]);

/**
 * Регрессия PR 5a (S3-миграция, тип B): parseFromStoragePath читает файл через
 * объектное хранилище — скачивает во временную локальную копию с сохранением
 * расширения (.xlsx) и передаёт её ридеру, а не абсолютный путь с диска.
 */
final class OzonReportParserFacadeStorageTest extends TestCase
{
    public function testParsesFromStorageViaTemporaryLocalXlsxFile(): void
    {
        $bytes = 'FAKE-XLSX-'.bin2hex(random_bytes(6));

        $seenPath = null;
        $reader = $this->createMock(XlsxReaderService::class);
        $reader->method('read')->willReturnCallback(function (string $path) use ($bytes, &$seenPath): array {
            $seenPath = $path;
            self::assertFileExists($path, 'Ридер должен получить реальный локальный файл.');
            self::assertSame('xlsx', pathinfo($path, \PATHINFO_EXTENSION), 'Временный файл должен сохранить расширение ключа.');
            self::assertSame($bytes, file_get_contents($path));

            return ['rows' => [], 'period' => '2025-01'];
        });

        $aggregator = $this->createMock(ReportAggregatorService::class);
        $aggregator->method('aggregate')->willReturn(['ok' => true]);

        $facade = new OzonReportParserFacade(
            $reader,
            $this->createMock(BaseSignResolverService::class),
            $this->createMock(RowClassifierService::class),
            $aggregator,
            new TemporaryLocalFile($this->storageReturning($bytes)),
        );

        $result = $facade->parseFromStoragePath('marketplace/reconciliation/ozon/2025-01/report.xlsx');

        self::assertSame(['ok' => true], $result);
        self::assertIsString($seenPath);
        self::assertFileDoesNotExist($seenPath, 'Временная копия должна быть удалена после парсинга.');
    }

    private function storageReturning(string $contents): ObjectStorageInterface
    {
        return new class($contents) implements ObjectStorageInterface {
            public function __construct(private readonly string $contents)
            {
            }

            public function write(string $path, string $contents): StoredObject
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
