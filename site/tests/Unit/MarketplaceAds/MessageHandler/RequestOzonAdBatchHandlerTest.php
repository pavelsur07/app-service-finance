<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\RequestOzonAdBatchMessage;
use App\MarketplaceAds\MessageHandler\RequestOzonAdBatchHandler;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Unit-тесты {@see RequestOzonAdBatchHandler}: ровно один POST /statistics
 * на сообщение, с корректной обработкой terminal / missing / permanent /
 * transient сценариев.
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

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, new NullLogger());
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

        $oversized = [];
        for ($i = 1; $i <= 11; ++$i) {
            $oversized[] = 'c'.$i;
        }

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, new NullLogger());

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
        // Ozon 429 «Превышен лимит активных запросов» и прочие transient
        // (5xx, сеть, JSON) проходят наружу как \RuntimeException —
        // Messenger ретраит по расписанию async_ads, markFailed не
        // вызывается, Unrecoverable тоже.
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
            ->willThrowException(new \RuntimeException('Ozon Performance: HTTP 429 Превышен лимит активных запросов'));

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 429');

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

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, new NullLogger());

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

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, new NullLogger());
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

        $handler = new RequestOzonAdBatchHandler($jobRepo, $ozonClient, new NullLogger());
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
}
