<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\MessageHandler\FetchOzonAdStatisticsHandler;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\State\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

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
        $jobRepo->expects(self::never())->method('incrementFailedDays');

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
        $jobRepo->expects(self::never())->method('incrementFailedDays');

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

        $hub = $this->createMock(HubInterface::class);
        $logger = new class(new NullLogger(), $hub) extends AppLogger {
            public bool $infoLogged = false;

            public function info(string $message, array $context = []): void
            {
                if (str_contains($message, 'chunk already marked completed')) {
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
            $em,
            $messageBus,
            $logger,
        );

        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertTrue($logger->infoLogged, 'info-лог "chunk already marked completed" должен быть записан');
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
        $jobRepo->expects(self::never())->method('incrementFailedDays');
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
        $jobRepo->expects(self::never())->method('incrementFailedDays');
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
        $jobRepo->expects(self::never())->method('incrementFailedDays');

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
        $jobRepo->expects(self::never())->method('incrementFailedDays');

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
        $jobRepo->expects(self::never())->method('incrementFailedDays');
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
        $jobRepo->expects(self::never())->method('incrementFailedDays');
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
     * Невалидный UTF-8 в одном дне → день попадает в failedDays, остальные
     * загружаются как обычно. Чанк физически отработан → markChunkCompleted вызван.
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
        $jobRepo->expects(self::once())
            ->method('incrementFailedDays')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, 1)
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

    private function createHandler(
        OzonAdClient $ozonClient,
        AdRawDocumentRepository $rawRepo,
        AdLoadJobRepository $jobRepo,
        AdChunkProgressRepositoryInterface $chunkProgressRepo,
        EntityManagerInterface $em,
        MessageBusInterface $messageBus,
    ): FetchOzonAdStatisticsHandler {
        return new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $em,
            $messageBus,
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
        );
    }
}
