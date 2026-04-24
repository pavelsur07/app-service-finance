<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Command;

use App\MarketplaceAds\Application\ExtractBatchesToRawDocumentsAction;
use App\MarketplaceAds\Command\AdBatchPollerCommand;
use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Shared\Service\Storage\StorageService;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit-тесты {@see AdBatchPollerCommand}: ветки processBatch() без реальной БД.
 *
 * Покрывают все state-branches:
 *  - пустой queue → SUCCESS, "No IN_FLIGHT batches";
 *  - state=OK → download + OK с storagePath/Hash/Size;
 *  - state=ERROR → FAILED + finishedAt + lastError с state;
 *  - state=NOT_STARTED → батч не трогаем;
 *  - unexpected state → warning, батч не трогаем;
 *  - OzonPermanentApiException на pollOneReport → FAILED;
 *  - IN_FLIGHT без ozon_uuid → FAILED (sanity-check);
 *  - transient ошибка в одном батче — continue на следующий.
 */
final class AdBatchPollerCommandTest extends TestCase
{
    /** @var OzonAdClient&MockObject */
    private OzonAdClient $ozonClient;
    /** @var AdScheduledBatchRepository&MockObject */
    private AdScheduledBatchRepository $batchRepo;
    /** @var StorageService&MockObject */
    private StorageService $storage;
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;
    /** @var ExtractBatchesToRawDocumentsAction&MockObject */
    private ExtractBatchesToRawDocumentsAction $extractAction;

    private AdBatchPollerCommand $command;

    protected function setUp(): void
    {
        $this->ozonClient = $this->createMock(OzonAdClient::class);
        $this->batchRepo = $this->createMock(AdScheduledBatchRepository::class);
        $this->storage = $this->createMock(StorageService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->extractAction = $this->createMock(ExtractBatchesToRawDocumentsAction::class);

        $this->command = new AdBatchPollerCommand(
            $this->ozonClient,
            $this->batchRepo,
            $this->storage,
            $this->em,
            $this->extractAction,
            new NullLogger(),
        );
    }

    public function testEmptyQueueExitsSuccess(): void
    {
        $this->batchRepo->method('findAllInFlight')->willReturn([]);
        $this->ozonClient->expects(self::never())->method('pollOneReport');
        $this->em->expects(self::never())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No IN_FLIGHT batches', $tester->getDisplay());
    }

    public function testOkStateDownloadsAndMarksOk(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->expects(self::once())
            ->method('pollOneReport')
            ->with($batch->getCompanyId(), $batch->getOzonUuid())
            ->willReturn(['state' => 'OK', 'raw' => ['state' => 'OK']]);

        $this->ozonClient->expects(self::once())
            ->method('fetchReportContent')
            ->willReturn(['body' => 'a,b,c', 'contentType' => 'text/csv']);

        $this->storage->expects(self::once())
            ->method('storeBytes')
            ->with(
                'a,b,c',
                self::stringContains(sprintf('marketplace-ads/%s/%s.csv', $batch->getCompanyId(), (string) $batch->getOzonUuid())),
            )
            ->willReturn([
                'storagePath' => sprintf('marketplace-ads/%s/%s.csv', $batch->getCompanyId(), (string) $batch->getOzonUuid()),
                'fileHash' => 'abc123',
                'sizeBytes' => 5,
                'mimeType' => null,
            ]);

        $this->em->expects(self::once())->method('flush');

        // Task-13a: после успешного download'а вызывается auto-extract.
        $this->extractAction->expects(self::once())
            ->method('processBatch')
            ->with($batch)
            ->willReturn(['processed' => 1, 'skipped' => 0]);

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::OK, $batch->getState());
        self::assertSame('abc123', $batch->getFileHash());
        self::assertSame(5, $batch->getFileSize());
        self::assertNotNull($batch->getFinishedAt());
        self::assertNull($batch->getLastError());
    }

    public function testOkStateAutoExtractFailureDoesNotMarkBatchFailed(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willReturn(['state' => 'OK', 'raw' => []]);
        $this->ozonClient->method('fetchReportContent')
            ->willReturn(['body' => 'a,b,c', 'contentType' => 'text/csv']);

        $this->storage->method('storeBytes')->willReturn([
            'storagePath' => sprintf('marketplace-ads/%s/%s.csv', $batch->getCompanyId(), (string) $batch->getOzonUuid()),
            'fileHash' => 'abc123',
            'sizeBytes' => 5,
            'mimeType' => null,
        ]);

        // Auto-extract падает — это НЕ должно перевести batch в FAILED.
        $this->extractAction->expects(self::once())
            ->method('processBatch')
            ->willThrowException(new \RuntimeException('zip is broken'));

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::OK, $batch->getState(), 'Extract failure не ломает successful download');
        self::assertNull($batch->getLastError(), 'lastError — только про download, не про extract');
        self::assertStringContainsString('ok=1', $tester->getDisplay());
    }

    public function testErrorStateMarksFailed(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willReturn(['state' => 'ERROR', 'raw' => ['state' => 'ERROR']]);

        $this->ozonClient->expects(self::never())->method('fetchReportContent');
        $this->storage->expects(self::never())->method('storeBytes');
        $this->em->expects(self::once())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::FAILED, $batch->getState());
        self::assertNotNull($batch->getFinishedAt());
        self::assertStringContainsString('state=ERROR', (string) $batch->getLastError());
    }

    public function testNotFoundStateMarksFailed(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willReturn(['state' => 'NOT_FOUND', 'raw' => []]);

        $this->ozonClient->expects(self::never())->method('fetchReportContent');
        $this->em->expects(self::once())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::FAILED, $batch->getState());
        self::assertStringContainsString('state=NOT_FOUND', (string) $batch->getLastError());
    }

    public function testPermanentApiExceptionDuringDownloadMarksFailed(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willReturn(['state' => 'OK', 'raw' => []]);

        // Download бросает permanent — должен быть поймано, batch → FAILED.
        $this->ozonClient->expects(self::once())
            ->method('fetchReportContent')
            ->willThrowException(new OzonPermanentApiException('403 revoked mid-download'));

        $this->storage->expects(self::never())->method('storeBytes');
        $this->em->expects(self::once())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::FAILED, $batch->getState());
        self::assertNotNull($batch->getFinishedAt());
        self::assertStringStartsWith('Download permanent failure:', (string) $batch->getLastError());
        self::assertNull($batch->getStoragePath(), 'storage не должен быть установлен при permanent');
    }

    public function testCancelledStateMarksFailed(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willReturn(['state' => 'CANCELLED', 'raw' => []]);

        $this->em->expects(self::once())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::FAILED, $batch->getState());
        self::assertStringContainsString('state=CANCELLED', (string) $batch->getLastError());
    }

    public function testNotStartedKeepsBatchInFlight(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willReturn(['state' => 'NOT_STARTED', 'raw' => []]);

        // Не трогаем batch — ни flush, ни mutation.
        $this->ozonClient->expects(self::never())->method('fetchReportContent');
        $this->storage->expects(self::never())->method('storeBytes');
        $this->em->expects(self::never())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $batch->getState());
        self::assertNull($batch->getFinishedAt());
        self::assertNull($batch->getLastError());
    }

    public function testInProgressBatchAbandonedAfterThreeHours(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');
        // Override startedAt: 4 часа назад → за порогом 3h.
        $batch->setStartedAt(new \DateTimeImmutable('-4 hours'));

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willReturn(['state' => 'IN_PROGRESS', 'raw' => []]);

        $this->ozonClient->expects(self::never())->method('fetchReportContent');
        $this->em->expects(self::once())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::ABANDONED, $batch->getState());
        self::assertNotNull($batch->getFinishedAt());
        self::assertStringContainsString('Abandoned: stuck in IN_PROGRESS', (string) $batch->getLastError());
    }

    public function testInProgressKeepsBatchInFlight(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willReturn(['state' => 'IN_PROGRESS', 'raw' => []]);

        $this->em->expects(self::never())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $batch->getState());
    }

    public function testUnexpectedStateLogsWarningAndKeepsBatch(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willReturn(['state' => 'WEIRD_UNKNOWN_STATE', 'raw' => []]);

        $this->em->expects(self::never())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $batch->getState());
    }

    public function testPermanentApiExceptionOnPollMarksFailed(): void
    {
        $batch = $this->buildInFlightBatch('11111111-1111-1111-1111-111111111111');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);
        $this->ozonClient->method('pollOneReport')
            ->willThrowException(new OzonPermanentApiException('403 missing scope'));

        $this->em->expects(self::once())->method('flush');
        $this->ozonClient->expects(self::never())->method('fetchReportContent');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::FAILED, $batch->getState());
        self::assertNotNull($batch->getFinishedAt());
        self::assertStringContainsString('Poll permanent failure', (string) $batch->getLastError());
    }

    public function testInFlightWithoutUuidIsMarkedFailedAsSanityCheck(): void
    {
        // Строим батч напрямую, минуя Scheduler: ozon_uuid остаётся null,
        // но state выставляем в IN_FLIGHT через setter.
        $batch = AdScheduledBatchBuilder::aBatch()
            ->withId('22222222-2222-2222-2222-000000000001')
            ->withJobId('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withIndex(0)
            ->withState(AdScheduledBatchState::IN_FLIGHT)
            ->build();
        // Builder при IN_FLIGHT не проставляет ozonUuid (его нет в .withState).
        self::assertNull($batch->getOzonUuid(), 'sanity: batch специально без uuid');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batch]);

        $this->ozonClient->expects(self::never())->method('pollOneReport');
        $this->em->expects(self::once())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::FAILED, $batch->getState());
        self::assertStringContainsString('Invariant violation', (string) $batch->getLastError());
    }

    public function testTransientErrorOnOneBatchContinuesOthers(): void
    {
        $batchA = $this->buildInFlightBatch('11111111-1111-1111-1111-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-bbbb-bbbb-00000000000a');
        $batchB = $this->buildInFlightBatch('11111111-1111-1111-1111-bbbbbbbbbbbb', 'bbbbbbbb-bbbb-bbbb-bbbb-00000000000b');

        $this->batchRepo->method('findAllInFlight')->willReturn([$batchA, $batchB]);

        // Первый poll бросает transient, второй — OK.
        $this->ozonClient->expects(self::exactly(2))
            ->method('pollOneReport')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new \RuntimeException('Ozon 5xx')),
                ['state' => 'NOT_STARTED', 'raw' => []],
            );

        // Только первый batch не должен быть модифицирован, второй — тоже не трогаем
        // (NOT_STARTED = keep). Flush не должен вызываться вообще.
        $this->em->expects(self::never())->method('flush');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $batchA->getState(), 'A не трогаем: transient → следующий тик');
        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $batchB->getState(), 'B прошёл poll → NOT_STARTED → не трогаем');
    }

    private function buildInFlightBatch(
        string $companyId,
        string $batchId = 'bbbbbbbb-bbbb-bbbb-bbbb-000000000001',
    ): AdScheduledBatch {
        $batch = AdScheduledBatchBuilder::aBatch()
            ->withId($batchId)
            ->withJobId('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')
            ->withCompanyId($companyId)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::IN_FLIGHT)
            ->withOzonUuid('cccccccc-cccc-cccc-cccc-cccccccccccc')
            ->build();

        return $batch;
    }
}
