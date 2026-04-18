<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
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
 * Фокус — идемпотентная фиксация факта завершения чанка через
 * {@see AdChunkProgressRepositoryInterface::markChunkCompleted}:
 *  - вызывается ровно один раз и только когда чанк реально отработан;
 *  - получает DateTimeImmutable dateFrom/dateTo, разобранные из message-строк;
 *  - повторный вызов (Messenger retry) даёт false — Handler не падает, только info-лог;
 *  - при permanent/transient ошибках Ozon API НЕ вызывается (иначе дубли
 *    в marketplace_ad_chunk_progress / подделка прогресса);
 *  - пустой результат Ozon считается успешной обработкой чанка — вызов происходит.
 */
final class FetchOzonAdStatisticsHandlerTest extends TestCase
{
    private const JOB_ID = AdLoadJobBuilder::DEFAULT_ID;
    private const COMPANY_ID = AdLoadJobBuilder::DEFAULT_COMPANY_ID;
    private const DATE_FROM = '2026-03-01';
    private const DATE_TO = '2026-03-03';

    public function testHappyPathCallsMarkChunkCompletedWithParsedDates(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 100]]],
            '2026-03-02' => ['rows' => [['spend' => 200]]],
            '2026-03-03' => ['rows' => [['spend' => 300]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $rawRepo->method('save')->willReturnCallback(static function (AdRawDocument $doc): void {});

        $em = $this->createMock(EntityManagerInterface::class);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );

        $chunkProgress = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgress->expects(self::once())
            ->method('markChunkCompleted')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                self::callback(static fn (\DateTimeImmutable $d): bool => self::DATE_FROM === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => self::DATE_TO === $d->format('Y-m-d')),
            )
            ->willReturn(true);

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgress, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testDuplicateChunkIsLoggedAndHandlerFinishesWithoutError(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            self::DATE_FROM => ['rows' => [['spend' => 1]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );

        $chunkProgress = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgress->expects(self::once())
            ->method('markChunkCompleted')
            ->willReturn(false); // повторная доставка — Handler не должен упасть

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $infoCalls = [];
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context = []) use (&$infoCalls): void {
                $infoCalls[] = $message;
            },
        );

        $handler = $this->createHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgress,
            $em,
            $messageBus,
            new AppLogger($logger, $this->createMock(HubInterface::class)),
        );

        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_FROM,
        ));

        self::assertNotSame([], $infoCalls, 'duplicate chunk должен писать info-лог');
    }

    public function testOzonPermanentApiExceptionDoesNotCallMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')
            ->willThrowException(new OzonPermanentApiException('403 — нет скоупа «Продвижение»'));

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);

        $chunkProgress = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgress->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgress, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testTransientRuntimeExceptionDoesNotCallMarkChunkCompleted(): void
    {
        // Инкрементить прогресс при transient-ошибке нельзя: Messenger ретраит
        // сообщение, и каждый retry подделывал бы счётчик выполненных чанков.
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')
            ->willThrowException(new \RuntimeException('Ozon 502 Bad Gateway'));

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);

        $chunkProgress = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgress->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgress, $em, $messageBus);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ozon 502 Bad Gateway');

        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    public function testEmptyOzonResultStillCallsMarkChunkCompleted(): void
    {
        // Крайний случай: Ozon вернул пустой массив (ни одной кампании за весь чанк).
        // Чанк физически отработан, поэтому markChunkCompleted всё равно обязан
        // сработать — иначе countCompletedChunks никогда не дорастёт до chunksTotal
        // и job не финализируется.
        $job = AdLoadJobBuilder::aJob()
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgress = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgress->expects(self::once())
            ->method('markChunkCompleted')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                self::callback(static fn (\DateTimeImmutable $d): bool => self::DATE_FROM === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => self::DATE_TO === $d->format('Y-m-d')),
            )
            ->willReturn(true);

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgress, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    private function createHandler(
        OzonAdClient $ozonClient,
        AdRawDocumentRepository $rawRepo,
        AdLoadJobRepository $jobRepo,
        AdChunkProgressRepositoryInterface $chunkProgress,
        EntityManagerInterface $em,
        MessageBusInterface $messageBus,
        ?AppLogger $logger = null,
    ): FetchOzonAdStatisticsHandler {
        return new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgress,
            $em,
            $messageBus,
            $logger ?? new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
        );
    }
}
