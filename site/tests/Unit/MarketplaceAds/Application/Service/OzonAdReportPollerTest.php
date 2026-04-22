<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application\Service;

use App\MarketplaceAds\Application\Service\OzonAdReportPoller;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

/**
 * Unit-тесты {@see OzonAdReportPoller}: per-company state-machine shared-polling'а.
 *
 * Покрываемые инварианты:
 *  - Пустой in-flight набор → PollResult(0,0,0,0), Ozon не дёргается.
 *  - OzonPermanentApiException (403) → все строки markFinalized(ERROR).
 *  - Generic Throwable → строки не трогаются, errors=1.
 *  - Ozon state=OK → updateStateWithSchedule(state=OK, nextPollAt=null). НЕ markFinalized.
 *  - Ozon state=ERROR → markFinalized(ERROR) c raw state в errorMessage.
 *  - UUID отсутствует в ответе, age < 1h → updateSchedule с backoff.
 *  - UUID отсутствует, age ≥ 1h → markFinalized(ABANDONED).
 *  - Ozon state=NOT_STARTED → updateStateWithSchedule(state=NOT_STARTED, nextPollAt).
 *  - Backoff schedule корректен для attempts 0..10.
 */
final class OzonAdReportPollerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    /** @var OzonAdClient&MockObject */
    private OzonAdClient $client;
    /** @var OzonAdPendingReportRepository&MockObject */
    private OzonAdPendingReportRepository $repo;
    private OzonAdReportPoller $poller;

    protected function setUp(): void
    {
        $this->client = $this->createMock(OzonAdClient::class);
        $this->repo = $this->createMock(OzonAdPendingReportRepository::class);

        $this->poller = new OzonAdReportPoller(
            $this->client,
            $this->repo,
            new NullLogger(),
        );
    }

    public function testEmptyInFlightReturnsZeroAndDoesNotCallOzon(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');

        $this->repo->expects(self::once())
            ->method('findInFlightByCompany')
            ->with(self::COMPANY_ID)
            ->willReturn([]);

        $this->client->expects(self::never())->method('listReportsForCompany');

        $result = ($this->poller)(self::COMPANY_ID, $now);

        self::assertSame(0, $result->seen);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->finalized);
        self::assertSame(0, $result->errors);
    }

    public function testOzonPermanentApiExceptionFinalizesAllInFlight(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-1', pollAttempts: 0, ageSeconds: 60);
        $r2 = $this->makeReport('uuid-2', pollAttempts: 1, ageSeconds: 120);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1, $r2]);
        $this->client->method('listReportsForCompany')
            ->willThrowException(new OzonPermanentApiException('403 forbidden'));

        $this->repo->expects(self::exactly(2))
            ->method('markFinalized')
            ->willReturnCallback(function (string $companyId, string $uuid, string $state, ?string $err) {
                self::assertSame(self::COMPANY_ID, $companyId);
                self::assertSame(OzonAdPendingReportState::ERROR, $state);
                self::assertNotNull($err);
                self::assertStringContainsString('permanently denied', $err);

                return 1;
            });

        $result = ($this->poller)(self::COMPANY_ID, $now);

        self::assertSame(2, $result->seen);
        self::assertSame(0, $result->updated);
        self::assertSame(2, $result->finalized);
        self::assertSame(2, $result->errors);
    }

    public function testGenericThrowableLeavesRowsUntouched(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-1', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')
            ->willThrowException(new \RuntimeException('network timeout'));

        $this->repo->expects(self::never())->method('markFinalized');
        $this->repo->expects(self::never())->method('updateSchedule');
        $this->repo->expects(self::never())->method('updateStateWithSchedule');

        $result = ($this->poller)(self::COMPANY_ID, $now);

        self::assertSame(1, $result->seen);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->finalized);
        self::assertSame(1, $result->errors);
    }

    public function testOkStateUpdatesStateButDoesNotFinalize(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-ok', pollAttempts: 2, ageSeconds: 300);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')
            ->willReturn(['uuid-ok' => 'OK']);

        $this->repo->expects(self::never())->method('markFinalized');
        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->with(
                self::COMPANY_ID,
                'uuid-ok',
                OzonAdPendingReportState::OK,
                $now,
                null,          // nextPollAt=null — terminal OK, больше не опрашиваем
                3,             // attempts + 1
                $now,          // firstNonPendingAt (state не REQUESTED/NOT_STARTED)
            )
            ->willReturn(1);

        $result = ($this->poller)(self::COMPANY_ID, $now);

        self::assertSame(1, $result->seen);
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->finalized);
    }

    public function testReadyStateIsTreatedLikeOk(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-ready', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')
            ->willReturn(['uuid-ready' => 'READY']);

        $this->repo->expects(self::never())->method('markFinalized');
        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->with(self::COMPANY_ID, 'uuid-ready', OzonAdPendingReportState::OK, $now, null, 1, $now);

        ($this->poller)(self::COMPANY_ID, $now);
    }

    public function testErrorStateFinalizesWithRawStateInMessage(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-err', pollAttempts: 1, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')
            ->willReturn(['uuid-err' => 'CANCELLED']);

        $this->repo->expects(self::once())
            ->method('markFinalized')
            ->with(
                self::COMPANY_ID,
                'uuid-err',
                OzonAdPendingReportState::ERROR,
                self::stringContains('CANCELLED'),
            )
            ->willReturn(1);
        $this->repo->expects(self::never())->method('updateStateWithSchedule');
        $this->repo->expects(self::never())->method('updateSchedule');

        $result = ($this->poller)(self::COMPANY_ID, $now);

        self::assertSame(1, $result->seen);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->finalized);
    }

    public function testMissingFromListAndYoungReschedules(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-missing', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')->willReturn([]);

        $this->repo->expects(self::never())->method('markFinalized');
        $this->repo->expects(self::never())->method('updateStateWithSchedule');
        $this->repo->expects(self::once())
            ->method('updateSchedule')
            ->with(
                self::COMPANY_ID,
                'uuid-missing',
                $now,
                $now->modify('+30 seconds'), // attempts=1 → 30s
                1,
            )
            ->willReturn(1);

        $result = ($this->poller)(self::COMPANY_ID, $now);

        self::assertSame(1, $result->seen);
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->finalized);
    }

    public function testMissingFromListAndOldAbandons(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-zombie', pollAttempts: 5, ageSeconds: 3700);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')->willReturn([]);

        $this->repo->expects(self::never())->method('updateSchedule');
        $this->repo->expects(self::never())->method('updateStateWithSchedule');
        $this->repo->expects(self::once())
            ->method('markFinalized')
            ->with(
                self::COMPANY_ID,
                'uuid-zombie',
                OzonAdPendingReportState::ABANDONED,
                self::stringContains('Missing from /statistics/list'),
            )
            ->willReturn(1);

        $result = ($this->poller)(self::COMPANY_ID, $now);

        self::assertSame(1, $result->seen);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->finalized);
    }

    public function testNotStartedStateUpdatesWithScheduleAndNoFirstNonPending(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-ns', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')
            ->willReturn(['uuid-ns' => 'NOT_STARTED']);

        $this->repo->expects(self::never())->method('markFinalized');
        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->with(
                self::COMPANY_ID,
                'uuid-ns',
                'NOT_STARTED',
                $now,
                $now->modify('+30 seconds'), // attempts=1 → 30s
                1,
                null, // NOT_STARTED → не фиксируем firstNonPendingAt
            )
            ->willReturn(1);

        $result = ($this->poller)(self::COMPANY_ID, $now);

        self::assertSame(1, $result->seen);
        self::assertSame(1, $result->updated);
    }

    public function testInProgressStateFixesFirstNonPendingAt(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-ip', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')
            ->willReturn(['uuid-ip' => 'IN_PROGRESS']);

        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->with(
                self::COMPANY_ID,
                'uuid-ip',
                'IN_PROGRESS',
                $now,
                $now->modify('+30 seconds'),
                1,
                $now, // state ≠ NOT_STARTED → фиксируем
            )
            ->willReturn(1);

        ($this->poller)(self::COMPANY_ID, $now);
    }

    /**
     * BACKOFF_SCHEDULE_SECONDS[i] — задержка ПОСЛЕ i-го poll'а. В сервисе
     * nextAttempts = pollAttempts + 1, поэтому тест задаёт pollAttemptsBefore
     * и ожидает BACKOFF[pollAttemptsBefore + 1] (clamp на верхней границе = 5).
     *
     * @return iterable<string, array{int, int}> [pollAttemptsBefore, expectedSeconds]
     */
    public static function backoffSchedule(): iterable
    {
        yield 'before=0 → 30s (BACKOFF[1])' => [0, 30];
        yield 'before=1 → 60s (BACKOFF[2])' => [1, 60];
        yield 'before=2 → 120s (BACKOFF[3])' => [2, 120];
        yield 'before=3 → 300s (BACKOFF[4])' => [3, 300];
        yield 'before=4 → 600s (BACKOFF[5])' => [4, 600];
        yield 'before=5 → 600s (clamp at 5)' => [5, 600];
        yield 'before=10 → 600s (clamp at 5)' => [10, 600];
    }

    /**
     * @dataProvider backoffSchedule
     */
    public function testBackoffScheduleIsCorrect(int $pollAttemptsBefore, int $expectedSeconds): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');

        $r = $this->makeReport('uuid-bo', pollAttempts: $pollAttemptsBefore, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r]);
        $this->client->method('listReportsForCompany')->willReturn([]); // missing → reschedule

        $expected = $now->modify(sprintf('+%d seconds', $expectedSeconds));

        $this->repo->expects(self::once())
            ->method('updateSchedule')
            ->with(self::COMPANY_ID, 'uuid-bo', $now, $expected, $pollAttemptsBefore + 1)
            ->willReturn(1);

        ($this->poller)(self::COMPANY_ID, $now);
    }

    private function makeReport(
        string $ozonUuid,
        int $pollAttempts,
        int $ageSeconds,
    ): OzonAdPendingReport {
        $report = new OzonAdPendingReport(
            companyId: self::COMPANY_ID,
            ozonUuid: $ozonUuid,
            dateFrom: new \DateTimeImmutable('2026-04-01'),
            dateTo: new \DateTimeImmutable('2026-04-01'),
            campaignIds: ['1'],
            jobId: Uuid::uuid7()->toString(),
        );

        $ref = new \ReflectionClass($report);

        $attemptsProp = $ref->getProperty('pollAttempts');
        $attemptsProp->setAccessible(true);
        $attemptsProp->setValue($report, $pollAttempts);

        $requestedProp = $ref->getProperty('requestedAt');
        $requestedProp->setAccessible(true);
        $requestedProp->setValue($report, new \DateTimeImmutable(sprintf('2026-04-22 12:00:00 -%d seconds', $ageSeconds)));

        return $report;
    }
}
