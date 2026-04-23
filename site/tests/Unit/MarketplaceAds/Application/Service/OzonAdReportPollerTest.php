<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application\Service;

use App\MarketplaceAds\Application\Service\OzonAdReportPoller;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
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
 * Unit-тесты {@see OzonAdReportPoller}: per-UUID polling state machine (v1.17).
 *
 * Покрываемые инварианты:
 *  - state=OK: updateStateWithSchedule(OK, nextPollAt=null) + dispatch DownloadOzonAdReportMessage.
 *  - state=IN_PROGRESS: updateStateWithSchedule(IN_PROGRESS, nextPollAt) без dispatch.
 *  - state=ERROR: markFinalized(ERROR).
 *  - Старый (age ≥ MAX_AGE_BEFORE_ABANDON) IN_PROGRESS → force-ABANDONED после updateStateWithSchedule.
 *  - Exception на одном UUID изолируется: остальные обрабатываются, errors++.
 *  - state=OK, но updateStateWithSchedule=0 rows (гонка) → dispatch НЕ делается, warning в лог.
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

    public function testInvokeWithSingleOkReportDispatchesDownloadAndReturnsUpdated(): void
    {
        $r = $this->makeReport('uuid-ok', pollAttempts: 2, ageSeconds: 300);

        $this->repo->method('findInFlightByCompany')->willReturn([$r]);
        $this->client->expects(self::once())
            ->method('pollOneReport')
            ->with(self::COMPANY_ID, 'uuid-ok')
            ->willReturn(['state' => 'OK', 'raw' => ['UUID' => 'uuid-ok', 'state' => 'OK']]);

        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->with(
                self::COMPANY_ID,
                'uuid-ok',
                OzonAdPendingReportState::OK,
                self::isInstanceOf(\DateTimeImmutable::class),
                null,
                3,
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn(1);

        $this->repo->expects(self::never())->method('markFinalized');

        $this->bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use ($r): Envelope {
                self::assertInstanceOf(DownloadOzonAdReportMessage::class, $message);
                self::assertSame(self::COMPANY_ID, $message->companyId);
                self::assertSame($r->getId(), $message->pendingReportId);

                return new Envelope($message);
            });

        $result = ($this->poller)(self::COMPANY_ID);

        self::assertSame(1, $result->seen);
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->finalized);
        self::assertSame(0, $result->errors);
    }

    public function testInvokeWithInProgressReportUpdatesStateAndContinues(): void
    {
        $r = $this->makeReport('uuid-ip', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r]);
        $this->client->expects(self::once())
            ->method('pollOneReport')
            ->with(self::COMPANY_ID, 'uuid-ip')
            ->willReturn(['state' => 'IN_PROGRESS', 'raw' => ['state' => 'IN_PROGRESS']]);

        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->with(
                self::COMPANY_ID,
                'uuid-ip',
                OzonAdPendingReportState::IN_PROGRESS,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::callback(static fn ($v): bool => $v instanceof \DateTimeImmutable),
                1,
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn(1);

        $this->repo->expects(self::never())->method('markFinalized');
        $this->bus->expects(self::never())->method('dispatch');

        $result = ($this->poller)(self::COMPANY_ID);

        self::assertSame(1, $result->seen);
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->finalized);
        self::assertSame(0, $result->errors);
    }

    public function testInvokeWithErrorStateFinalizesAsError(): void
    {
        $r = $this->makeReport('uuid-err', pollAttempts: 1, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r]);
        $this->client->expects(self::once())
            ->method('pollOneReport')
            ->with(self::COMPANY_ID, 'uuid-err')
            ->willReturn(['state' => 'ERROR', 'raw' => ['state' => 'ERROR']]);

        $this->repo->expects(self::once())
            ->method('markFinalized')
            ->with(
                self::COMPANY_ID,
                'uuid-err',
                OzonAdPendingReportState::ERROR,
                self::stringContains('ERROR'),
            )
            ->willReturn(1);

        $this->repo->expects(self::never())->method('updateStateWithSchedule');
        $this->bus->expects(self::never())->method('dispatch');

        $result = ($this->poller)(self::COMPANY_ID);

        self::assertSame(1, $result->seen);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->finalized);
        self::assertSame(0, $result->errors);
    }

    public function testInvokeWithOldInProgressReportForceAbandonedAfterThreeHours(): void
    {
        // MAX_AGE_BEFORE_ABANDON_SECONDS = 10 800 (3 часа); берём 3ч + 1с.
        $r = $this->makeReport('uuid-zombie', pollAttempts: 5, ageSeconds: 10_801);

        $this->repo->method('findInFlightByCompany')->willReturn([$r]);
        $this->client->expects(self::once())
            ->method('pollOneReport')
            ->with(self::COMPANY_ID, 'uuid-zombie')
            ->willReturn(['state' => 'IN_PROGRESS', 'raw' => ['state' => 'IN_PROGRESS']]);

        // Полный polling-цикл не break'ается: сначала updateStateWithSchedule,
        // затем overlay markFinalized(ABANDONED).
        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->with(
                self::COMPANY_ID,
                'uuid-zombie',
                OzonAdPendingReportState::IN_PROGRESS,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::isInstanceOf(\DateTimeImmutable::class),
                6,
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn(1);

        $this->repo->expects(self::once())
            ->method('markFinalized')
            ->with(
                self::COMPANY_ID,
                'uuid-zombie',
                OzonAdPendingReportState::ABANDONED,
                self::stringContains('Force-abandoned'),
            )
            ->willReturn(1);

        $this->bus->expects(self::never())->method('dispatch');

        $result = ($this->poller)(self::COMPANY_ID);

        self::assertSame(1, $result->seen);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->finalized);
        self::assertSame(0, $result->errors);
    }

    public function testInvokeWithPollerExceptionOnOneContinuesWithOthers(): void
    {
        $r1 = $this->makeReport('uuid-1', pollAttempts: 0, ageSeconds: 60);
        $r2 = $this->makeReport('uuid-2', pollAttempts: 0, ageSeconds: 60);
        $r3 = $this->makeReport('uuid-3', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r1, $r2, $r3]);

        $this->client->expects(self::exactly(3))
            ->method('pollOneReport')
            ->willReturnCallback(function (string $companyId, string $uuid): array {
                if ('uuid-2' === $uuid) {
                    throw new \RuntimeException('network blip');
                }

                return ['state' => 'OK', 'raw' => ['UUID' => $uuid, 'state' => 'OK']];
            });

        $this->repo->expects(self::exactly(2))
            ->method('updateStateWithSchedule')
            ->willReturn(1);

        $this->repo->expects(self::never())->method('markFinalized');

        $dispatched = [];
        $this->bus->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatched): Envelope {
                self::assertInstanceOf(DownloadOzonAdReportMessage::class, $message);
                $dispatched[] = $message->pendingReportId;

                return new Envelope($message);
            });

        $result = ($this->poller)(self::COMPANY_ID);

        self::assertSame(3, $result->seen);
        self::assertSame(2, $result->updated);
        self::assertSame(0, $result->finalized);
        self::assertSame(1, $result->errors);

        self::assertSame([$r1->getId(), $r3->getId()], $dispatched);
    }

    public function testInvokeWithOkStateButUpdateReturns0RowsSkipsDispatch(): void
    {
        $r = $this->makeReport('uuid-race', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r]);
        $this->client->method('pollOneReport')
            ->willReturn(['state' => 'OK', 'raw' => ['state' => 'OK']]);

        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->willReturn(0);

        $this->bus->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('update returned 0 rows'),
                self::callback(function (array $context) use ($r): bool {
                    self::assertSame(self::COMPANY_ID, $context['companyId'] ?? null);
                    self::assertSame('uuid-race', $context['reportUuid'] ?? null);
                    self::assertSame($r->getId(), $context['pendingReportId'] ?? null);

                    return true;
                }),
            );

        $poller = new OzonAdReportPoller($this->client, $this->repo, $this->bus, $logger);
        $result = ($poller)(self::COMPANY_ID);

        self::assertSame(1, $result->seen);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->finalized);
        self::assertSame(0, $result->errors);
    }

    public function testInvokeWithEmptyInFlightReturnsZeroAndDoesNotCallOzon(): void
    {
        $this->repo->expects(self::once())
            ->method('findInFlightByCompany')
            ->with(self::COMPANY_ID)
            ->willReturn([]);

        $this->client->expects(self::never())->method('pollOneReport');
        $this->bus->expects(self::never())->method('dispatch');

        $result = ($this->poller)(self::COMPANY_ID);

        self::assertSame(0, $result->seen);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->finalized);
        self::assertSame(0, $result->errors);
    }

    public function testReadyStateIsTreatedLikeOk(): void
    {
        $r = $this->makeReport('uuid-ready', pollAttempts: 0, ageSeconds: 60);

        $this->repo->method('findInFlightByCompany')->willReturn([$r]);
        $this->client->method('pollOneReport')
            ->willReturn(['state' => 'READY', 'raw' => ['state' => 'READY']]);

        $this->repo->expects(self::once())
            ->method('updateStateWithSchedule')
            ->with(
                self::COMPANY_ID,
                'uuid-ready',
                OzonAdPendingReportState::OK,
                self::isInstanceOf(\DateTimeImmutable::class),
                null,
                1,
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn(1);

        $this->bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use ($r): Envelope {
                self::assertInstanceOf(DownloadOzonAdReportMessage::class, $message);
                self::assertSame($r->getId(), $message->pendingReportId);

                return new Envelope($message);
            });

        ($this->poller)(self::COMPANY_ID);
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
        $requestedProp->setValue($report, new \DateTimeImmutable(sprintf('-%d seconds', $ageSeconds)));

        return $report;
    }
}
