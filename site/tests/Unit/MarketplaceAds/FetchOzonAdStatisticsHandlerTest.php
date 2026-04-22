<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Message\RequestOzonAdBatchMessage;
use App\MarketplaceAds\MessageHandler\FetchOzonAdStatisticsHandler;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use DG\BypassFinals;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\State\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

// AdLoadJobFinalizer — final readonly, нужен BypassFinals для createMock.
BypassFinals::allowPaths([
    '*/src/MarketplaceAds/Application/Service/AdLoadJobFinalizer.php',
]);

/**
 * Unit-тесты {@see FetchOzonAdStatisticsHandler} для async-poll flow (step 5).
 *
 * Handler теперь делает ТОЛЬКО request-half работы: POST /statistics через
 * OzonAdClient::requestStatisticsOnly() + markChunkCompleted. Вся
 * download/upsert/dispatch-цепочка перенесена в DownloadOzonAdReportHandler
 * и поднимается через poll-cron.
 *
 * Покрываемые инварианты:
 *  1. Happy-path: requestStatisticsOnly вызывается с корректными аргументами,
 *     markChunkCompleted вызывается с датами чанка и возвращает true.
 *  2. Duplicate (markChunkCompleted → false): info-лог, counters не инкрементируются.
 *  3. Permanent error (OzonPermanentApiException): abandon in-flight + markFailed + Unrecoverable.
 *  4. Transient error (RuntimeException): markChunkCompleted НЕ вызван, exception propagate.
 *  5. Терминальный job: requestStatisticsOnly не вызывается.
 *  6. Job не найден: requestStatisticsOnly не вызывается.
 *  7. InvalidArgumentException от клиента: markFailed + Unrecoverable.
 *  8. Невалидный формат даты в Message: markFailed + Unrecoverable.
 *  9. Календарно-невалидная дата: markFailed + Unrecoverable.
 */
final class FetchOzonAdStatisticsHandlerTest extends TestCase
{
    private const JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const DATE_FROM = '2026-03-01';
    private const DATE_TO = '2026-03-03';

    public function testHappyPathDispatchesOneBatchMessagePerBatchAndMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(self::JOB_ID, self::COMPANY_ID, 3)
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::once())
            ->method('prepareStatisticsBatches')
            ->with(
                self::COMPANY_ID,
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-01' === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-03' === $d->format('Y-m-d')),
            )
            ->willReturn([['c1', 'c2'], ['c3']]);
        $ozonClient->expects(self::never())->method('requestStatisticsOnly');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-01' === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-03' === $d->format('Y-m-d')),
            )
            ->willReturn(true);

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);
        $pendingRepo->expects(self::never())->method('markFinalized');

        $finalizer = $this->createMock(AdLoadJobFinalizer::class);
        $finalizer->expects(self::never())->method('tryFinalize');

        $dispatched = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $m) use (&$dispatched): Envelope {
                $dispatched[] = $m;

                return new Envelope($m);
            });

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo, $finalizer, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertCount(2, $dispatched);
        foreach ($dispatched as $i => $m) {
            self::assertInstanceOf(RequestOzonAdBatchMessage::class, $m);
            self::assertSame(self::JOB_ID, $m->jobId);
            self::assertSame(self::COMPANY_ID, $m->companyId);
            self::assertSame(self::DATE_FROM, $m->dateFrom);
            self::assertSame(self::DATE_TO, $m->dateTo);
            self::assertSame($i, $m->batchIndex);
            self::assertSame(2, $m->batchTotal);
        }
        self::assertSame(['c1', 'c2'], $dispatched[0]->campaignIds);
        self::assertSame(['c3'], $dispatched[1]->campaignIds);
    }

    public function testZeroBatchesStillCallsMarkChunkCompletedAndFinalizer(): void
    {
        // Zero-batches scenario: prepareStatisticsBatches возвращает [] (нет
        // активных SKU-кампаний или все отфильтрованы recency-cutoff'ом). Ни
        // одного RequestOzonAdBatchMessage не диспатчим, ни одного pending-
        // отчёта не создастся → poll-cron'у нечего опрашивать → без прямого
        // tryFinalize job навсегда застрял бы в RUNNING.
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(self::JOB_ID, self::COMPANY_ID, 3)
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::once())
            ->method('prepareStatisticsBatches')
            ->willReturn([]);

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->willReturn(true);

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);
        $pendingRepo->expects(self::never())->method('markFinalized');

        // KEY ASSERTION: the fix. tryFinalize MUST be called in the zero-batches
        // branch, otherwise jobs with no active campaigns would hang in RUNNING.
        $finalizer = $this->createMock(AdLoadJobFinalizer::class);
        $finalizer->expects(self::once())
            ->method('tryFinalize')
            ->with(self::JOB_ID, self::COMPANY_ID);

        // Regression guard: zero-batches ветка не диспатчит ни одного
        // RequestOzonAdBatchMessage'а.
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo, $finalizer, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testZeroBatchesOnDuplicateChunkStillCallsFinalizer(): void
    {
        // Инвариант: zero-batches ветка вызывает tryFinalize независимо от
        // результата markChunkCompleted. tryFinalize идемпотентен (SQL-level
        // guard на статус RUNNING), и повторный вызов при Messenger-retry —
        // безопасный no-op. Этот тест фиксирует контракт «zero-batches всегда
        // пытается финализировать» и ловит регрессию, если кто-то решит
        // привязать вызов к `$marked === true`.
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        // duplicate-chunk → incrementLoadedDays не вызывается (chunk уже учтён
        // в первичном проходе).
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('prepareStatisticsBatches')->willReturn([]);

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->willReturn(false);

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

        $finalizer = $this->createMock(AdLoadJobFinalizer::class);
        $finalizer->expects(self::once())
            ->method('tryFinalize')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID);

        // Zero batches → никаких dispatch'ей.
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo, $finalizer, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testDuplicateChunkSkipsCounters(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('prepareStatisticsBatches')->willReturn([['c1']]);

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->willReturn(false);

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

        // Non-empty batches path: finalizer не должен вызываться — за финализацию
        // отвечает DownloadOzonAdReportHandler через per-document trigger.
        $finalizer = $this->createMock(AdLoadJobFinalizer::class);
        $finalizer->expects(self::never())->method('tryFinalize');

        // На дубликате мы всё равно диспатчим батчи — matchResumableReport
        // внутри RequestOzonAdBatchHandler абсорбирует повторный POST.
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $marketplaceAdsLogger = new class extends NullLogger {
            public bool $infoLogged = false;

            public function info(string|\Stringable $message, array $context = []): void
            {
                if (str_contains((string) $message, 'chunk already marked completed')) {
                    $this->infoLogged = true;
                }
                parent::info($message, $context);
            }
        };

        $handler = new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $jobRepo,
            $chunkProgressRepo,
            $pendingRepo,
            $finalizer,
            $messageBus,
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
            $marketplaceAdsLogger,
        );

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertTrue($marketplaceAdsLogger->infoLogged, 'info-лог "chunk already marked completed" должен быть записан');
    }

    public function testPermanentErrorAbandonsInFlightAndMarksFailed(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, self::stringContains('Ozon API permanent failure'))
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('prepareStatisticsBatches')
            ->willThrowException(new OzonPermanentApiException('403 — нет скоупа «Продвижение»'));

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pending = new OzonAdPendingReport(
            companyId: self::COMPANY_ID,
            ozonUuid: 'abandoned-uuid',
            dateFrom: new \DateTimeImmutable(self::DATE_FROM),
            dateTo: new \DateTimeImmutable(self::DATE_TO),
            campaignIds: ['c1'],
            jobId: AdLoadJobBuilder::DEFAULT_ID,
        );

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);
        $pendingRepo->expects(self::once())
            ->method('findInFlightByJob')
            ->with(self::COMPANY_ID, AdLoadJobBuilder::DEFAULT_ID)
            ->willReturn([$pending]);
        $pendingRepo->expects(self::once())
            ->method('markFinalized')
            ->with(
                self::COMPANY_ID,
                'abandoned-uuid',
                OzonAdPendingReportState::ABANDONED,
                self::stringContains('Job failed permanently'),
            )
            ->willReturn(1);

        // Permanent error path: markFailed сам переводит job в FAILED,
        // дополнительный tryFinalize не нужен.
        $finalizer = $this->createMock(AdLoadJobFinalizer::class);
        $finalizer->expects(self::never())->method('tryFinalize');

        // prepareStatisticsBatches кинул exception до цикла dispatch —
        // ни одного RequestOzonAdBatchMessage'а быть не должно.
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo, $finalizer, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Ozon permanent failure');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testTransientErrorDoesNotCallMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('prepareStatisticsBatches')
            ->willThrowException(new \RuntimeException('Ozon 502 Bad Gateway'));

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);
        $pendingRepo->expects(self::never())->method('markFinalized');

        // Transient error: Messenger повторит message, finalize пока не нужен.
        $finalizer = $this->createMock(AdLoadJobFinalizer::class);
        $finalizer->expects(self::never())->method('tryFinalize');

        // Transient на prep-этапе → ни одного dispatch'а батчей.
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo, $finalizer, $messageBus);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ozon 502 Bad Gateway');

        try {
            $handler(new FetchOzonAdStatisticsMessage(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::DATE_FROM,
                self::DATE_TO,
            ));
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(
                UnrecoverableMessageHandlingException::class,
                $e,
                'transient-ошибки не должны заворачиваться в Unrecoverable',
            );
            throw $e;
        }
    }

    public function testTerminalJobSkipsOzonCall(): void
    {
        $job = AdLoadJobBuilder::aJob()->asFailed('предыдущая ошибка')->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('prepareStatisticsBatches');
        $ozonClient->expects(self::never())->method('requestStatisticsOnly');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

        self::assertTrue($job->getStatus()->isTerminal(), 'sanity: FAILED is terminal');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo, null, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testJobNotFoundSkipsOzonCall(): void
    {
        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn(null);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('prepareStatisticsBatches');
        $ozonClient->expects(self::never())->method('requestStatisticsOnly');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo, null, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testInvalidArgumentFromClientMarksFailedAndThrowsUnrecoverable(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::stringContains('Invalid date range'),
            )
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('requestStatisticsOnly')
            ->willThrowException(new \InvalidArgumentException('Диапазон превышает 62 дня'));

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('invalid date range');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testInvalidDateFormatInMessageMarksFailedAndThrowsUnrecoverable(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::stringContains('Invalid date format'),
            )
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('requestStatisticsOnly');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('invalid date format');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            'not-a-date',
            self::DATE_TO,
        ));
    }

    public function testCalendarInvalidDateInMessageMarksFailedAndThrowsUnrecoverable(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::stringContains('2026-02-31'),
            )
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('requestStatisticsOnly');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('2026-02-31');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            '2026-02-31',
            self::DATE_TO,
        ));
    }

    private function createHandler(
        OzonAdClient $ozonClient,
        AdLoadJobRepository $jobRepo,
        AdChunkProgressRepositoryInterface $chunkProgressRepo,
        OzonAdPendingReportRepository $pendingRepo,
        ?AdLoadJobFinalizer $finalizer = null,
        ?MessageBusInterface $messageBus = null,
    ): FetchOzonAdStatisticsHandler {
        if (null === $messageBus) {
            $messageBus = $this->createMock(MessageBusInterface::class);
            $messageBus->method('dispatch')
                ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        }

        return new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $jobRepo,
            $chunkProgressRepo,
            $pendingRepo,
            $finalizer ?? $this->createMock(AdLoadJobFinalizer::class),
            $messageBus,
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
            new NullLogger(),
        );
    }
}
