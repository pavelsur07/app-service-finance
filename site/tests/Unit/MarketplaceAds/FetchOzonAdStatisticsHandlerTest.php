<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\MessageHandler\FetchOzonAdStatisticsHandler;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\State\HubInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

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

    public function testHappyPathCallsRequestStatisticsOnlyAndMarkChunkCompleted(): void
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
            ->method('requestStatisticsOnly')
            ->with(
                self::COMPANY_ID,
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-01' === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-03' === $d->format('Y-m-d')),
                self::JOB_ID,
            )
            ->willReturn(['uuid-1', 'uuid-2']);

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

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo);
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
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
        $ozonClient->method('requestStatisticsOnly')->willReturn(['uuid-1']);

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->willReturn(false);

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

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
        $ozonClient->method('requestStatisticsOnly')
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

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo);

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
        $ozonClient->method('requestStatisticsOnly')
            ->willThrowException(new \RuntimeException('Ozon 502 Bad Gateway'));

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);
        $pendingRepo->expects(self::never())->method('markFinalized');

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo);

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
        $ozonClient->expects(self::never())->method('requestStatisticsOnly');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

        self::assertTrue($job->getStatus()->isTerminal(), 'sanity: FAILED is terminal');

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo);
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
        $ozonClient->expects(self::never())->method('requestStatisticsOnly');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $pendingRepo = $this->createMock(OzonAdPendingReportRepository::class);

        $handler = $this->createHandler($ozonClient, $jobRepo, $chunkProgressRepo, $pendingRepo);
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
    ): FetchOzonAdStatisticsHandler {
        return new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $jobRepo,
            $chunkProgressRepo,
            $pendingRepo,
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
            new NullLogger(),
        );
    }
}
