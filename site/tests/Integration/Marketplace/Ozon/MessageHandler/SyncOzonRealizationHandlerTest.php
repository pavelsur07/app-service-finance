<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\MessageHandler;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Marketplace\Message\ProcessOzonRealizationMessage;
use App\Marketplace\Message\SyncOzonRealizationMessage;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Infrastructure\Api\OzonRealizationFetcher;
use App\Marketplace\Ozon\MessageHandler\SyncOzonRealizationHandler;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\ParameterType;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncOzonRealizationHandlerTest extends IntegrationTestCase
{
    private const NOW = '2026-10-03 10:00:00 Europe/Moscow';

    private string $companyId;
    private string $connectionId;

    private int $month = 9;

    /** @var \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> */
    private \ArrayObject $logs;

    /** @var \ArrayObject<int, object> */
    private \ArrayObject $bus;

    /** @var \ArrayObject<string, bool> */
    private \ArrayObject $busControl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = new \ArrayObject();
        $this->bus = new \ArrayObject();
        $this->busControl = new \ArrayObject(['fails' => false]);
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        $this->companyId = (string) $company->getId();
        $this->connectionId = Uuid::uuid4()->toString();
        $this->connection->insert('marketplace_connections', [
            'id' => $this->connectionId, 'company_id' => $this->companyId, 'marketplace' => 'ozon', 'api_key' => 'plain-key', 'client_id' => '12345',
            'is_active' => true, 'connection_type' => 'seller', 'auth_status' => 'ok', 'auth_failure_count' => 0,
            'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
        ], ['is_active' => ParameterType::BOOLEAN]);
    }

    public function testRowsAreStoredOverwrittenAndHashedByContent(): void
    {
        $this->handle(new MockResponse($this->body([['sku' => '1', 'price' => 10]])));

        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::RAW_LOADED, $status->getStatus());
        self::assertSame(1, $status->getRecordsCount());
        self::assertNotNull($status->getRowsHash());
        self::assertNotNull($status->getRawDocumentId());
        $firstHash = $status->getRowsHash();
        $docId = $status->getRawDocumentId();

        // Повторная загрузка с другими строками перезаписывает тот же документ, хеш меняется.
        $this->handle(new MockResponse($this->body([['sku' => '1', 'price' => 10], ['sku' => '2', 'price' => 20]])));

        $status = $this->pairStatus();
        self::assertSame($docId, $status->getRawDocumentId());
        self::assertSame(2, $status->getRecordsCount());
        self::assertNotSame($firstHash, $status->getRowsHash());
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_raw_documents WHERE company_id = :c AND document_type = 'realization'", ['c' => $this->companyId]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT records_count FROM marketplace_raw_documents WHERE id = :id', ['id' => $docId]));
    }

    public function testLoadedReportQueuesProcessingOnce(): void
    {
        $this->handle(new MockResponse($this->body([['sku' => '1', 'price' => 10]])));

        self::assertCount(1, $this->bus);
        $message = $this->bus[0];
        self::assertInstanceOf(ProcessOzonRealizationMessage::class, $message);
        self::assertSame($this->companyId, $message->companyId);
        self::assertSame($this->connectionId, $message->connectionId);
        self::assertSame(2026, $message->year);
        self::assertSame(9, $message->month);
        self::assertSame($this->pairStatus()->getRawDocumentId(), $message->rawDocumentId);
    }

    public function testSameReportAfterSuccessIsNotProcessedAgain(): void
    {
        $this->handle(new MockResponse($this->body([['sku' => '1', 'price' => 10]])));
        $status = $this->pairStatus();
        $status->markProcessing();
        $status->markSuccess();
        $this->statuses()->save($status);
        $this->em->flush();
        $this->bus->exchangeArray([]);

        // Ozon отдал те же строки: успех сохраняется, обработка не ставится.
        $this->handle(new MockResponse($this->body([['sku' => '1', 'price' => 10]])));

        self::assertCount(0, $this->bus);
        self::assertSame(FinancialReportSyncStatus::SUCCESS, $this->pairStatus()->getStatus());

        // Ozon изменил отчёт: пара снова ждёт обработки.
        $this->handle(new MockResponse($this->body([['sku' => '1', 'price' => 11]])));

        self::assertCount(1, $this->bus);
        self::assertSame(FinancialReportSyncStatus::RAW_LOADED, $this->pairStatus()->getStatus());
    }

    public function testQueueingFailureMovesPairToRetryInsteadOfLeavingItStuck(): void
    {
        $this->busControl['fails'] = true;

        $this->handle(new MockResponse($this->body([['sku' => '1']])));

        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::FAILED, $status->getStatus());
        self::assertNotNull($status->getNextRetryAt());
        self::assertSame([], $this->logsOf('error'));
    }

    public function testSuccessfulPairIsNeverDemotedByAFailedRecheck(): void
    {
        $this->handle(new MockResponse($this->body([['sku' => '1', 'price' => 10]])));
        $status = $this->pairStatus();
        $status->markProcessing();
        $status->markSuccess();
        $this->statuses()->save($status);
        $this->em->flush();
        $this->bus->exchangeArray([]);

        foreach ([
            new MockResponse($this->body([])),
            new MockResponse('{"message":"not found"}', ['http_code' => 404]),
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]),
            new MockResponse('boom', ['http_code' => 503]),
            new MockResponse('{"message":"forbidden"}', ['http_code' => 403]),
        ] as $response) {
            $this->handle($response);
            self::assertSame(FinancialReportSyncStatus::SUCCESS, $this->pairStatus()->getStatus());
        }

        self::assertCount(0, $this->bus);
    }

    public function testFailureOutsideThePollWindowIsReturnedToMessengerForRetry(): void
    {
        $this->month = 7; // окно открыто только для сентября: ручная загрузка июля опросом не охвачена

        $this->expectException(RecoverableMessageHandlingException::class);

        $this->handle(new MockResponse('boom', ['http_code' => 503]));
    }

    public function testFailureInsideThePollWindowIsLeftToThePoll(): void
    {
        $this->handle(new MockResponse('boom', ['http_code' => 503]));

        self::assertSame(FinancialReportSyncStatus::FAILED, $this->pairStatus()->getStatus());
    }

    public function testRetryAfterIsCapped(): void
    {
        $this->handle(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 999999']]));

        self::assertSame('2026-10-03 11:00:00', $this->pairStatus()->getNextRetryAt()?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s'));
    }

    public function testEmptyResponseIsWaitingNotFailureAndKeepsNoDocument(): void
    {
        $this->handle(new MockResponse($this->body([])));

        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::EMPTY, $status->getStatus());
        self::assertSame('2026-10-03 11:00:00', $status->getNextRetryAt()?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_raw_documents WHERE company_id = :c AND document_type = 'realization'", ['c' => $this->companyId]));
        self::assertSame([], $this->logsOf('error'));
    }

    public function testEmptyResponseDoesNotWipeAlreadyLoadedDocument(): void
    {
        $this->handle(new MockResponse($this->body([['sku' => '1']])));
        $this->handle(new MockResponse($this->body([])));

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT records_count FROM marketplace_raw_documents WHERE company_id = :c AND document_type = 'realization'", ['c' => $this->companyId]));
    }

    public function testClientErrorMeansReportIsNotReadyYet(): void
    {
        $this->handle(new MockResponse('{"message":"not found"}', ['http_code' => 404]));

        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::EMPTY, $status->getStatus());
        self::assertNotNull($status->getNextRetryAt());
        self::assertSame([], $this->logsOf('error'));
    }

    public function testRateLimitUsesRetryAfter(): void
    {
        $this->handle(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]));

        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::FAILED, $status->getStatus());
        self::assertSame('2026-10-03 10:02:00', $status->getNextRetryAt()?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s'));
        self::assertSame(429, $status->getLastErrorStatusCode());
        self::assertSame([], $this->logsOf('error'));
    }

    public function testServerErrorAndTransportFailureRetryLater(): void
    {
        $this->handle(new MockResponse('boom', ['http_code' => 503]));
        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::FAILED, $status->getStatus());
        self::assertSame('2026-10-03 10:30:00', $status->getNextRetryAt()?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s'));

        $this->handle(new MockResponse('', ['error' => 'connection reset']));
        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::FAILED, $status->getStatus());
        self::assertSame([], $this->logsOf('error'));
    }

    public function testRejectedKeyIsTerminalAndNotAnIncident(): void
    {
        $this->handle(new MockResponse('{"message":"forbidden"}', ['http_code' => 403]));

        $status = $this->pairStatus();
        self::assertSame(FinancialReportSyncStatus::AUTH_FAILED, $status->getStatus());
        self::assertNull($status->getNextRetryAt());
        self::assertSame([], $this->logsOf('error'));
        self::assertNotSame([], $this->logsOf('warning'));
    }

    public function testRequestAsksForTheRightMonth(): void
    {
        $captured = new \ArrayObject();
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($captured): MockResponse {
            $captured->exchangeArray([$method, $url, json_decode((string) ($options['body'] ?? ''), true)]);

            return new MockResponse($this->body([['sku' => '1']]));
        });

        $this->runHandler($client);

        /** @var array{0: string, 1: string, 2: mixed} $request */
        $request = $captured->getArrayCopy();
        self::assertSame('POST', $request[0]);
        self::assertStringEndsWith('/v2/finance/realization', $request[1]);
        self::assertSame(['month' => 9, 'year' => 2026], $request[2]);
    }

    public function testInactiveConnectionIsSkippedWithoutStatus(): void
    {
        $this->connection->executeStatement('UPDATE marketplace_connections SET is_active = false WHERE id = :id', ['id' => $this->connectionId]);

        $this->handle(new MockResponse($this->body([['sku' => '1']])));

        self::assertNull($this->statuses()->findByBusinessDay($this->companyId, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, new \DateTimeImmutable('2026-09-01')));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function body(array $rows): string
    {
        return json_encode(['result' => ['header' => ['start_date' => '2026-09-01', 'stop_date' => '2026-09-30'], 'rows' => $rows]], \JSON_THROW_ON_ERROR);
    }

    private function handle(MockResponse $response): void
    {
        $this->runHandler(new MockHttpClient([$response]));
    }

    private function runHandler(MockHttpClient $client): void
    {
        $this->em->clear();
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> $logs */
            public function __construct(private readonly \ArrayObject $logs)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs->append(['level' => (string) $level, 'message' => (string) $message, 'context' => $context]);
            }
        };

        $handler = new SyncOzonRealizationHandler(
            $this->em,
            new OzonRealizationFetcher($client, new NullLogger(), self::getContainer()->get(ConnectionApiKeyCodec::class)),
            $this->statuses(),
            $this->messageBus(),
            new LockFactory(new InMemoryStore()),
            new MockClock(self::NOW),
            $logger,
        );

        $handler(new SyncOzonRealizationMessage($this->companyId, $this->connectionId, 2026, $this->month));
    }

    private function messageBus(): MessageBusInterface
    {
        return new class($this->bus, $this->busControl) implements MessageBusInterface {
            /**
             * @param \ArrayObject<int, object> $dispatched
             * @param \ArrayObject<string, bool> $control
             */
            public function __construct(private readonly \ArrayObject $dispatched, private readonly \ArrayObject $control)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->control['fails']) {
                    throw new \RuntimeException('bus down');
                }
                $this->dispatched->append($message);

                return new Envelope($message);
            }
        };
    }

    private function statuses(): MarketplaceFinancialReportSyncStatusRepository
    {
        return self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);
    }

    private function pairStatus(): MarketplaceFinancialReportSyncStatus
    {
        $this->em->clear();
        $status = $this->statuses()->findByBusinessDay($this->companyId, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, new \DateTimeImmutable(sprintf('2026-%02d-01', $this->month)));
        self::assertNotNull($status);
        self::assertSame(FinancialReportSyncMode::MANUAL, $status->getMode());

        return $status;
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    private function logsOf(string $level): array
    {
        return array_values(array_filter($this->logs->getArrayCopy(), static fn (array $r): bool => $level === $r['level']));
    }
}
