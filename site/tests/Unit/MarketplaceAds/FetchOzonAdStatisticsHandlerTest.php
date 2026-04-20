<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Exception\OzonStatisticsQueueFullException;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonReportDownload;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\MessageHandler\FetchOzonAdStatisticsHandler;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\AppLogger;
use App\Shared\Service\Storage\StorageService;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use DG\BypassFinals;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\State\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

// Bootstrap pins BypassFinals to an allowlist; finalizer is final readonly — extend
// the allowlist so PHPUnit can double it. StorageService тоже final — нужен для bronze-тестов.
BypassFinals::allowPaths([
    '*/src/MarketplaceAds/Application/Service/AdLoadJobFinalizer.php',
    '*/src/Shared/Service/Storage/StorageService.php',
]);

/**
 * Unit-тесты FetchOzonAdStatisticsHandler.
 *
 * Покрываемые инварианты:
 *  1. Happy-path: markChunkCompleted вызван с корректными датами, вернул true.
 *  2. Duplicate (markChunkCompleted → false): info-лог, counters не инкрементируются, handler OK.
 *  3. Permanent error (OzonPermanentApiException): markChunkCompleted НЕ вызван.
 *  4. Transient error (RuntimeException): markChunkCompleted НЕ вызван.
 *  5. Пустой результат Ozon: markChunkCompleted всё равно вызван.
 *  6. Терминальный job: Ozon не вызывается, все побочные эффекты отсутствуют.
 *  7. Job не найден: Ozon не вызывается, все побочные эффекты отсутствуют.
 *  8. InvalidArgumentException от клиента: markFailed + UnrecoverableMessageHandlingException.
 *  9. flush() ОБЯЗАН завершиться до первого dispatch() — race condition guard.
 * 10. Ozon вернул меньше дней: loaded_days считается по chunkDays, а не count($documents).
 * 11. json_encode() === false для одного дня: день пропускается, остальные загружаются.
 * 12. Невалидный формат даты в Message: markFailed + UnrecoverableMessageHandlingException.
 * 13. Календарно-невалидная дата (2026-02-31): markFailed + UnrecoverableMessageHandlingException.
 * 14. Happy-path: AdLoadJobFinalizer::tryFinalize вызывается ПОСЛЕ markChunkCompleted —
 *     без этого job с нулём документов от Ozon навечно залип бы в RUNNING.
 */
final class FetchOzonAdStatisticsHandlerTest extends TestCase
{
    private const JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const DATE_FROM = '2026-03-01';
    private const DATE_TO = '2026-03-03';

    /**
     * Сценарий 1: happy-path.
     *
     * Ozon вернул 3 документа, markChunkCompleted вызван ровно один раз с корректными
     * датами чанка и вернул true (первая фиксация).
     */
    public function testHappyPathMarkChunkCompletedCalledWithCorrectDatesAndReturnsTrue(): void
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
            ->method('fetchAdStatisticsRange')
            ->willReturn([
                '2026-03-01' => ['rows' => [['spend' => 100]]],
                '2026-03-02' => ['rows' => [['spend' => 200]]],
                '2026-03-03' => ['rows' => [['spend' => 300]]],
            ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $rawRepo->expects(self::exactly(3))->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

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

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 2: duplicate (markChunkCompleted → false).
     *
     * При Messenger retry тот же чанк обрабатывается повторно. markChunkCompleted
     * возвращает false. Handler пишет info-лог, счётчики НЕ инкрементируются,
     * завершается без ошибки.
     */
    public function testDuplicateChunkLogsInfoAndHandlerCompletesOk(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        // Duplicate chunk: counters must NOT be incremented to avoid double-counting
        // on Messenger retry — markChunkCompleted returning false is the guard.
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 100]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $rawRepo->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->willReturn(false);

        $logger = new AppLogger(new NullLogger(), $this->createMock(HubInterface::class));

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
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $this->createMock(AdLoadJobFinalizer::class),
            $em,
            $messageBus,
            $this->createMock(StorageService::class),
            $logger,
            $marketplaceAdsLogger,
        );

        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertTrue($marketplaceAdsLogger->infoLogged, 'info-лог "chunk already marked completed" должен быть записан');
    }

    /**
     * Сценарий 3: permanent error (OzonPermanentApiException).
     *
     * markChunkCompleted НЕ вызывается: permanent ошибка выбрасывает
     * UnrecoverableMessageHandlingException до happy-path.
     */
    public function testPermanentErrorDoesNotCallMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, self::stringContains('Ozon API permanent failure'))
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')
            ->willThrowException(new OzonPermanentApiException('403 — нет скоупа «Продвижение»'));

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Ozon permanent failure');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 3.5: OzonStatisticsQueueFullException — очередь отчётов Ozon перегружена.
     *
     * NOT_STARTED > 5 минут → client выбросил typed exception. Handler должен:
     *   - вызвать markFailed с понятным пользовательским сообщением;
     *   - залогировать warning с reportUuid и waitedSeconds;
     *   - обернуть в UnrecoverableMessageHandlingException (не retry немедленно —
     *     повтор имеет смысл только на следующий день, когда очередь Ozon рассосётся).
     * markChunkCompleted НЕ вызывается.
     */
    public function testQueueFullExceptionMarksFailedAndThrowsUnrecoverable(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::stringContains('Очередь отчётов Ozon Performance перегружена'),
            )
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $queueFull = new OzonStatisticsQueueFullException('test-uuid-queue-full', 305);
        $ozonClient->method('fetchAdStatisticsRange')->willThrowException($queueFull);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $marketplaceAdsLogger = new class extends NullLogger {
            /** @var array<string, mixed>|null */
            public ?array $warningContext = null;

            public function warning(string|\Stringable $message, array $context = []): void
            {
                if (str_contains((string) $message, 'Ozon statistics queue full')) {
                    $this->warningContext = $context;
                }
                parent::warning($message, $context);
            }
        };

        $handler = new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $this->createMock(AdLoadJobFinalizer::class),
            $em,
            $messageBus,
            $this->createMock(StorageService::class),
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
            $marketplaceAdsLogger,
        );

        try {
            $handler(new FetchOzonAdStatisticsMessage(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::DATE_FROM,
                self::DATE_TO,
            ));
            self::fail('expected UnrecoverableMessageHandlingException');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertSame(
                $queueFull,
                $e->getPrevious(),
                'UnrecoverableMessageHandlingException должен содержать оригинальный OzonStatisticsQueueFullException как previous',
            );
        }

        self::assertNotNull($marketplaceAdsLogger->warningContext, 'warning "Ozon statistics queue full" должен быть залогирован');
        self::assertSame('test-uuid-queue-full', $marketplaceAdsLogger->warningContext['reportUuid']);
        self::assertSame(305, $marketplaceAdsLogger->warningContext['waitedSeconds']);
    }

    /**
     * Сценарий 4: transient error (RuntimeException).
     *
     * markChunkCompleted НЕ вызывается: transient ошибка выбрасывается
     * «голой» (Messenger ретраит).
     */
    public function testTransientErrorDoesNotCallMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')
            ->willThrowException(new \RuntimeException('Ozon 502 Bad Gateway'));

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);

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
                'transient-ошибки не должны заворачиваться в UnrecoverableMessageHandlingException',
            );
            throw $e;
        }
    }

    /**
     * Сценарий 5: пустой результат Ozon.
     *
     * Ozon вернул пустой массив (ни одной кампании за чанк).
     * markChunkCompleted всё равно должен быть вызван: чанк отработал,
     * пустой ответ — легитимный результат, а не ошибка.
     */
    public function testEmptyOzonResultStillCallsMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, 3)
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->with(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn(true);

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 6: терминальный job.
     *
     * Задание уже в FAILED → handler возвращает сразу. API Ozon, flush,
     * dispatch и markChunkCompleted не должны вызываться.
     */
    public function testTerminalJobSkipsOzonCall(): void
    {
        $job = AdLoadJobBuilder::aJob()->asFailed('предыдущая ошибка')->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('fetchAdStatisticsRange');

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        self::assertTrue($job->getStatus()->isTerminal(), 'sanity check: AdLoadJobStatus::FAILED is terminal');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 7: job не найден.
     *
     * findByIdAndCompany() вернул null → handler возвращает сразу без
     * каких-либо побочных эффектов.
     */
    public function testJobNotFoundSkipsOzonCall(): void
    {
        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn(null);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('fetchAdStatisticsRange');

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 8: \InvalidArgumentException от OzonAdClient.
     *
     * Диапазон > 62 дней / from > to — permanent баг вызывающего кода.
     * markFailed вызывается, бросается UnrecoverableMessageHandlingException.
     * markChunkCompleted не вызывается.
     */
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
        $ozonClient->method('fetchAdStatisticsRange')
            ->willThrowException(new \InvalidArgumentException('Диапазон превышает 62 дня'));

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('invalid date range');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 9: flush() ОБЯЗАН завершиться до первого dispatch().
     *
     * Нарушение порядка приводит к race condition: ProcessAdRawDocumentHandler
     * увидит message, а документа в БД ещё нет.
     */
    public function testDispatchHappensStrictlyAfterFlush(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            self::DATE_FROM => ['rows' => [['spend' => 1]]],
            '2026-03-02' => ['rows' => [['spend' => 2]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);

        $flushed = false;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(static function () use (&$flushed): void {
            $flushed = true;
        });

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $m) use (&$flushed): Envelope {
            self::assertTrue(
                $flushed,
                'dispatch(ProcessAdRawDocumentMessage) не должен запускаться раньше EntityManager::flush()',
            );

            return new Envelope($m);
        });

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->method('markChunkCompleted')->willReturn(true);

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            '2026-03-02',
        ));
    }

    /**
     * Сценарий 10: Ozon вернул меньше дней, чем запросили.
     *
     * P1 regression: дни без кампаний отсутствуют в ответе Ozon, но должны
     * считаться загруженными. loaded_days инкрементируется по chunkDays, а
     * НЕ по count($documents), иначе прогресс никогда не дойдёт до 100%.
     */
    public function testOzonReturnsFewerDaysThanChunkStillCountsCoverageAsLoaded(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, 3) // chunkDays=3, не count($documents)=1
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            // Ozon вернул только 1 день из 3 запрошенных.
            '2026-03-02' => ['rows' => [['spend' => 200]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $rawRepo->expects(self::once())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->willReturn(true);

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 11: json_encode() === false для одного дня.
     *
     * Невалидный UTF-8 в одном дне пропускается, остальные загружаются как обычно.
     * Чанк физически отработан → markChunkCompleted вызван.
     * loaded_days инкрементируется только на число успешно сохранённых дней
     * (chunkDays - skippedDays).
     */
    public function testJsonEncodeFailureSkipsOnlyThatDay(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, 2)
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('markFailed');

        // Невалидный UTF-8 во втором дне — json_encode() без JSON_THROW_ON_ERROR вернёт false.
        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 100]]],
            '2026-03-02' => ['rows' => [['broken' => "\xB1\x31"]]],
            '2026-03-03' => ['rows' => [['spend' => 300]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        // save() зовётся только для двух валидных дней — второй день пропущен ДО save.
        $rawRepo->expects(self::exactly(2))->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->willReturn(true);

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 12: невалидный формат даты в Message ('not-a-date').
     *
     * createFromFormat() вернёт false → markFailed + UnrecoverableMessageHandlingException.
     * markChunkCompleted не вызывается.
     */
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
        $ozonClient->expects(self::never())->method('fetchAdStatisticsRange');

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('invalid date format');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            'not-a-date',
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 13: календарно-невалидная дата (2026-02-31).
     *
     * P2 regression: createFromFormat('!Y-m-d', '2026-02-31') НЕ возвращает false —
     * тихо нормализует в 2026-03-03. Без round-trip сравнения handler грузил бы
     * не тот диапазон. markFailed + UnrecoverableMessageHandlingException.
     * markChunkCompleted не вызывается.
     */
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
        $ozonClient->expects(self::never())->method('fetchAdStatisticsRange');

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('2026-02-31');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            '2026-02-31',
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 14: happy-path вызывает AdLoadJobFinalizer::tryFinalize.
     *
     * Без этого вызова задание, по которому Ozon вернул 0 документов за весь
     * период, зависнет в RUNNING навечно: ProcessAdRawDocumentHandler не
     * запустится, и триггера финализации не будет.
     */
    public function testHappyPathCallsFinalizerAfterMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);

        $markedChunk = false;
        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->method('markChunkCompleted')
            ->willReturnCallback(static function () use (&$markedChunk): bool {
                $markedChunk = true;

                return true;
            });

        $finalizer = $this->createMock(AdLoadJobFinalizer::class);
        $finalizer->expects(self::once())
            ->method('tryFinalize')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturnCallback(static function () use (&$markedChunk): void {
                self::assertTrue(
                    $markedChunk,
                    'tryFinalize должен вызываться ПОСЛЕ markChunkCompleted',
                );
            });

        $handler = $this->createHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $em,
            $messageBus,
            $finalizer,
        );
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Bronze-сценарий 1: happy-path.
     *
     * OzonAdClient вернул один download, handler обязан вызвать StorageService::storeBytes
     * с путём `companies/{cid}/marketplace-ads/ozon/bronze/{dateFrom}/{uuid}.{zip|csv}`
     * и прописать результат в setFileStorage() каждого AdRawDocument чанка.
     */
    public function testHappyPathSavesBronzeFileAndSetsStoragePathOnDocument(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);

        $download = new OzonReportDownload(
            rawBytes: "PK\x03\x04raw-zip-bytes",
            csvContent: "date,campaign_id,sku,spend\n2026-03-01,1,SKU,100",
            wasZip: true,
            sizeBytes: 16,
            sha256: str_repeat('a', 64),
            reportUuid: 'report-uuid-001',
            filesInZip: 1,
        );

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 100]]],
        ]);
        $ozonClient->method('getLastChunkDownloads')->willReturn([$download]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $savedDoc = null;
        $rawRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (AdRawDocument $doc) use (&$savedDoc): void {
                $savedDoc = $doc;
            });

        $storageService = $this->createMock(StorageService::class);
        $storageService->expects(self::once())
            ->method('storeBytes')
            ->with(
                "PK\x03\x04raw-zip-bytes",
                sprintf(
                    'companies/%s/marketplace-ads/ozon/bronze/%s/report-uuid-001.zip',
                    self::COMPANY_ID,
                    self::DATE_FROM,
                ),
            )
            ->willReturn([
                'storagePath' => 'companies/'.self::COMPANY_ID.'/marketplace-ads/ozon/bronze/'.self::DATE_FROM.'/report-uuid-001.zip',
                'fileHash' => str_repeat('b', 64),
                'sizeBytes' => 16,
                'mimeType' => 'application/zip',
            ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->method('markChunkCompleted')->willReturn(true);

        $handler = $this->createHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $em,
            $messageBus,
            null,
            $storageService,
        );
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertNotNull($savedDoc);
        self::assertSame(
            'companies/'.self::COMPANY_ID.'/marketplace-ads/ozon/bronze/'.self::DATE_FROM.'/report-uuid-001.zip',
            $savedDoc->getStoragePath(),
        );
        self::assertSame(str_repeat('b', 64), $savedDoc->getFileHash());
        self::assertSame(16, $savedDoc->getFileSizeBytes());
    }

    /**
     * Bronze-сценарий 2: несколько документов одного чанка ссылаются на ОДИН bronze-файл.
     *
     * Принцип «1 bronze = 1 chunk»: storeBytes должен быть вызван ровно один раз,
     * а setFileStorage каждого из трёх AdRawDocument получает одинаковые
     * storage_path/file_hash/size (первый download батча).
     */
    public function testMultipleDocumentsInChunkShareSameBronzeFile(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);

        $download = new OzonReportDownload(
            rawBytes: 'csv-raw-bytes',
            csvContent: 'csv-raw-bytes',
            wasZip: false,
            sizeBytes: 13,
            sha256: str_repeat('c', 64),
            reportUuid: 'shared-uuid',
            filesInZip: 0,
        );

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 100]]],
            '2026-03-02' => ['rows' => [['spend' => 200]]],
            '2026-03-03' => ['rows' => [['spend' => 300]]],
        ]);
        $ozonClient->method('getLastChunkDownloads')->willReturn([$download]);

        $savedDocs = [];
        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $rawRepo->expects(self::exactly(3))
            ->method('save')
            ->willReturnCallback(static function (AdRawDocument $doc) use (&$savedDocs): void {
                $savedDocs[] = $doc;
            });

        $storageService = $this->createMock(StorageService::class);
        // Критически важно: storeBytes вызывается РОВНО ОДИН раз для всего чанка,
        // даже при трёх документах — иначе мы бы дублировали bronze-файлы.
        $storageService->expects(self::once())
            ->method('storeBytes')
            ->willReturn([
                'storagePath' => 'companies/'.self::COMPANY_ID.'/marketplace-ads/ozon/bronze/'.self::DATE_FROM.'/shared-uuid.csv',
                'fileHash' => str_repeat('d', 64),
                'sizeBytes' => 13,
                'mimeType' => 'text/csv',
            ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->method('markChunkCompleted')->willReturn(true);

        $handler = $this->createHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $em,
            $messageBus,
            null,
            $storageService,
        );
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertCount(3, $savedDocs);
        $expectedPath = 'companies/'.self::COMPANY_ID.'/marketplace-ads/ozon/bronze/'.self::DATE_FROM.'/shared-uuid.csv';
        foreach ($savedDocs as $doc) {
            self::assertSame($expectedPath, $doc->getStoragePath(), 'все документы чанка должны ссылаться на один bronze-файл');
            self::assertSame(str_repeat('d', 64), $doc->getFileHash());
            self::assertSame(13, $doc->getFileSizeBytes());
        }
    }

    /**
     * Bronze-сценарий 3: формат пути.
     *
     * Проверяет, что handler строит путь вида
     * `companies/{companyId}/marketplace-ads/ozon/bronze/{yyyy-mm-dd}/{uuid}.{zip|csv}`
     * и что {yyyy-mm-dd} — это именно dateFrom чанка (не dateTo и не текущая дата).
     */
    public function testBronzePathContainsCompanyIdAndDate(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);

        $download = new OzonReportDownload(
            rawBytes: "PK\x03\x04abc",
            csvContent: 'unpacked',
            wasZip: true,
            sizeBytes: 7,
            sha256: str_repeat('e', 64),
            reportUuid: '0197f1e2-3456-7890-abcd-ef0123456789',
            filesInZip: 1,
        );

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            self::DATE_FROM => ['rows' => []],
        ]);
        $ozonClient->method('getLastChunkDownloads')->willReturn([$download]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $rawRepo->method('save');

        $capturedPath = null;
        $storageService = $this->createMock(StorageService::class);
        $storageService->expects(self::once())
            ->method('storeBytes')
            ->willReturnCallback(static function (string $bytes, string $path) use (&$capturedPath): array {
                $capturedPath = $path;

                return [
                    'storagePath' => $path,
                    'fileHash' => str_repeat('f', 64),
                    'sizeBytes' => strlen($bytes),
                    'mimeType' => 'application/zip',
                ];
            });

        $em = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->method('markChunkCompleted')->willReturn(true);

        $handler = $this->createHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $em,
            $messageBus,
            null,
            $storageService,
        );
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertSame(
            sprintf(
                'companies/%s/marketplace-ads/ozon/bronze/%s/%s.zip',
                self::COMPANY_ID,
                self::DATE_FROM,
                '0197f1e2-3456-7890-abcd-ef0123456789',
            ),
            $capturedPath,
        );
    }

    /**
     * Bronze-сценарий 4: multi-batch чанк (campaigns > 10 → несколько Ozon-отчётов).
     *
     * При батчинге Ozon возвращает несколько независимых ZIP-файлов, и сохранять
     * только первый было бы нечестно: file_hash на всех AdRawDocument'ах чанка
     * не соответствовал бы полным данным. В таком случае storeBytes НЕ вызывается
     * вовсе, документы остаются со storage_path=null, а в marketplace_ads-канал
     * пишется warning с batchCount.
     */
    public function testMultiBatchChunkDoesNotSaveBronzeButLogsWarning(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);

        // Два download'а — эмулируют >10 кампаний, разнесённых по двум батчам
        // внутри одного вызова fetchAdStatisticsRange().
        $download1 = new OzonReportDownload(
            rawBytes: "PK\x03\x04batch-1",
            csvContent: 'csv-batch-1',
            wasZip: true,
            sizeBytes: 10,
            sha256: str_repeat('a', 64),
            reportUuid: 'uuid-batch-1',
            filesInZip: 1,
        );
        $download2 = new OzonReportDownload(
            rawBytes: "PK\x03\x04batch-2",
            csvContent: 'csv-batch-2',
            wasZip: true,
            sizeBytes: 10,
            sha256: str_repeat('b', 64),
            reportUuid: 'uuid-batch-2',
            filesInZip: 1,
        );

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 100]]],
            '2026-03-02' => ['rows' => [['spend' => 200]]],
        ]);
        $ozonClient->method('getLastChunkDownloads')->willReturn([$download1, $download2]);

        $savedDocs = [];
        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $rawRepo->method('save')->willReturnCallback(static function (AdRawDocument $doc) use (&$savedDocs): void {
            $savedDocs[] = $doc;
        });

        // Критический инвариант: при multi-batch бронза НЕ пишется на диск.
        $storageService = $this->createMock(StorageService::class);
        $storageService->expects(self::never())->method('storeBytes');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->method('markChunkCompleted')->willReturn(true);

        $marketplaceAdsLogger = new class extends NullLogger {
            /** @var array<string, mixed>|null */
            public ?array $warningContext = null;

            public function warning(string|\Stringable $message, array $context = []): void
            {
                if (str_contains((string) $message, 'Multi-batch chunk')) {
                    $this->warningContext = $context;
                }
                parent::warning($message, $context);
            }
        };

        $handler = new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $this->createMock(AdLoadJobFinalizer::class),
            $em,
            $messageBus,
            $storageService,
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
            $marketplaceAdsLogger,
        );

        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertNotNull($marketplaceAdsLogger->warningContext, 'warning "Multi-batch chunk" должен быть залогирован');
        self::assertSame(self::JOB_ID, $marketplaceAdsLogger->warningContext['jobId']);
        self::assertSame(self::COMPANY_ID, $marketplaceAdsLogger->warningContext['companyId']);
        self::assertSame(self::DATE_FROM, $marketplaceAdsLogger->warningContext['dateFrom']);
        self::assertSame(self::DATE_TO, $marketplaceAdsLogger->warningContext['dateTo']);
        self::assertSame(2, $marketplaceAdsLogger->warningContext['batchCount']);

        self::assertNotEmpty($savedDocs, 'документы должны создаваться даже без bronze');
        foreach ($savedDocs as $doc) {
            self::assertNull($doc->getStoragePath(), 'storage_path обязан оставаться null при multi-batch');
            self::assertNull($doc->getFileHash(), 'file_hash обязан оставаться null при multi-batch');
            self::assertNull($doc->getFileSizeBytes(), 'file_size_bytes обязан оставаться null при multi-batch');
        }
    }

    private function createHandler(
        OzonAdClient $ozonClient,
        AdRawDocumentRepository $rawRepo,
        AdLoadJobRepository $jobRepo,
        AdChunkProgressRepositoryInterface $chunkProgressRepo,
        EntityManagerInterface $em,
        MessageBusInterface $messageBus,
        ?AdLoadJobFinalizer $finalizer = null,
        ?StorageService $storageService = null,
    ): FetchOzonAdStatisticsHandler {
        return new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $finalizer ?? $this->createMock(AdLoadJobFinalizer::class),
            $em,
            $messageBus,
            $storageService ?? $this->createMock(StorageService::class),
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
            new NullLogger(),
        );
    }
}
