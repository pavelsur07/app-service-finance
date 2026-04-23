<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\MessageHandler;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\DownloadOzonAdReportMessage;
use App\MarketplaceAds\MessageHandler\DownloadOzonAdReportHandler;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Shared\Service\Storage\StorageService;
use DG\BypassFinals;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

// StorageService — final class, нужен BypassFinals для createMock.
// AdLoadJobFinalizer — final readonly, нужен BypassFinals для createMock.
BypassFinals::allowPaths([
    '*/src/Shared/Service/Storage/StorageService.php',
    '*/src/MarketplaceAds/Application/Service/AdLoadJobFinalizer.php',
]);

/**
 * Unit-тесты {@see DownloadOzonAdReportHandler}: task-8 flow — сохранение
 * raw-отчёта на диск без парсинга.
 *
 * Покрываемые инварианты:
 *  1. findByIdAndCompany вернул null → warning + no-op ACK, никаких side-effects.
 *  2. pending-отчёт уже финализирован → info + no-op ACK, download не вызывается.
 *  3. OzonPermanentApiException → markFinalized(ERROR) + Unrecoverable.
 *  4. Generic \Throwable → markFinalized НЕ вызывается, exception propagate
 *     (Messenger ретраит).
 *  5. Happy-path: storeBytes вызван с правильным relativePath; AdRawDocument
 *     создан per-day с storage_path/hash/size и rawPayload='{}'.
 *  6. Detection расширения: zip по Content-Type, zip по magic bytes, csv по
 *     Content-Type, csv по умолчанию.
 *  7. ProcessAdRawDocumentMessage НЕ диспатчится (bus удалён из зависимостей).
 */
final class DownloadOzonAdReportHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const PENDING_ID = '22222222-2222-2222-2222-222222222222';
    private const OZON_UUID = '33333333-3333-3333-3333-333333333333';

    /** @var OzonAdPendingReportRepository&MockObject */
    private OzonAdPendingReportRepository $pendingRepo;
    /** @var AdRawDocumentRepository&MockObject */
    private AdRawDocumentRepository $rawRepo;
    /** @var OzonAdClient&MockObject */
    private OzonAdClient $client;
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;
    /** @var StorageService&MockObject */
    private StorageService $storage;
    /** @var AdLoadJobFinalizer&MockObject */
    private AdLoadJobFinalizer $finalizer;

    protected function setUp(): void
    {
        $this->pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);
        $this->rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->client = $this->createMock(OzonAdClient::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->storage = $this->createMock(StorageService::class);
        $this->finalizer = $this->createMock(AdLoadJobFinalizer::class);
    }

    public function testPendingReportNotFoundIsNoop(): void
    {
        $this->pendingRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->with(self::PENDING_ID, self::COMPANY_ID)
            ->willReturn(null);

        $this->client->expects(self::never())->method('fetchReportContent');
        $this->pendingRepo->expects(self::never())->method('markFinalized');
        $this->em->expects(self::never())->method('flush');
        $this->storage->expects(self::never())->method('storeBytes');

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testAlreadyFinalizedIsNoop(): void
    {
        $pending = $this->makePendingReport(finalizedAt: new \DateTimeImmutable('-1 minute'));

        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $this->client->expects(self::never())->method('fetchReportContent');
        $this->pendingRepo->expects(self::never())->method('markFinalized');
        $this->em->expects(self::never())->method('flush');
        $this->storage->expects(self::never())->method('storeBytes');

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testPermanentApiExceptionFinalizesErrorAndThrowsUnrecoverable(): void
    {
        $pending = $this->makePendingReport();

        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $this->client->method('fetchReportContent')
            ->willThrowException(new OzonPermanentApiException('403 forbidden'));

        $this->pendingRepo->expects(self::once())
            ->method('markFinalized')
            ->with(
                self::COMPANY_ID,
                self::OZON_UUID,
                OzonAdPendingReportState::ERROR,
                self::stringContains('Download permanent failure'),
            )
            ->willReturn(1);

        $this->em->expects(self::never())->method('flush');
        $this->storage->expects(self::never())->method('storeBytes');

        $this->expectException(\Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Ozon permanent failure');

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testGenericThrowableDoesNotFinalizeAndPropagates(): void
    {
        $pending = $this->makePendingReport();

        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);
        $this->client->method('fetchReportContent')
            ->willThrowException(new \RuntimeException('network timeout'));

        $this->pendingRepo->expects(self::never())->method('markFinalized');
        $this->em->expects(self::never())->method('flush');
        $this->storage->expects(self::never())->method('storeBytes');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('network timeout');

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testHappyPathStoresFileAndUpsertsDocumentsPerDayWithoutDispatch(): void
    {
        $pending = $this->makePendingReport();
        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $csvBody = "date;sku;spend\n2026-04-01;S1;1.00\n2026-04-02;S1;2.00";
        $this->client->method('fetchReportContent')
            ->with(self::COMPANY_ID, self::OZON_UUID)
            ->willReturn([
                'body' => $csvBody,
                'contentType' => 'text/csv; charset=utf-8',
            ]);

        $this->rawRepo->method('findByMarketplaceAndDate')->willReturn(null);

        /** @var list<AdRawDocument> $saved */
        $saved = [];
        $this->rawRepo->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(function (AdRawDocument $doc) use (&$saved): void {
                $saved[] = $doc;
            });

        $expectedPath = sprintf('marketplace-ads/%s/%s.csv', self::COMPANY_ID, self::OZON_UUID);
        $this->storage->expects(self::once())
            ->method('storeBytes')
            ->with($csvBody, $expectedPath)
            ->willReturn([
                'storagePath' => $expectedPath,
                'fileHash' => 'sha256:deadbeef',
                'sizeBytes' => \strlen($csvBody),
                'mimeType' => 'text/csv',
            ]);

        // Строгий порядок: flush → markFinalized(OK).
        $trace = [];
        $this->em->expects(self::once())
            ->method('flush')
            ->willReturnCallback(function () use (&$trace): void {
                $trace[] = 'flush';
            });
        $this->pendingRepo->expects(self::once())
            ->method('markFinalized')
            ->with(self::COMPANY_ID, self::OZON_UUID, OzonAdPendingReportState::OK, null)
            ->willReturnCallback(function () use (&$trace): int {
                $trace[] = 'markFinalized';

                return 1;
            });

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));

        self::assertCount(2, $saved, '2 AdRawDocument — по одному на день диапазона 2026-04-01..02');
        foreach ($saved as $doc) {
            self::assertSame(MarketplaceType::OZON, $doc->getMarketplace());
            self::assertSame(self::COMPANY_ID, $doc->getCompanyId());
            self::assertSame('{}', $doc->getRawPayload(), 'raw_payload = "{}" — контент на диске, не в БД');
            self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());
            self::assertSame($expectedPath, $doc->getStoragePath());
            self::assertSame('sha256:deadbeef', $doc->getFileHash());
            self::assertSame(\strlen($csvBody), $doc->getFileSizeBytes());
        }

        $dates = array_map(
            static fn (AdRawDocument $d): string => $d->getReportDate()->format('Y-m-d'),
            $saved,
        );
        self::assertSame(['2026-04-01', '2026-04-02'], $dates);

        self::assertSame(['flush', 'markFinalized'], $trace);
    }

    public function testExtensionZipByContentType(): void
    {
        $pending = $this->makePendingReport();
        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $this->client->method('fetchReportContent')->willReturn([
            'body' => 'arbitrary-bytes',
            'contentType' => 'application/zip',
        ]);
        $this->rawRepo->method('findByMarketplaceAndDate')->willReturn(null);

        $expectedPath = sprintf('marketplace-ads/%s/%s.zip', self::COMPANY_ID, self::OZON_UUID);
        $this->storage->expects(self::once())
            ->method('storeBytes')
            ->with(self::anything(), $expectedPath)
            ->willReturn([
                'storagePath' => $expectedPath,
                'fileHash' => 'h',
                'sizeBytes' => 15,
                'mimeType' => 'application/zip',
            ]);

        $this->pendingRepo->method('markFinalized')->willReturn(1);

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testExtensionZipByMagicBytesBeatsConflictingContentType(): void
    {
        $pending = $this->makePendingReport();
        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        // PK\x03\x04 — локальный ZIP-header. Magic bytes должны побеждать
        // Content-Type, даже если CDN/прокси пометил ответ как text/csv.
        // Иначе юзер скачал бы ".csv" с бинарным ZIP-содержимым.
        $zipBody = "PK\x03\x04rest-of-archive";
        $this->client->method('fetchReportContent')->willReturn([
            'body' => $zipBody,
            'contentType' => 'text/csv',
        ]);
        $this->rawRepo->method('findByMarketplaceAndDate')->willReturn(null);

        $expectedPath = sprintf('marketplace-ads/%s/%s.zip', self::COMPANY_ID, self::OZON_UUID);
        $this->storage->expects(self::once())
            ->method('storeBytes')
            ->with($zipBody, $expectedPath)
            ->willReturn([
                'storagePath' => $expectedPath,
                'fileHash' => 'h',
                'sizeBytes' => \strlen($zipBody),
                'mimeType' => 'application/zip',
            ]);

        $this->pendingRepo->method('markFinalized')->willReturn(1);

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testExtensionCsvByDefault(): void
    {
        $pending = $this->makePendingReport();
        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        // Ни zip-content-type, ни PK-magic bytes → дефолт csv.
        $this->client->method('fetchReportContent')->willReturn([
            'body' => 'date;sku;spend',
            'contentType' => null,
        ]);
        $this->rawRepo->method('findByMarketplaceAndDate')->willReturn(null);

        $expectedPath = sprintf('marketplace-ads/%s/%s.csv', self::COMPANY_ID, self::OZON_UUID);
        $this->storage->expects(self::once())
            ->method('storeBytes')
            ->with(self::anything(), $expectedPath)
            ->willReturn([
                'storagePath' => $expectedPath,
                'fileHash' => 'h',
                'sizeBytes' => 14,
                'mimeType' => 'text/plain',
            ]);

        $this->pendingRepo->method('markFinalized')->willReturn(1);

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testExistingDocumentGetsUpdatedPayloadAndFileStorage(): void
    {
        $pending = $this->makePendingReport();
        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $existing = new AdRawDocument(
            self::COMPANY_ID,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-04-01'),
            '{"old":"payload"}',
        );

        // findByMarketplaceAndDate — existing для первого дня, null для второго.
        $this->rawRepo->method('findByMarketplaceAndDate')
            ->willReturnCallback(function (string $companyId, string $mp, \DateTimeImmutable $date) use ($existing): ?AdRawDocument {
                if ('2026-04-01' === $date->format('Y-m-d')) {
                    return $existing;
                }

                return null;
            });
        $this->rawRepo->expects(self::once())->method('save');

        $this->client->method('fetchReportContent')->willReturn([
            'body' => 'body',
            'contentType' => 'text/csv',
        ]);
        $this->storage->method('storeBytes')->willReturn([
            'storagePath' => 'path',
            'fileHash' => 'h',
            'sizeBytes' => 4,
            'mimeType' => 'text/csv',
        ]);
        $this->pendingRepo->method('markFinalized')->willReturn(1);

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));

        self::assertSame('{}', $existing->getRawPayload(), 'existing updatePayload()->{}');
        self::assertSame('path', $existing->getStoragePath());
        self::assertSame(4, $existing->getFileSizeBytes());
    }

    private function makeHandler(): DownloadOzonAdReportHandler
    {
        return new DownloadOzonAdReportHandler(
            $this->pendingRepo,
            $this->rawRepo,
            $this->client,
            $this->em,
            $this->storage,
            $this->finalizer,
            new NullLogger(),
        );
    }

    private function makePendingReport(
        ?\DateTimeImmutable $finalizedAt = null,
        ?string $jobId = 'auto',
    ): OzonAdPendingReport {
        if ('auto' === $jobId) {
            $jobId = Uuid::uuid7()->toString();
        }
        $pending = new OzonAdPendingReport(
            companyId: self::COMPANY_ID,
            ozonUuid: self::OZON_UUID,
            dateFrom: new \DateTimeImmutable('2026-04-01'),
            dateTo: new \DateTimeImmutable('2026-04-02'),
            campaignIds: ['c1', 'c2'],
            jobId: $jobId,
        );

        $ref = new \ReflectionClass($pending);

        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($pending, self::PENDING_ID);

        $stateProp = $ref->getProperty('state');
        $stateProp->setAccessible(true);
        $stateProp->setValue($pending, OzonAdPendingReportState::OK);

        if (null !== $finalizedAt) {
            $finalizedProp = $ref->getProperty('finalizedAt');
            $finalizedProp->setAccessible(true);
            $finalizedProp->setValue($pending, $finalizedAt);
        }

        return $pending;
    }
}
