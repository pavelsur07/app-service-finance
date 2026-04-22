<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\MessageHandler;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonReportDownload;
use App\MarketplaceAds\Message\DownloadOzonAdReportMessage;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

// StorageService — final class, нужен BypassFinals для createMock.
// AdLoadJobFinalizer — final readonly, нужен BypassFinals для createMock.
BypassFinals::allowPaths([
    '*/src/Shared/Service/Storage/StorageService.php',
    '*/src/MarketplaceAds/Application/Service/AdLoadJobFinalizer.php',
]);

/**
 * Unit-тесты {@see DownloadOzonAdReportHandler}: async-download pending-отчёта.
 *
 * Покрываемые инварианты:
 *  1. findByIdAndCompany вернул null → warning + no-op ACK, никаких side-effects.
 *  2. pending-отчёт уже финализирован → info + no-op ACK, download не вызывается.
 *  3. OzonPermanentApiException → markFinalized(ERROR) + Unrecoverable.
 *  4. Generic \Throwable → markFinalized НЕ вызывается, exception propagate
 *     (Messenger ретраит).
 *  5. Happy-path 2 дня → 2 dispatch(ProcessAdRawDocumentMessage).
 *  6. Happy-path bronze — один download → setFileStorage на каждом документе.
 *  7. Порядок side-effects: flush → markFinalized(OK) → dispatch.
 */
final class DownloadOzonAdReportHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const PENDING_ID = '22222222-2222-2222-2222-222222222222';
    private const OZON_UUID = 'ozon-report-uuid-xyz';

    /** @var OzonAdPendingReportRepository&MockObject */
    private OzonAdPendingReportRepository $pendingRepo;
    /** @var AdRawDocumentRepository&MockObject */
    private AdRawDocumentRepository $rawRepo;
    /** @var OzonAdClient&MockObject */
    private OzonAdClient $client;
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;
    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $bus;
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
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->storage = $this->createMock(StorageService::class);
        $this->finalizer = $this->createMock(AdLoadJobFinalizer::class);
    }

    public function testPendingReportNotFoundIsNoop(): void
    {
        $this->pendingRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->with(self::PENDING_ID, self::COMPANY_ID)
            ->willReturn(null);

        $this->client->expects(self::never())->method('downloadAndConvertReport');
        $this->pendingRepo->expects(self::never())->method('markFinalized');
        $this->em->expects(self::never())->method('flush');
        $this->bus->expects(self::never())->method('dispatch');

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testAlreadyFinalizedIsNoop(): void
    {
        $pending = $this->makePendingReport(finalizedAt: new \DateTimeImmutable('-1 minute'));

        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $this->client->expects(self::never())->method('downloadAndConvertReport');
        $this->pendingRepo->expects(self::never())->method('markFinalized');
        $this->em->expects(self::never())->method('flush');
        $this->bus->expects(self::never())->method('dispatch');

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testPermanentApiExceptionFinalizesErrorAndThrowsUnrecoverable(): void
    {
        $pending = $this->makePendingReport();

        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $this->client->method('downloadAndConvertReport')
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
        $this->bus->expects(self::never())->method('dispatch');

        $this->expectException(UnrecoverableMessageHandlingException::class);
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
        $this->client->method('downloadAndConvertReport')
            ->willThrowException(new \RuntimeException('network timeout'));

        $this->pendingRepo->expects(self::never())->method('markFinalized');
        $this->em->expects(self::never())->method('flush');
        $this->bus->expects(self::never())->method('dispatch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('network timeout');

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testHappyPathUpsertsDocumentsAndDispatchesProcessingAfterFlush(): void
    {
        $pending = $this->makePendingReport();

        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $download = $this->makeDownload('raw-bytes-1');
        $this->client->method('downloadAndConvertReport')
            ->willReturn([
                'downloads' => [$download],
                'resultByDate' => [
                    '2026-04-01' => ['campaigns' => [[
                        'campaign_id' => 'c1',
                        'campaign_name' => 'Camp 1',
                        'rows' => [['sku' => 's1', 'spend' => '10.00', 'views' => 5, 'clicks' => 1]],
                    ]]],
                    '2026-04-02' => ['campaigns' => [[
                        'campaign_id' => 'c1',
                        'campaign_name' => 'Camp 1',
                        'rows' => [['sku' => 's1', 'spend' => '20.00', 'views' => 10, 'clicks' => 2]],
                    ]]],
                ],
            ]);

        // Оба дня новые — findByMarketplaceAndDate возвращает null.
        $this->rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        /** @var list<AdRawDocument> $saved */
        $saved = [];
        $this->rawRepo->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(function (AdRawDocument $doc) use (&$saved): void {
                $saved[] = $doc;
            });

        $this->storage->expects(self::once())
            ->method('storeBytes')
            ->willReturn([
                'storagePath' => 'companies/xxx/marketplace-ads/ozon/bronze/2026-04-01/ozon-report-uuid-xyz.csv',
                'fileHash' => 'sha256:deadbeef',
                'sizeBytes' => 11,
                'mimeType' => 'text/csv',
            ]);

        // Строгий порядок вызовов: flush → markFinalized → dispatch. Assertим через
        // шаг-трекер, потому что PHPUnit InSequence не работает для разных моков.
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

        $dispatched = [];
        $this->bus->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatched, &$trace): Envelope {
                self::assertInstanceOf(ProcessAdRawDocumentMessage::class, $message);
                self::assertSame(self::COMPANY_ID, $message->companyId);
                $dispatched[] = $message;
                $trace[] = 'dispatch';

                return new Envelope($message);
            });

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));

        self::assertCount(2, $saved, '2 новых AdRawDocument должны быть сохранены');
        foreach ($saved as $doc) {
            self::assertSame(MarketplaceType::OZON, $doc->getMarketplace());
            self::assertSame(self::COMPANY_ID, $doc->getCompanyId());
            self::assertSame('companies/xxx/marketplace-ads/ozon/bronze/2026-04-01/ozon-report-uuid-xyz.csv', $doc->getStoragePath());
            self::assertSame('sha256:deadbeef', $doc->getFileHash());
            self::assertSame(11, $doc->getFileSizeBytes());
        }

        self::assertCount(2, $dispatched, 'dispatch должен быть вызван по разу на документ');
        self::assertSame(['flush', 'markFinalized', 'dispatch', 'dispatch'], $trace, 'flush → markFinalized → dispatch — порядок критичен');
    }

    public function testHappyPathExistingDocumentUpdatesPayload(): void
    {
        $pending = $this->makePendingReport();
        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $existing = new AdRawDocument(
            self::COMPANY_ID,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-04-01'),
            '{"old":"payload"}',
        );

        // Существующий документ — save не вызывается, updatePayload внутри entity.
        $this->rawRepo->method('findByMarketplaceAndDate')->willReturn($existing);
        $this->rawRepo->expects(self::never())->method('save');

        $download = $this->makeDownload('raw-bytes-2');
        $this->client->method('downloadAndConvertReport')
            ->willReturn([
                'downloads' => [$download],
                'resultByDate' => [
                    '2026-04-01' => ['campaigns' => [['campaign_id' => 'c1', 'campaign_name' => 'N', 'rows' => []]]],
                ],
            ]);

        $this->storage->method('storeBytes')->willReturn([
            'storagePath' => 'p',
            'fileHash' => 'h',
            'sizeBytes' => 11,
            'mimeType' => 'text/csv',
        ]);

        $this->em->expects(self::once())->method('flush');
        $this->pendingRepo->expects(self::once())->method('markFinalized');
        $this->bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));

        self::assertStringContainsString('"campaigns"', $existing->getRawPayload(), 'updatePayload должен переписать payload');
    }

    public function testHandlerCallsFinalizerOnZeroDocs(): void
    {
        $jobId = Uuid::uuid7()->toString();
        $pending = $this->makePendingReport(jobId: $jobId);
        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $download = $this->makeDownload('raw-bytes-empty');
        $this->client->method('downloadAndConvertReport')->willReturn([
            'downloads' => [$download],
            'resultByDate' => [],
        ]);

        $this->rawRepo->expects(self::never())->method('save');
        $this->storage->expects(self::never())->method('storeBytes');
        $this->em->expects(self::once())->method('flush');

        // markFinalized всё равно вызывается — пустой отчёт это валидный "обработан".
        $this->pendingRepo->expects(self::once())
            ->method('markFinalized')
            ->with(self::COMPANY_ID, self::OZON_UUID, OzonAdPendingReportState::OK, null)
            ->willReturn(1);

        $this->bus->expects(self::never())->method('dispatch');

        // Zero-docs edge case: handler должен сам вызвать finalizer->tryFinalize,
        // иначе job с нулём документов навечно залип бы в RUNNING.
        $this->finalizer->expects(self::once())
            ->method('tryFinalize')
            ->with($jobId, self::COMPANY_ID);

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testHandlerSkipsFinalizerIfPendingHasNoJobId(): void
    {
        // Defensive: pending-отчёт может существовать без jobId (manual-triggered
        // загрузка через OzonDebugFetcher, cleanup-cron'ом). Финализировать нечего,
        // tryFinalize не должен вызываться даже при пустом отчёте.
        $pending = $this->makePendingReport(jobId: null);
        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $this->client->method('downloadAndConvertReport')->willReturn([
            'downloads' => [$this->makeDownload('raw-bytes-empty')],
            'resultByDate' => [],
        ]);

        $this->em->expects(self::once())->method('flush');
        $this->pendingRepo->expects(self::once())
            ->method('markFinalized')
            ->with(self::COMPANY_ID, self::OZON_UUID, OzonAdPendingReportState::OK, null)
            ->willReturn(1);

        $this->bus->expects(self::never())->method('dispatch');
        $this->finalizer->expects(self::never())->method('tryFinalize');

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    public function testHappyPathDoesNotCallFinalizer(): void
    {
        // Non-empty report → per-document handler'ы (ProcessAdRawDocumentHandler)
        // сами позовут finalizer после обработки каждого документа, duplicate
        // call здесь нарушил бы single-source-of-truth для счётчиков.
        $pending = $this->makePendingReport();
        $this->pendingRepo->method('findByIdAndCompany')->willReturn($pending);

        $this->client->method('downloadAndConvertReport')->willReturn([
            'downloads' => [$this->makeDownload('raw-bytes-1')],
            'resultByDate' => [
                '2026-04-01' => ['campaigns' => [[
                    'campaign_id' => 'c1',
                    'campaign_name' => 'C',
                    'rows' => [['sku' => 's', 'spend' => '1.00', 'views' => 1, 'clicks' => 1]],
                ]]],
            ],
        ]);

        $this->rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $this->storage->method('storeBytes')->willReturn([
            'storagePath' => 'p',
            'fileHash' => 'h',
            'sizeBytes' => 11,
            'mimeType' => 'text/csv',
        ]);
        $this->bus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );

        $this->finalizer->expects(self::never())->method('tryFinalize');

        $this->makeHandler()(new DownloadOzonAdReportMessage(
            companyId: self::COMPANY_ID,
            pendingReportId: self::PENDING_ID,
        ));
    }

    private function makeHandler(): DownloadOzonAdReportHandler
    {
        return new DownloadOzonAdReportHandler(
            $this->pendingRepo,
            $this->rawRepo,
            $this->client,
            $this->em,
            $this->bus,
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

        if (null !== $finalizedAt) {
            $finalizedProp = $ref->getProperty('finalizedAt');
            $finalizedProp->setAccessible(true);
            $finalizedProp->setValue($pending, $finalizedAt);

            $stateProp = $ref->getProperty('state');
            $stateProp->setAccessible(true);
            $stateProp->setValue($pending, OzonAdPendingReportState::OK);
        } else {
            $stateProp = $ref->getProperty('state');
            $stateProp->setAccessible(true);
            $stateProp->setValue($pending, OzonAdPendingReportState::OK);
        }

        return $pending;
    }

    private function makeDownload(string $rawBytes): OzonReportDownload
    {
        return new OzonReportDownload(
            rawBytes: $rawBytes,
            csvContent: 'date;sku;spend;views;clicks',
            csvParts: ['date;sku;spend;views;clicks'],
            wasZip: false,
            sizeBytes: strlen($rawBytes),
            sha256: hash('sha256', $rawBytes),
            reportUuid: self::OZON_UUID,
            filesInZip: 0,
        );
    }
}
