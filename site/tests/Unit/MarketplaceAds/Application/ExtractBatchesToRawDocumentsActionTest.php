<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application;

use App\MarketplaceAds\Application\ExtractBatchesToRawDocumentsAction;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Shared\Service\Storage\StorageService;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
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

    public function testProcessBatchHappyPathCreatesRawDocAndDispatchesMessage(): void
    {
        $relativePath = 'marketplace-ads/11111111-1111-1111-1111-111111111111/22655731_23.04.2026-23.04.2026.csv';
        $csvBody = "sku;spend\nsku-1;1.00\n";
        $this->writeFile($relativePath, $csvBody);

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withId('bbbbbbbb-bbbb-bbbb-bbbb-000000000001')
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withState(AdScheduledBatchState::OK)
            ->withStorage($relativePath, 'hash-1', strlen($csvBody))
            ->build();

        $rawDocRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawDocRepo->expects(self::once())
            ->method('findByBatchAndFilename')
            ->with($batch->getCompanyId(), $batch->getId(), '22655731_23.04.2026-23.04.2026.csv')
            ->willReturn(null);
        $rawDocRepo->expects(self::once())
            ->method('save')
            ->with(self::isInstanceOf(AdRawDocument::class));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (object $msg): bool {
                return $msg instanceof ProcessAdRawDocumentMessage
                    && '11111111-1111-1111-1111-111111111111' === $msg->companyId;
            }))
            ->willReturnCallback(static fn (object $msg): Envelope => new Envelope($msg));

        $action = new ExtractBatchesToRawDocumentsAction(
            $this->createMock(AdScheduledBatchRepository::class),
            $rawDocRepo,
            new StorageService($this->tmpDir),
            $em,
            $messageBus,
            new NullLogger(),
        );

        $result = $action->processBatch($batch);

        self::assertSame(1, $result['processed']);
        self::assertSame(0, $result['skipped']);
    }

    public function testProcessBatchIdempotencyExistingRawDocIsSkipped(): void
    {
        $relativePath = 'marketplace-ads/11111111-1111-1111-1111-111111111111/already-processed.csv';
        $csvBody = "sku;spend\nsku-1;1.00\n";
        $this->writeFile($relativePath, $csvBody);

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withId('bbbbbbbb-bbbb-bbbb-bbbb-000000000002')
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withState(AdScheduledBatchState::OK)
            ->withStorage($relativePath, 'hash-1', strlen($csvBody))
            ->build();

        $existingDoc = $this->createMock(AdRawDocument::class);

        $rawDocRepo = $this->createMock(AdRawDocumentRepository::class);
        // Существующий документ найден → второй AdRawDocument не создаётся.
        $rawDocRepo->expects(self::once())
            ->method('findByBatchAndFilename')
            ->willReturn($existingDoc);
        $rawDocRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        // Flush не нужен: dispatchIds пуст (всё skipped), conditional-flush
        // пропускает noop-round-trip в БД. Clear тоже не нужен — UoW чист,
        // мы не вошли в persist-путь.
        $em->expects(self::never())->method('flush');
        $em->expects(self::never())->method('clear');

        $messageBus = $this->createMock(MessageBusInterface::class);
        // Dispatch не вызывается при skipped — reprocess уже происходит через
        // отдельный pipeline, а Poller не должен плодить дубликаты сообщений.
        $messageBus->expects(self::never())->method('dispatch');

        $action = new ExtractBatchesToRawDocumentsAction(
            $this->createMock(AdScheduledBatchRepository::class),
            $rawDocRepo,
            new StorageService($this->tmpDir),
            $em,
            $messageBus,
            new NullLogger(),
        );

        $result = $action->processBatch($batch);

        self::assertSame(0, $result['processed']);
        self::assertSame(1, $result['skipped']);
    }

    public function testProcessBatchZipWithMultipleCsvsProcessesAll(): void
    {
        $relativePath = 'marketplace-ads/11111111-1111-1111-1111-111111111111/multi.zip';
        $zipAbsolute = $this->tmpDir.'/'.$relativePath;
        $this->makeParentDir($zipAbsolute);

        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($zipAbsolute, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('22655731_23.04.2026-23.04.2026.csv', "csv-1-content");
        $zip->addFromString('22655732_23.04.2026-23.04.2026.csv', "csv-2-content");
        $zip->close();

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withId('bbbbbbbb-bbbb-bbbb-bbbb-000000000003')
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withState(AdScheduledBatchState::OK)
            ->withStorage($relativePath, 'hash-zip', (int) filesize($zipAbsolute))
            ->build();

        $rawDocRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawDocRepo->method('findByBatchAndFilename')->willReturn(null);
        $rawDocRepo->expects(self::exactly(2))->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $msg): Envelope => new Envelope($msg));

        $action = new ExtractBatchesToRawDocumentsAction(
            $this->createMock(AdScheduledBatchRepository::class),
            $rawDocRepo,
            new StorageService($this->tmpDir),
            $em,
            $messageBus,
            new NullLogger(),
        );

        $result = $action->processBatch($batch);

        self::assertSame(2, $result['processed']);
        self::assertSame(0, $result['skipped']);
    }

    public function testProcessBatchDetachesPersistedDocsWhenMidLoopExceptionLeaksEntities(): void
    {
        // Регрессия на UoW-leak (review PR #1654): processBatchInternal
        // персистит AdRawDocument в цикле по CSV. Если сбой случился на 2-м из
        // 3-х CSV — первый persist() уже успешен. Без detach'а orphan остался
        // бы в UoW и следующий em->flush() (следующий batch в tick'е Poller'а
        // или аггрегированный flush в __invoke) отправил бы его в БД вне
        // своего контекста.
        //
        // Почему detach, а не em->clear(): Doctrine ORM 3.x больше не
        // поддерживает selective clear(entityName); глобальный em->clear()
        // detach'нул бы и pre-fetched managed AdScheduledBatch'и Poller'а
        // (из findAllInFlight), после чего их setState/setLastError/flush
        // silently не дошли бы до БД — регрессия хуже самого leak'а.
        //
        // Контракт: em->detach() вызван ровно 1 раз (на первом, единственном
        // успешно-persisted AdRawDocument); em->flush() — не вызывался
        // (exception внутри цикла, до conditional flush'а в processBatch).
        $relativePath = 'marketplace-ads/11111111-1111-1111-1111-111111111111/three-csvs.zip';
        $zipAbsolute = $this->tmpDir.'/'.$relativePath;
        $this->makeParentDir($zipAbsolute);

        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($zipAbsolute, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('11111111_23.04.2026-23.04.2026.csv', 'csv-1-ok');
        $zip->addFromString('22222222_23.04.2026-23.04.2026.csv', 'csv-2-will-throw');
        $zip->addFromString('33333333_23.04.2026-23.04.2026.csv', 'csv-3-never-reached');
        $zip->close();

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withId('bbbbbbbb-bbbb-bbbb-bbbb-000000000099')
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withState(AdScheduledBatchState::OK)
            ->withStorage($relativePath, 'hash-3', (int) filesize($zipAbsolute))
            ->build();

        $rawDocRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawDocRepo->method('findByBatchAndFilename')->willReturn(null);

        // save() на 1-м CSV — OK (entity в UoW), на 2-м — бросает. 3-й до
        // вызова не доходит (исключение прерывает цикл).
        $saveCall = 0;
        $rawDocRepo->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(static function () use (&$saveCall): void {
                ++$saveCall;
                if (2 === $saveCall) {
                    throw new \RuntimeException('DB hiccup on 2nd CSV');
                }
            });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        // Clear не должен вызываться — он бы убил Poller'овские AdScheduledBatch.
        $em->expects(self::never())->method('clear');
        // Detach — строго по числу persisted-to-point-of-failure: 1.
        $em->expects(self::once())
            ->method('detach')
            ->with(self::isInstanceOf(AdRawDocument::class));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $action = new ExtractBatchesToRawDocumentsAction(
            $this->createMock(AdScheduledBatchRepository::class),
            $rawDocRepo,
            new StorageService($this->tmpDir),
            $em,
            $messageBus,
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB hiccup on 2nd CSV');

        $action->processBatch($batch);
    }

    public function testProcessBatchPropagatesExtractionError(): void
    {
        // storage_path отсутствует → extractCsvsFromBatch бросает \RuntimeException
        // ДО первого persist'а. processBatch пробрасывает — ничего не detach'ит
        // (persisted-list пуст), flush не зовётся.
        $batch = AdScheduledBatchBuilder::aBatch()->build();

        $rawDocRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawDocRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $em->expects(self::never())->method('clear');
        $em->expects(self::never())->method('detach');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $action = new ExtractBatchesToRawDocumentsAction(
            $this->createMock(AdScheduledBatchRepository::class),
            $rawDocRepo,
            new StorageService($this->tmpDir),
            $em,
            $messageBus,
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no storage_path/');

        $action->processBatch($batch);
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
