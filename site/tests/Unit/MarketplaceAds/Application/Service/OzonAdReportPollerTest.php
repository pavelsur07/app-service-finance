<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application\Service;

use App\MarketplaceAds\Application\Service\OzonAdReportPoller;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\DownloadOzonAdReportMessage;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit-тесты {@see OzonAdReportPoller}: per-company state-machine shared-polling'а.
 *
 * Покрываемые инварианты:
 *  - Пустой in-flight набор → PollResult(0,0,0,0), Ozon не дёргается.
 *  - OzonPermanentApiException (403) → все строки markFinalized(ERROR).
 *  - Generic Throwable → строки не трогаются, errors=1.
 *  - Ozon state=OK → updateStateWithSchedule(state=OK, nextPollAt=null). НЕ markFinalized.
 *  - Ozon state=OK, updateStateWithSchedule=0 rows (гонка с параллельной финализацией)
 *    → dispatch НЕ происходит, warning в лог (v1.16).
 *  - Ozon state=ERROR → markFinalized(ERROR) c raw state в errorMessage.
 *  - UUID отсутствует в ответе, age < 3h → updateSchedule с backoff.
 *  - UUID отсутствует, age ≥ 3h → markFinalized(ABANDONED).
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
    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $bus;
    private OzonAdReportPoller $poller;

    protected function setUp(): void
    {
        $this->client = $this->createMock(OzonAdClient::class);
        $this->repo = $this->createMock(OzonAdPendingReportRepository::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $this->poller = new OzonAdReportPoller(
            $this->client,
            $this->repo,
            $this->bus,
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
        $this->bus->expects(self::never())->method('dispatch');

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

        // 403 путь никогда не должен диспатчить download — row'ы финализированы ERROR.
        $this->bus->expects(self::never())->method('dispatch');

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
        $this->bus->expects(self::never())->method('dispatch');

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

        // OK путь обязан диспатчить DownloadOzonAdReportMessage c совпадающими
        // companyId + pendingReportId (step 4 редизайна).
        $this->bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use ($r1): Envelope {
                self::assertInstanceOf(DownloadOzonAdReportMessage::class, $message);
                self::assertSame(self::COMPANY_ID, $message->companyId);
                self::assertSame($r1->getId(), $message->pendingReportId);

                return new Envelope($message);
            });

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
            ->with(self::COMPANY_ID, 'uuid-ready', OzonAdPendingReportState::OK, $now, null, 1, $now)
            ->willReturn(1);

        $this->bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use ($r1): Envelope {
                self::assertInstanceOf(DownloadOzonAdReportMessage::class, $message);
                self::assertSame($r1->getId(), $message->pendingReportId);

                return new Envelope($message);
            });

        ($this->poller)(self::COMPANY_ID, $now);
    }

    public function testOkStateDispatchesDownloadWhenUpdateAffectedRow(): void
    {
        // v1.16: explicit happy-path guard — updateStateWithSchedule=1 row
        // означает, что state=OK зафиксирован в БД до dispatch'а, контракт
        // «OK видна в БД до прихода message» соблюдён.
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-ok-dispatch', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')
            ->willReturn(['uuid-ok-dispatch' => 'OK']);

        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->willReturn(1);

        $this->bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use ($r1): Envelope {
                self::assertInstanceOf(DownloadOzonAdReportMessage::class, $message);
                self::assertSame(self::COMPANY_ID, $message->companyId);
                self::assertSame($r1->getId(), $message->pendingReportId);

                return new Envelope($message);
            });

        $result = ($this->poller)(self::COMPANY_ID, $now);

        // reconcileOne returns [1, 0] → aggregated PollResult fields.
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->finalized);
    }

    public function testOkStateSkipsDownloadDispatchWhenUpdateReturnsZeroRows(): void
    {
        // v1.16: race protection — если updateStateWithSchedule вернул 0 строк
        // (запись параллельно финализирована), dispatch DownloadOzonAdReportMessage
        // НЕ делается. Warning с context (companyId, reportUuid, pendingReportId)
        // пишется в лог для диагностики.
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        $r1 = $this->makeReport('uuid-race', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1]);
        $this->client->method('listReportsForCompany')
            ->willReturn(['uuid-race' => 'OK']);

        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->willReturn(0);

        // КЛЮЧЕВОЕ УТВЕРЖДЕНИЕ: dispatch не вызывается при 0 rows.
        $this->bus->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('update returned 0 rows'),
                self::callback(function (array $context) use ($r1): bool {
                    self::assertSame(self::COMPANY_ID, $context['companyId'] ?? null);
                    self::assertSame('uuid-race', $context['reportUuid'] ?? null);
                    self::assertSame($r1->getId(), $context['pendingReportId'] ?? null);

                    return true;
                }),
            );

        $poller = new OzonAdReportPoller($this->client, $this->repo, $this->bus, $logger);
        $result = ($poller)(self::COMPANY_ID, $now);

        // reconcileOne returns [0, 0] — ни updated, ни finalized не инкрементируются.
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->finalized);
        self::assertSame(1, $result->seen);
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
        $this->bus->expects(self::never())->method('dispatch');

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
        $this->bus->expects(self::never())->method('dispatch');

        $result = ($this->poller)(self::COMPANY_ID, $now);

        self::assertSame(1, $result->seen);
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->finalized);
    }

    public function testMissingFromListAndOldAbandons(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');
        // Чуть старше MAX_AGE_BEFORE_ABANDON_SECONDS (10 800с, v1.15).
        $r1 = $this->makeReport('uuid-zombie', pollAttempts: 5, ageSeconds: 10900);

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
        $this->bus->expects(self::never())->method('dispatch');

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
        $this->bus->expects(self::never())->method('dispatch');

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
        $this->bus->expects(self::never())->method('dispatch');

        ($this->poller)(self::COMPANY_ID, $now);
    }

    /**
     * BACKOFF_SCHEDULE_SECONDS[i] — задержка ПОСЛЕ i-го poll'а. Сервис
     * вызывает computeNextPollAt($now, $pollAttempts + 1), поэтому тест
     * передаёт $nextAttempts напрямую (уже после +1) и ожидает BACKOFF[min(nextAttempts, 5)].
     * Индекса 0 в таблице нет (см. const-комментарий) — значения ≤0 должны
     * clamp'иться к первому валидному индексу (1 → 30s).
     *
     * В сервисе $nextAttempts = pollAttempts_before + 1, поэтому для теста
     * достаточно задать pollAttempts_before = $nextAttempts - 1 (значения
     * <0 допустимы через прямой рефлекшн и тестируют defensive clamp).
     *
     * @return iterable<string, array{int, int}> [nextAttempts, expectedSeconds]
     */
    public static function backoffSchedule(): iterable
    {
        yield 'zero_attempts_clamps_up' => [0, 30];
        yield 'first_poll' => [1, 30];
        yield 'second_poll' => [2, 60];
        yield 'third_poll' => [3, 120];
        yield 'fourth_poll' => [4, 300];
        yield 'fifth_poll' => [5, 600];
        yield 'beyond_last_clamps' => [10, 600];
        yield 'negative_clamps_up' => [-1, 30];
    }

    /**
     * @dataProvider backoffSchedule
     */
    public function testBackoffScheduleIsCorrect(int $nextAttempts, int $expectedSeconds): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');

        // Сервис прибавляет 1 к pollAttempts перед вызовом computeNextPollAt,
        // поэтому задаём pollAttempts_before = nextAttempts - 1. Для nextAttempts ≤ 0
        // это даст отрицательный pollAttempts в entity — записывается через reflection.
        $r = $this->makeReport('uuid-bo', pollAttempts: $nextAttempts - 1, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r]);
        $this->client->method('listReportsForCompany')->willReturn([]); // missing → reschedule

        $expected = $now->modify(sprintf('+%d seconds', $expectedSeconds));

        $this->repo->expects(self::once())
            ->method('updateSchedule')
            ->with(self::COMPANY_ID, 'uuid-bo', $now, $expected, $nextAttempts)
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
