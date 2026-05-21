<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Application\Service\WbFinancialReportFirstAvailableResolver;
use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbFinancialReportFirstAvailableResolverTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const CONNECTION_ID = '22222222-2222-2222-2222-222222222222';

    private MarketplaceFinancialReportSyncStatusRepository $statusRepository;

    protected function setUp(): void { parent::setUp(); $this->statusRepository = self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class); }

    public function testLocalKnownDataReturnsFirstDateAndIgnoresLoading(): void
    {
        $this->persistStatus('2026-01-02', static function (MarketplaceFinancialReportSyncStatus $s): void { $s->markLoading(FinancialReportSyncMode::INITIAL); });
        $this->persistStatus('2026-01-03', static function (MarketplaceFinancialReportSyncStatus $s): void { $s->markRawLoaded('raw-id', 10, 'hash'); });

        $resolver = $this->resolverWithResponses([]);
        $result = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token');

        self::assertTrue($result->hasData());
        self::assertSame('2026-01-03', $result->getStartDate()?->format('Y-m-d'));
    }

    public function testFullRangeFalseReturnsNoDataImmediately(): void
    {
        $resolver = $this->resolverWithResponses([['http_code' => 204, 'body' => '']]);
        $result = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token');

        self::assertFalse($result->hasData());
        self::assertFalse($result->needsRetry());
    }


    public function testFullRangeContinuationAfterRateLimitGoesToMonthScan(): void
    {
        $resolver = $this->resolverWithResponses([
            ['http_code' => 429, 'body' => '{"error":"rate"}'],
            ['http_code' => 200, 'body' => '[{"rrdId":1}]'],
        ]);

        $step1 = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token');
        self::assertTrue($step1->needsRetry());
        self::assertSame(WbFinancialReportFirstAvailableResolver::PHASE_FULL_RANGE, $step1->getPhase());

        $step2 = $resolver->resolve(
            self::CONNECTION_ID,
            self::COMPANY_ID,
            'token',
            $step1->getPhase(),
            $step1->getNextProbeFrom(),
            $step1->getNextProbeTo(),
        );

        self::assertTrue($step2->needsRetry());
        self::assertSame(WbFinancialReportFirstAvailableResolver::PHASE_MONTH_SCAN, $step2->getPhase());
    }

    public function testProgressIsStatefulAndDoesNotRestartFromFullRange(): void
    {
        $resolver = $this->resolverWithResponses([
            ['http_code' => 200, 'body' => '[{"rrdId":1}]'], // full range true
            ['http_code' => 204, 'body' => ''], // jan false
            ['http_code' => 200, 'body' => '[{"rrdId":2}]'], // feb true
            ['http_code' => 204, 'body' => ''], // feb-01 false
            ['http_code' => 204, 'body' => ''], // feb-02 false
            ['http_code' => 200, 'body' => '[{"rrdId":3}]'], // feb-03 true
        ]);

        $step1 = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token');
        self::assertTrue($step1->needsRetry());
        self::assertSame(WbFinancialReportFirstAvailableResolver::PHASE_MONTH_SCAN, $step1->getPhase());

        $step2 = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token', $step1->getPhase(), $step1->getNextProbeFrom(), $step1->getNextProbeTo());
        self::assertTrue($step2->needsRetry());
        self::assertSame(WbFinancialReportFirstAvailableResolver::PHASE_MONTH_SCAN, $step2->getPhase());

        $step3 = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token', $step2->getPhase(), $step2->getNextProbeFrom(), $step2->getNextProbeTo());
        self::assertTrue($step3->needsRetry());
        self::assertSame(WbFinancialReportFirstAvailableResolver::PHASE_DAY_SCAN, $step3->getPhase());

        $step4 = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token', $step3->getPhase(), $step3->getNextProbeFrom(), $step3->getNextProbeTo());
        self::assertTrue($step4->needsRetry());
        $step5 = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token', $step4->getPhase(), $step4->getNextProbeFrom(), $step4->getNextProbeTo());
        self::assertTrue($step5->needsRetry());

        $step6 = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token', $step5->getPhase(), $step5->getNextProbeFrom(), $step5->getNextProbeTo());
        self::assertTrue($step6->hasData());
        self::assertSame('2026-02-03', $step6->getStartDate()?->format('Y-m-d'));
    }

    public function testRateLimitedStepReturnsIncompleteWithRetryAfter(): void
    {
        $resolver = $this->resolverWithResponses([
            ['http_code' => 200, 'body' => '[{"rrdId":1}]'],
        ], true);

        $step1 = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token');
        self::assertTrue($step1->needsRetry());

        $step2 = $resolver->resolve(self::CONNECTION_ID, self::COMPANY_ID, 'token', $step1->getPhase(), $step1->getNextProbeFrom(), $step1->getNextProbeTo());
        self::assertTrue($step2->needsRetry());
        self::assertSame(61, $step2->getRetryAfterSeconds());
    }

    private function resolverWithResponses(array $responses, bool $withLimiter = false): WbFinancialReportFirstAvailableResolver
    {
        $idx = 0;
        $http = new MockHttpClient(static function () use (&$idx, $responses): MockResponse {
            $response = $responses[$idx] ?? ['http_code' => 500, 'body' => '{"error":"unexpected"}'];
            ++$idx;

            return new MockResponse($response['body'], ['http_code' => $response['http_code']]);
        });

        $client = new WbFinanceSalesReportClient($http, new MockClock('2026-03-15 10:00:00 Europe/Moscow'), null, $withLimiter ? $this->rateLimiter() : null);

        return new WbFinancialReportFirstAvailableResolver(new WbFinancialReportPeriodResolver(new MockClock('2026-03-15 10:00:00 Europe/Moscow')), $this->statusRepository, $client);
    }

    private function persistStatus(string $day, callable $marker): void
    {
        $status = new MarketplaceFinancialReportSyncStatus(Uuid::uuid7()->toString(), self::COMPANY_ID, self::CONNECTION_ID, MarketplaceType::WILDBERRIES, 'sales_report', 'endpoint', new \DateTimeImmutable($day));
        $marker($status);
        $this->statusRepository->save($status);
        $this->em->flush();
    }

    private function rateLimiter(): WbFinanceRateLimiter
    {
        return new WbFinanceRateLimiter(new RateLimiterFactory(['id' => 'wb_finance', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage()));
    }
}
