<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
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
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
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
 * Ключевые инварианты, которые проверяем:
 *  - Happy path: save() → flush() → incrementLoadedDays() → dispatch(ProcessAdRawDocumentMessage)
 *    в строгом порядке. Нарушение порядка приводит к race condition в
 *    ProcessAdRawDocumentHandler (увидит message, а документа в БД ещё нет).
 *  - Upsert: для существующего дня вместо new AdRawDocument вызывается updatePayload()
 *    (который сам сбрасывает status в DRAFT — двойной resetToDraft() сломал бы entity).
 *  - Терминальный job и отсутствующий job — no-op, API Ozon не дёргается.
 *  - Error taxonomy: InvalidArgumentException + 403 → markFailed + Unrecoverable;
 *    прочие 5xx / сеть → rethrow (Messenger сам делает retry, прогресс чанка не двигаем).
 *  - json_encode() === false для одного дня: этот день пропускается, остальные дни
 *    загружаются как обычно. Это устойчивость к «кривой» записи без срыва чанка.
 */
final class FetchOzonAdStatisticsHandlerTest extends TestCase
{
    private const JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const DATE_FROM = '2026-03-01';
    private const DATE_TO = '2026-03-03';

    public function testHappyPathCreatesThreeDocumentsAndDispatchesInOrder(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::once())
            ->method('fetchAdStatisticsRange')
            ->with(
                self::COMPANY_ID,
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-01 00:00:00' === $d->format('Y-m-d H:i:s')),
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-03 00:00:00' === $d->format('Y-m-d H:i:s')),
            )
            ->willReturn([
                '2026-03-01' => ['rows' => [['spend' => 100]]],
                '2026-03-02' => ['rows' => [['spend' => 200]]],
                '2026-03-03' => ['rows' => [['spend' => 300]]],
            ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);

        $savedDocs = [];
        $rawRepo->expects(self::exactly(3))
            ->method('save')
            ->willReturnCallback(static function (AdRawDocument $doc) use (&$savedDocs): void {
                $savedDocs[] = $doc;
            });

        $callOrder = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('flush')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'flush';
            });

        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(self::JOB_ID, self::COMPANY_ID, 3)
            ->willReturnCallback(static function () use (&$callOrder): int {
                $callOrder[] = 'incrementLoadedDays';

                return 1;
            });
        $jobRepo->expects(self::once())
            ->method('incrementChunksCompleted')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturnCallback(static function () use (&$callOrder): int {
                $callOrder[] = 'incrementChunksCompleted';

                return 1;
            });

        $dispatchedIds = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$callOrder, &$dispatchedIds): Envelope {
                self::assertInstanceOf(ProcessAdRawDocumentMessage::class, $message);
                self::assertSame(self::COMPANY_ID, $message->companyId);
                $callOrder[] = 'dispatch';
                $dispatchedIds[] = $message->adRawDocumentId;

                return new Envelope($message);
            });

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertCount(3, $savedDocs);
        self::assertSame(
            ['flush', 'incrementLoadedDays', 'dispatch', 'dispatch', 'dispatch', 'incrementChunksCompleted'],
            $callOrder,
            'save→flush→incrementLoadedDays→dispatch→incrementChunksCompleted — строгий порядок',
        );
        self::assertSame(
            array_map(static fn (AdRawDocument $d): string => $d->getId(), $savedDocs),
            $dispatchedIds,
            'Dispatch идёт за тот же набор документов, что сохранили (в том же порядке)',
        );
    }

    public function testUpsertCallsUpdatePayloadForExistingDay(): void
    {
        // Сценарий: единственный день уже есть в БД → upsert идёт по ветке
        // `updated`, `created` пустой. Ledger (AdChunkProgressRepository) для
        // этого (jobId, range) уже содержит запись — tryMarkCompleted вернёт
        // false, chunks_completed инкрементить не будем. loaded_days всё равно
        // увеличиваем по coverage чанка — он информационный и в условие
        // финализации не входит (см. ProcessAdRawDocumentHandler).
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $existing = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable(self::DATE_FROM))
            ->withRawPayload('{"old":true}')
            ->asProcessed() // после updatePayload() должен вернуться в DRAFT
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, 1)
            ->willReturn(1);
        // Retry-fetch: chunks_completed НЕ инкрементим.
        $jobRepo->expects(self::never())->method('incrementChunksCompleted');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            self::DATE_FROM => ['rows' => [['spend' => 42]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::once())
            ->method('findByMarketplaceAndDate')
            ->with(self::COMPANY_ID, MarketplaceType::OZON->value, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn($existing);
        $rawRepo->expects(self::never())->method('save'); // для существующего — никакого persist

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use ($existing): Envelope {
                self::assertInstanceOf(ProcessAdRawDocumentMessage::class, $msg);
                self::assertSame($existing->getId(), $msg->adRawDocumentId);

                return new Envelope($msg);
            });

        $handler = $this->createHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $em,
            $messageBus,
            $this->createChunkProgressRepoReturning(false), // ledger уже содержит эту пару — retry
        );
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_FROM,
        ));

        // updatePayload() сбросил статус → документ снова DRAFT и payload обновлён.
        self::assertSame(AdRawDocumentStatus::DRAFT, $existing->getStatus());
        self::assertStringContainsString('spend', $existing->getRawPayload());
    }

    public function testTerminalJobSkipsOzonCall(): void
    {
        $job = AdLoadJobBuilder::aJob()->asFailed('предыдущая ошибка')->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');
        $jobRepo->expects(self::never())->method('incrementChunksCompleted');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('fetchAdStatisticsRange');

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        self::assertTrue($job->getStatus()->isTerminal(), 'sanity check: AdLoadJobStatus::FAILED is terminal');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testJobNotFoundSkipsOzonCall(): void
    {
        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn(null);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementChunksCompleted');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('fetchAdStatisticsRange');

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

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
        $jobRepo->expects(self::never())->method('incrementChunksCompleted');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')
            ->willThrowException(new \InvalidArgumentException('Диапазон превышает 62 дня'));

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('invalid date range');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testOzonPermanentApiExceptionMarksFailedAndThrowsUnrecoverable(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::stringContains('Ozon API permanent failure'),
            )
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('incrementChunksCompleted');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')
            ->willThrowException(new OzonPermanentApiException('403 — нет скоупа «Продвижение»'));

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Ozon permanent failure');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testTransientFailureDoesNotTouchCountersAndRethrows(): void
    {
        // Инкремент failed_days здесь был бы багом: Messenger ретраит
        // сообщение до max_retries раз, так что одна «настоящая» поломка
        // чанка давала бы failed_days = (max_retries + 1) · chunkDays и
        // ломала прогресс (сумма счётчиков ушла бы выше total_days).
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');
        $jobRepo->expects(self::never())->method('incrementChunksCompleted');

        $apiException = new \RuntimeException('Ozon 502 Bad Gateway');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willThrowException($apiException);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);

        // Важно: transient НЕ должен стать Unrecoverable — Messenger обязан сделать retry
        // по стратегии async транспорта. Поэтому ожидаем исходный RuntimeException, а не
        // UnrecoverableMessageHandlingException.
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
        // Чанк физически отработан (частичный json-fail не срывает чанк) —
        // chunksCompleted инкрементится один раз на сообщение.
        $jobRepo->expects(self::once())
            ->method('incrementChunksCompleted')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID)
            ->willReturn(1);

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

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testDispatchHappensStrictlyAfterFlush(): void
    {
        // Отдельный тест на порядок: flush() ОБЯЗАН завершиться до первого dispatch().
        // Иначе ProcessAdRawDocumentHandler.findByIdAndCompany() вернёт null — документа
        // в БД ещё нет, хотя message уже в очереди.
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);
        $jobRepo->method('incrementChunksCompleted')->willReturn(1);

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

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            '2026-03-02',
        ));
    }

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
        $jobRepo->expects(self::never())->method('incrementChunksCompleted');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('fetchAdStatisticsRange');

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('invalid date format');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            'not-a-date',
            self::DATE_TO,
        ));
    }

    public function testOzonReturnsFewerDaysThanChunkStillCountsCoverageAsLoaded(): void
    {
        // P1 regression: Ozon легитимно может вернуть меньше дней, чем запросили
        // (те дни, где не было ни одной активной кампании). Если бы loaded_days
        // инкрементировался по count($documents), такие «пустые» дни навсегда
        // оставались бы непосчитанными в прогрессе — (loaded + failed) не
        // дорастал бы до total_days, и getProgress() не достигал 100%.
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
        $jobRepo->expects(self::once())
            ->method('incrementChunksCompleted')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID)
            ->willReturn(1);

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

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testOzonReturnsNoDaysStillCountsEntireChunkAsLoadedAndIncrementsChunksCompleted(): void
    {
        // Крайний случай: Ozon вернул пустой массив (ни одной кампании за весь чанк).
        // Чанк отработал успешно → все chunkDays дней должны попасть в loaded_days,
        // а chunksCompleted — вырасти на 1 (иначе chunksTotal никогда не совпадёт
        // с chunksCompleted и ProcessAdRawDocumentHandler не финализирует job).
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, 3)
            ->willReturn(1);
        $jobRepo->expects(self::once())
            ->method('incrementChunksCompleted')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID)
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        // flush всё равно зовём — UoW очищает пустую очередь без вреда, а «пропускать»
        // flush при пустом результате значило бы размазать условную логику по handler'у.
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testCalendarInvalidDateInMessageMarksFailedAndThrowsUnrecoverable(): void
    {
        // P2 regression: createFromFormat('!Y-m-d', '2026-02-31') НЕ возвращает false —
        // тихо нормализует в 2026-03-03. Без round-trip сравнения handler грузил бы
        // не тот диапазон, а операторский баг молча корраптил бы соседние даты.
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
        $jobRepo->expects(self::never())->method('incrementChunksCompleted');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::never())->method('fetchAdStatisticsRange');

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('2026-02-31');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            '2026-02-31',
            self::DATE_TO,
        ));
    }

    public function testRetryOfOrchestratorDoesNotIncrementChunksCompleted(): void
    {
        // Сценарий: оркестратор ретраится после частичного dispatch'а. Ledger
        // уже содержит запись (jobId, dateFrom, dateTo) с первого fetch'а —
        // tryMarkCompleted вернёт false, chunks_completed не инкрементим.
        // Re-dispatch ProcessAdRawDocumentMessage сохраняется (перепроцессинг
        // идемпотентен на стороне handler'а).
        $job = AdLoadJobBuilder::aJob()
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $existingByDate = [
            '2026-03-01' => AdRawDocumentBuilder::aRawDocument()
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-03-01'))
                ->withRawPayload('{"old":1}')
                ->build(),
            '2026-03-02' => AdRawDocumentBuilder::aRawDocument()
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-03-02'))
                ->withRawPayload('{"old":2}')
                ->build(),
            '2026-03-03' => AdRawDocumentBuilder::aRawDocument()
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-03-03'))
                ->withRawPayload('{"old":3}')
                ->build(),
        ];

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);
        $jobRepo->expects(self::never())->method('incrementChunksCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 11]]],
            '2026-03-02' => ['rows' => [['spend' => 22]]],
            '2026-03-03' => ['rows' => [['spend' => 33]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')
            ->willReturnCallback(static function (string $companyId, string $marketplace, \DateTimeImmutable $date) use ($existingByDate): ?AdRawDocument {
                return $existingByDate[$date->format('Y-m-d')] ?? null;
            });
        $rawRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $handler = $this->createHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $em,
            $messageBus,
            $this->createChunkProgressRepoReturning(false), // retry: ledger уже содержит запись
        );
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testFirstFetchWithMixedCreatedAndUpdatedIncrementsChunksCompleted(): void
    {
        // Сценарий: часть документов уже была после ручного/CLI preload'а,
        // часть создаётся впервые. Для этого (jobId, range) в ledger'е записи
        // НЕТ — tryMarkCompleted вернёт true (первая вставка), инкрементим
        // chunks_completed. Это ключевой кейс: старая эвристика по
        // created/updated могла бы пропустить инкремент при полном preload'е,
        // ledger этого избегает.
        $job = AdLoadJobBuilder::aJob()
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $existingOnMiddleDay = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-03-02'))
            ->withRawPayload('{"old":true}')
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);
        $jobRepo->expects(self::once())
            ->method('incrementChunksCompleted')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID)
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 1]]],
            '2026-03-02' => ['rows' => [['spend' => 2]]],
            '2026-03-03' => ['rows' => [['spend' => 3]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')
            ->willReturnCallback(static function (string $c, string $m, \DateTimeImmutable $d) use ($existingOnMiddleDay): ?AdRawDocument {
                return '2026-03-02' === $d->format('Y-m-d') ? $existingOnMiddleDay : null;
            });
        $rawRepo->expects(self::exactly(2))->method('save'); // 2 новых дня

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testFirstFetchWithAllDaysPreExistingStillIncrementsChunksCompleted(): void
    {
        // Regression: раньше эвристика `created === [] && updated !== []`
        // пропускала инкремент, если все дни чанка уже существовали в БД
        // (например, CLI-preload до orchestrator-run'а). Новый jobId не
        // продвигался бы, и job застрял бы в RUNNING. Ledger смотрит на
        // (jobId, range): для нового job'а записи нет → tryMarkCompleted=true
        // → chunks_completed инкрементится нормально.
        $job = AdLoadJobBuilder::aJob()
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $existingByDate = [
            '2026-03-01' => AdRawDocumentBuilder::aRawDocument()
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-03-01'))
                ->build(),
            '2026-03-02' => AdRawDocumentBuilder::aRawDocument()
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-03-02'))
                ->build(),
            '2026-03-03' => AdRawDocumentBuilder::aRawDocument()
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-03-03'))
                ->build(),
        ];

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);
        // Ключевое отличие от testRetryOfOrchestrator... — несмотря на 0
        // created + 3 updated, chunks_completed ДОЛЖЕН быть инкрементирован:
        // ledger для этого jobId не содержит записи (первый fetch).
        $jobRepo->expects(self::once())
            ->method('incrementChunksCompleted')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID)
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 1]]],
            '2026-03-02' => ['rows' => [['spend' => 2]]],
            '2026-03-03' => ['rows' => [['spend' => 3]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')
            ->willReturnCallback(static function (string $c, string $m, \DateTimeImmutable $d) use ($existingByDate): ?AdRawDocument {
                return $existingByDate[$d->format('Y-m-d')] ?? null;
            });
        $rawRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $handler = $this->createHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $em,
            $messageBus,
            $this->createChunkProgressRepoReturning(true), // первый fetch этого jobId
        );
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testLedgerTryMarkCompletedReceivesChunkRange(): void
    {
        // Контракт вызова ledger'а: handler обязан передать ТОЧНЫЕ jobId /
        // companyId / dateFrom / dateTo из сообщения. Без этого UNIQUE-ключ
        // (job_id, date_from, date_to) не сработает и идемпотентность
        // рассыпется.
        $job = AdLoadJobBuilder::aJob()
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->method('incrementLoadedDays')->willReturn(1);
        $jobRepo->method('incrementChunksCompleted')->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('tryMarkCompleted')
            ->with(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::callback(static fn (\DateTimeImmutable $d): bool => self::DATE_FROM === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => self::DATE_TO === $d->format('Y-m-d')),
            )
            ->willReturn(true);

        $handler = $this->createHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $em,
            $messageBus,
            $chunkProgressRepo,
        );
        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    private function createHandler(
        OzonAdClient $ozonClient,
        AdRawDocumentRepository $rawRepo,
        AdLoadJobRepository $jobRepo,
        EntityManagerInterface $em,
        MessageBusInterface $messageBus,
        ?AdChunkProgressRepositoryInterface $chunkProgressRepo = null,
    ): FetchOzonAdStatisticsHandler {
        return new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo ?? $this->createChunkProgressRepoReturning(true),
            $em,
            $messageBus,
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
        );
    }

    /**
     * Мок ledger-репозитория с фиксированным ответом.
     *
     * true  — «чанк учтён впервые», handler инкрементит chunks_completed;
     * false — уже был учтён (любой retry), инкремент пропускается.
     */
    private function createChunkProgressRepoReturning(bool $isNew): AdChunkProgressRepositoryInterface
    {
        $mock = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $mock->method('tryMarkCompleted')->willReturn($isNew);

        return $mock;
    }
}
