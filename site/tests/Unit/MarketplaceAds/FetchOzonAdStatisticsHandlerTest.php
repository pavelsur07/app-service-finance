<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
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
 * Покрываемые инварианты:
 *  1. Happy-path: markChunkCompleted вызван с корректными датами, вернул true.
 *  2. Duplicate (markChunkCompleted → false): info-лог, handler завершился OK.
 *  3. Permanent error (OzonPermanentApiException): markChunkCompleted НЕ вызван.
 *  4. Transient error (RuntimeException): markChunkCompleted НЕ вызван.
 *  5. Пустой результат Ozon: markChunkCompleted всё равно вызван.
 */
final class FetchOzonAdStatisticsHandlerTest extends TestCase
{
    private const JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const DATE_FROM = '2026-03-01';
    private const DATE_TO = '2026-03-03';

    /**
     * Сценарий 1: happy-path.
     *
     * Ozon вернул 3 документа, markChunkCompleted вызван ровно один раз с корректными
     * датами чанка и вернул true (первая фиксация).
     */
    public function testHappyPathMarkChunkCompletedCalledWithCorrectDatesAndReturnsTrue(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(self::JOB_ID, self::COMPANY_ID, 3)
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('incrementFailedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->expects(self::once())
            ->method('fetchAdStatisticsRange')
            ->willReturn([
                '2026-03-01' => ['rows' => [['spend' => 100]]],
                '2026-03-02' => ['rows' => [['spend' => 200]]],
                '2026-03-03' => ['rows' => [['spend' => 300]]],
            ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $rawRepo->expects(self::exactly(3))->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-01' === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-03-03' === $d->format('Y-m-d')),
            )
            ->willReturn(true);

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);
        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 2: duplicate (markChunkCompleted → false).
     *
     * При Messenger retry тот же чанк обрабатывается повторно. markChunkCompleted
     * возвращает false. Handler пишет info-лог и завершается без ошибки.
     */
    public function testDuplicateChunkLogsInfoAndHandlerCompletesOk(): void
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
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([
            '2026-03-01' => ['rows' => [['spend' => 100]]],
        ]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->method('findByMarketplaceAndDate')->willReturn(null);
        $rawRepo->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->willReturn(false);

        $infoLogged = false;
        $hub = $this->createMock(HubInterface::class);
        $logger = new class(new NullLogger(), $hub) extends AppLogger {
            public bool $infoLogged = false;

            public function info(string $message, array $context = []): void
            {
                if (str_contains($message, 'chunk already marked completed')) {
                    $this->infoLogged = true;
                }
                parent::info($message, $context);
            }
        };

        $handler = new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $em,
            $messageBus,
            $logger,
        );

        $handler(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));

        self::assertTrue($logger->infoLogged, 'info-лог "chunk already marked completed" должен быть записан');
    }

    /**
     * Сценарий 3: permanent error (OzonPermanentApiException).
     *
     * markChunkCompleted НЕ вызывается: permanent ошибка выбрасывает
     * UnrecoverableMessageHandlingException до happy-path.
     */
    public function testPermanentErrorDoesNotCallMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, self::stringContains('Ozon API permanent failure'))
            ->willReturn(1);

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')
            ->willThrowException(new OzonPermanentApiException('403 — нет скоупа «Продвижение»'));

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Ozon permanent failure');

        $handler(new FetchOzonAdStatisticsMessage(
            AdLoadJobBuilder::DEFAULT_ID,
            self::COMPANY_ID,
            self::DATE_FROM,
            self::DATE_TO,
        ));
    }

    /**
     * Сценарий 4: transient error (RuntimeException).
     *
     * markChunkCompleted НЕ вызывается: transient ошибка выбрасывается
     * «голой» (Messenger ретраит).
     */
    public function testTransientErrorDoesNotCallMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()->asRunning()->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::never())->method('markFailed');
        $jobRepo->expects(self::never())->method('incrementFailedDays');
        $jobRepo->expects(self::never())->method('incrementLoadedDays');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')
            ->willThrowException(new \RuntimeException('Ozon 502 Bad Gateway'));

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::never())->method('markChunkCompleted');

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);

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

    /**
     * Сценарий 5: пустой результат Ozon.
     *
     * Ozon вернул пустой массив (ни одной кампании за чанк).
     * markChunkCompleted всё равно должен быть вызван: чанк отработал,
     * пустой ответ — легитимный результат, а не ошибка.
     */
    public function testEmptyOzonResultStillCallsMarkChunkCompleted(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withDateRange(new \DateTimeImmutable(self::DATE_FROM), new \DateTimeImmutable(self::DATE_TO))
            ->asRunning()
            ->build();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('findByIdAndCompany')->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('incrementLoadedDays')
            ->with(AdLoadJobBuilder::DEFAULT_ID, self::COMPANY_ID, 3)
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('incrementFailedDays');
        $jobRepo->expects(self::never())->method('markFailed');

        $ozonClient = $this->createMock(OzonAdClient::class);
        $ozonClient->method('fetchAdStatisticsRange')->willReturn([]);

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::never())->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $chunkProgressRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $chunkProgressRepo->expects(self::once())
            ->method('markChunkCompleted')
            ->with(
                AdLoadJobBuilder::DEFAULT_ID,
                self::COMPANY_ID,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn(true);

        $handler = $this->createHandler($ozonClient, $rawRepo, $jobRepo, $chunkProgressRepo, $em, $messageBus);
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
        AdChunkProgressRepositoryInterface $chunkProgressRepo,
        EntityManagerInterface $em,
        MessageBusInterface $messageBus,
    ): FetchOzonAdStatisticsHandler {
        return new FetchOzonAdStatisticsHandler(
            $ozonClient,
            $rawRepo,
            $jobRepo,
            $chunkProgressRepo,
            $em,
            $messageBus,
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
        );
    }
}
