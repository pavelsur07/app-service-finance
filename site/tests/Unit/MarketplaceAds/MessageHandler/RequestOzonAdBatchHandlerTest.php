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
}
