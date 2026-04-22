<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Exception\OzonRateLimitException;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\RequestOzonAdBatchMessage;
use App\MarketplaceAds\MessageHandler\RequestOzonAdBatchHandler;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Unit-тесты {@see RequestOzonAdBatchHandler}: ровно один POST /statistics
 * на сообщение, с корректной обработкой terminal / missing / permanent /
 * transient / rate-limited сценариев.
 */
final class RequestOzonAdBatchHandlerTest extends TestCase
{
    private const JOB_ID = AdLoadJobBuilder::DEFAULT_ID;
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const DATE_FROM = '2026-03-01';
    private const DATE_TO = '2026-03-03';

    public function testHappyPathCallsRequestOneBatchOnce(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::once())
            ->method('requestOneBatch')
            ->with(
                self::COMPANY_ID,
                self::JOB_ID,
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-01' === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-03' === $d->format('Y-m-d')),
                ['c1', 'c2'],
            )
            ->willReturn('uuid-1');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, $bus, new NullLogger());
        $handler(new RequestOzonAdBatchMessage(
            companyId: self::COMPANY_ID,
            jobId: self::JOB_ID,
            dateFrom: self::DATE_FROM,
            dateTo: self::DATE_TO,
            campaignIds: ['c1', 'c2'],
            batchIndex: 0,
            batchTotal: 1,
        ));
    }

    public function testOversizedBatchThrowsUnrecoverable(): void
    {
        // Defense-in-depth: Ozon принимает не более 10 campaignIds. Orchestrator
        // бьёт через array_chunk(..., 10), но если кто-то задиспатчит сообщение
        // руками с 11+ id, Ozon ответит 4xx — такие ошибки транспортируются
        // через обычный \RuntimeException и иначе ретраились бы forever. Guard
        // в handler'е ловит это один раз и отправляет сообщение в dead-letter.
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('requestOneBatch');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $oversized = [];
        for ($i = 1; $i <= 11; ++$i) {
            $oversized[] = 'c'.$i;
        }

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, $bus, new NullLogger());

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('campaignIds size 11 out of [1..10]');

        $handler(new RequestOzonAdBatchMessage(
            companyId: self::COMPANY_ID,
            jobId: self::JOB_ID,
            dateFrom: self::DATE_FROM,
            dateTo: self::DATE_TO,
            campaignIds: $oversized,
            batchIndex: 0,
            batchTotal: 1,
        ));
    }

    public function testTransientRuntimeExceptionPropagatesWithoutMarkingFailed(): void
    {
        // Generic \RuntimeException (5xx, сеть, JSON) проходит наружу —
        // Messenger ретраит по расписанию async_ads, markFailed не
        // вызывается, Unrecoverable тоже, и reschedule через bus не
        // диспатчится (это только для 429-веточки).
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::once())
            ->method('requestOneBatch')
            ->willThrowException(new \RuntimeException('Ozon Performance: HTTP 500 internal'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, $bus, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        try {
            $handler(new RequestOzonAdBatchMessage(
                companyId: self::COMPANY_ID,
                jobId: self::JOB_ID,
                dateFrom: self::DATE_FROM,
                dateTo: self::DATE_TO,
                campaignIds: ['c1'],
                batchIndex: 0,
                batchTotal: 1,
            ));
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(
                UnrecoverableMessageHandlingException::class,
                $e,
                'transient-ошибки не должны заворачиваться в Unrecoverable',
            );
            self::assertNotInstanceOf(
                OzonRateLimitException::class,
                $e,
                'generic RuntimeException не должен интерпретироваться как 429',
            );
            throw $e;
        }
    }

    public function testPermanentApiExceptionMarksFailedAndThrowsUnrecoverable(): void
    {
        // 403 / scope revoked → весь job обречён. Handler вызывает markFailed
        // и оборачивает ошибку в Unrecoverable — Messenger не ретраит, а
        // оставшиеся батчи того же job'а упадут с «job уже терминален»
        // и станут no-op (см. testTerminalJobIsNoop).
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                self::stringContains('Ozon Performance'),
            )
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::once())
            ->method('requestOneBatch')
            ->willThrowException(new OzonPermanentApiException('403 — нет скоупа «Продвижение»'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, $bus, new NullLogger());

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Ozon permanent failure');

        $handler(new RequestOzonAdBatchMessage(
            companyId: self::COMPANY_ID,
            jobId: self::JOB_ID,
            dateFrom: self::DATE_FROM,
            dateTo: self::DATE_TO,
            campaignIds: ['c1'],
            batchIndex: 0,
            batchTotal: 1,
        ));
    }

    public function testMissingJobIsNoop(): void
    {
        // Гонка dispatch-vs-cleanup: orchestrator успел диспатчнуть батч
        // прямо перед тем, как job был вычищен (ручной cleanup, миграция).
        // findByIdAndCompany возвращает null → handler молча ack'ает.
        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn(null);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('requestOneBatch');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, $bus, new NullLogger());
        $handler(new RequestOzonAdBatchMessage(
            companyId: self::COMPANY_ID,
            jobId: self::JOB_ID,
            dateFrom: self::DATE_FROM,
            dateTo: self::DATE_TO,
            campaignIds: ['c1'],
            batchIndex: 0,
            batchTotal: 1,
        ));
    }

    public function testTerminalJobIsNoop(): void
    {
        // Если job уже в терминальном статусе (успел зафейлиться другим
        // батчем / был вручную отменён), handler должен молча ack'нуть
        // сообщение — никакого POST в Ozon и никакого markFailed.
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asFailed('уже упал раньше')
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('requestOneBatch');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, $bus, new NullLogger());
        $handler(new RequestOzonAdBatchMessage(
            companyId: self::COMPANY_ID,
            jobId: self::JOB_ID,
            dateFrom: self::DATE_FROM,
            dateTo: self::DATE_TO,
            campaignIds: ['c1'],
            batchIndex: 0,
            batchTotal: 1,
        ));
    }

    public function testRateLimitExceptionReschedulesWithDelay(): void
    {
        // 429 от Ozon → handler диспатчит копию сообщения с DelayStamp(60_000)
        // и возвращается нормально (ACK). markFailed НЕ вызывается, никаких
        // исключений наружу — иначе Messenger посчитал бы это за retry и
        // в конце концов отправил бы сообщение в failure transport.
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::once())
            ->method('requestOneBatch')
            ->willThrowException(new OzonRateLimitException('Ozon Performance: HTTP 429 Превышен лимит активных запросов'));

        $capturedEnvelope = null;
        $capturedStamps = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $env, array $stamps) use (&$capturedEnvelope, &$capturedStamps): Envelope {
                $capturedEnvelope = $env;
                $capturedStamps = $stamps;

                return $env instanceof Envelope ? $env : new Envelope($env);
            });

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, $bus, new NullLogger());
        $handler(new RequestOzonAdBatchMessage(
            companyId: self::COMPANY_ID,
            jobId: self::JOB_ID,
            dateFrom: self::DATE_FROM,
            dateTo: self::DATE_TO,
            campaignIds: ['c1', 'c2'],
            batchIndex: 0,
            batchTotal: 1,
        ));

        self::assertInstanceOf(Envelope::class, $capturedEnvelope);
        $rescheduled = $capturedEnvelope->getMessage();
        self::assertInstanceOf(RequestOzonAdBatchMessage::class, $rescheduled);
        self::assertSame(self::COMPANY_ID, $rescheduled->companyId);
        self::assertSame(self::JOB_ID, $rescheduled->jobId);
        self::assertSame(['c1', 'c2'], $rescheduled->campaignIds);

        self::assertIsArray($capturedStamps);
        self::assertCount(1, $capturedStamps);
        self::assertInstanceOf(DelayStamp::class, $capturedStamps[0]);
        self::assertSame(60_000, $capturedStamps[0]->getDelay());
    }

    public function testRateLimitIncrementsAttemptsOnReschedule(): void
    {
        // rateLimitAttempts: 0 → 1 на первом 429, 3 → 4 на четвёртом и т.д.
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('requestOneBatch')
            ->willThrowException(new OzonRateLimitException());

        $captured = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $env) use (&$captured): Envelope {
                $captured = $env;

                return $env instanceof Envelope ? $env : new Envelope($env);
            });

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, $bus, new NullLogger());
        $handler(new RequestOzonAdBatchMessage(
            companyId: self::COMPANY_ID,
            jobId: self::JOB_ID,
            dateFrom: self::DATE_FROM,
            dateTo: self::DATE_TO,
            campaignIds: ['c1'],
            batchIndex: 0,
            batchTotal: 1,
            rateLimitAttempts: 3,
        ));

        self::assertInstanceOf(Envelope::class, $captured);
        $rescheduled = $captured->getMessage();
        self::assertInstanceOf(RequestOzonAdBatchMessage::class, $rescheduled);
        self::assertSame(4, $rescheduled->rateLimitAttempts);
    }

    public function testRateLimitMaxAttemptsMarksFailedAndUnrecoverable(): void
    {
        // После MAX_RATE_LIMIT_ATTEMPTS (10) reschedules handler сдаётся:
        // markFailed на job, Unrecoverable наружу, новых сообщений в bus нет.
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                self::stringContains('rate-limited'),
            )
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('requestOneBatch')
            ->willThrowException(new OzonRateLimitException());

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, $bus, new NullLogger());

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Ozon rate limit exhausted');

        $handler(new RequestOzonAdBatchMessage(
            companyId: self::COMPANY_ID,
            jobId: self::JOB_ID,
            dateFrom: self::DATE_FROM,
            dateTo: self::DATE_TO,
            campaignIds: ['c1'],
            batchIndex: 0,
            batchTotal: 1,
            rateLimitAttempts: 10,
        ));
    }
}
