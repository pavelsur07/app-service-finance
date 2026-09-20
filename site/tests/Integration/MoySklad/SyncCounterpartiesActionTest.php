<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\SyncCounterpartiesAction;
use App\MoySklad\Application\CounterpartyPageParser;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\CounterpartySyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladCounterpartyRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use App\MoySklad\Message\SyncCounterpartiesMessage;
use App\MoySklad\MessageHandler\SyncCounterpartiesHandler;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class SyncCounterpartiesActionTest extends WebTestCaseBase
{
    private const ACCOUNT_ID = '00000000-0000-4000-8000-000000000002';

    public function testLoadsBothPassesAndRepeatedRunDoesNotDuplicateRows(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $activePage = json_decode($this->page('counterparties_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $activePage['rows'][0]['updated'] = '2026-01-02 03:04:09.000';
        $activeResponse = json_encode($activePage, \JSON_THROW_ON_ERROR);

        $first = $this->action([
            new MockResponse($activeResponse),
            new MockResponse($this->page('counterparties_archived_page_0.json')),
        ])($connection->getCompanyId(), $connection->getId());
        self::assertNotNull($first);
        self::assertSame('succeeded', $first->getStatus());
        self::assertSame(4, $first->getCreated());
        $db = $this->em()->getConnection();
        self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties'));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties WHERE archived'));

        $second = $this->action([
            new MockResponse($activeResponse),
            new MockResponse($this->page('counterparties_archived_page_0.json')),
        ])($connection->getCompanyId(), $connection->getId());
        self::assertNotNull($second);
        self::assertSame(4, $second->getUnchanged());
        self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties'));
        $cursor = static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'counterparty');
        self::assertNotNull($cursor?->getLastCompletedAt());
    }

    public function testAdvancesOffsetAcrossTwoActivePages(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $firstPage = json_decode($this->page('counterparties_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $template = $firstPage['rows'][0];
        $firstPage['rows'] = [];
        for ($i = 1000; $i < 1100; ++$i) {
            $row = $template;
            $row['id'] = sprintf('00000000-0000-4000-8000-%012d', $i);
            $firstPage['rows'][] = $row;
        }
        $firstPage['meta']['size'] = 102;
        $second = new MockResponse($this->page('counterparties_active_page_2.json', 100));
        $run = $this->action([
            new MockResponse(json_encode($firstPage, \JSON_THROW_ON_ERROR)),
            $second,
            new MockResponse($this->page('counterparties_archived_empty.json')),
        ])($connection->getCompanyId(), $connection->getId());

        self::assertSame('succeeded', $run?->getStatus());
        self::assertSame(102, $run->getProcessed());
        self::assertSame(102, $run->getCreated());
        self::assertStringContainsString('offset=100', $second->getRequestUrl());
        self::assertSame(102, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties'));
    }

    public function testFailedSecondPassKeepsCommittedFirstPageAndOldCursor(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        try {
            $this->action([
                new MockResponse($this->page('counterparties_active_page_0.json')),
                new MockResponse('', ['http_code' => 503]),
            ])($connection->getCompanyId(), $connection->getId());
            self::fail('Second pass must fail.');
        } catch (CounterpartySyncException $e) {
            self::assertSame('temporary', $e->category);
        }
        $db = $this->em()->getConnection();
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties'));
        $run = static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'counterparty');
        self::assertSame('failed', $run?->getStatus());
        self::assertSame(2, $run->getProcessed());
        self::assertNull(static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'counterparty'));
    }

    public function testAnotherCompanyCannotStartConnection(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $result = $this->action([])('99999999-9999-4999-8999-999999999999', $connection->getId());
        self::assertNull($result);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_sync_runs'));
    }

    public function testUpdatedRowsAreVisibleButFailedRunDoesNotMoveCompletedCursor(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $this->action([
            new MockResponse($this->page('counterparties_active_page_0.json')),
            new MockResponse($this->page('counterparties_archived_page_0.json')),
        ])($connection->getCompanyId(), $connection->getId());
        $cursor = static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'counterparty');
        self::assertNotNull($cursor);
        $completedAt = $cursor->getLastCompletedAt()?->format('U.u');
        $changed = json_decode($this->page('counterparties_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $changed['rows'][0]['name'] = 'Обновлённый контрагент';
        unset($changed['rows'][0]['inn']);
        try {
            $this->action([
                new MockResponse(json_encode($changed, \JSON_THROW_ON_ERROR)),
                new MockResponse('', ['http_code' => 503]),
            ])($connection->getCompanyId(), $connection->getId());
            self::fail('Archived pass must fail.');
        } catch (CounterpartySyncException $e) {
            self::assertSame('temporary', $e->category);
        }
        $this->em()->clear();
        $record = static::getContainer()->get(MoySkladCounterpartyRepository::class)->findByExternalId($connection->getCompanyId(), $connection->getId(), '00000000-0000-4000-8000-000000000001');
        self::assertSame('Обновлённый контрагент', $record?->getName());
        self::assertNull($record->getInn());
        $cursor = static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'counterparty');
        self::assertSame($completedAt, $cursor?->getLastCompletedAt()?->format('U.u'));
        $run = static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'counterparty');
        self::assertSame(1, $run?->getUpdated());
    }

    public function testRateLimitSchedulesOneDelayedRetryWithoutLeakingResponse(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $bus = $this->createMock(MessageBusInterface::class);
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $bus->expects(self::once())->method('dispatch')->with(
            self::callback(static function (SyncCounterpartiesMessage $message) use ($connection): bool {
                self::assertSame($connection->getCompanyId(), $message->companyId);
                self::assertSame($connection->getId(), $message->connectionId);
                self::assertSame(1, $message->attempt);

                return true;
            }),
            self::callback(static function (array $stamps): bool {
                self::assertCount(1, $stamps);
                self::assertInstanceOf(DelayStamp::class, $stamps[0]);
                self::assertSame(15000, $stamps[0]->getDelay());

                return true;
            }),
        )->willReturn(new Envelope(new \stdClass()));
        $handler = new SyncCounterpartiesHandler($this->action([
            new MockResponse('private-response', ['http_code' => 429, 'response_headers' => ['X-Lognex-Retry-After: 15000']]),
        ], $logger), $bus, $logger);
        $handler(new SyncCounterpartiesMessage($connection->getCompanyId(), $connection->getId()));

        $run = static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'counterparty');
        self::assertSame('rate_limited', $run?->getErrorCategory());
        self::assertStringNotContainsString('private-response', json_encode($logger->records, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('test-secret', json_encode($logger->records, \JSON_THROW_ON_ERROR));
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('MoySklad counterparty sync message started', $logger->records[0]['message']);
        self::assertSame('MoySklad counterparty sync message finished', $logger->records[array_key_last($logger->records)]['message']);
        self::assertSame('retry_scheduled', $logger->records[array_key_last($logger->records)]['context']['outcome']);
        self::assertSame(0, $logger->records[array_key_last($logger->records)]['context']['attempt']);
    }

    public function testHandlerLogsStartAndTerminalOutcomeWhenConnectionIsSkipped(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $handler = new SyncCounterpartiesHandler($this->action([], $logger), $bus, $logger);
        $handler(new SyncCounterpartiesMessage('99999999-9999-4999-8999-999999999999', $connection->getId()));

        self::assertCount(2, $logger->records);
        self::assertSame('MoySklad counterparty sync message started', $logger->records[0]['message']);
        self::assertSame('MoySklad counterparty sync message finished', $logger->records[1]['message']);
        self::assertSame('skipped', $logger->records[1]['context']['outcome']);
        self::assertSame('99999999-9999-4999-8999-999999999999', $logger->records[1]['context']['companyId']);
        self::assertSame($connection->getId(), $logger->records[1]['context']['connectionId']);
        self::assertSame('counterparty', $logger->records[1]['context']['entityType']);
        self::assertSame(0, $logger->records[1]['context']['attempt']);
    }

    public function testRepairsStaleRunningRowBeforeNewPass(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->persist(new MoySkladSyncRun('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), 'counterparty', new \DateTimeImmutable('2026-09-19T09:00:00+00:00')));
        $this->em()->flush();

        $new = $this->action([
            new MockResponse($this->page('counterparties_active_empty.json')),
            new MockResponse($this->page('counterparties_archived_empty.json')),
        ])($connection->getCompanyId(), $connection->getId());
        self::assertSame('succeeded', $new?->getStatus());
        $stale = static::getContainer()->get(MoySkladSyncRunRepository::class)->findByIdAndCompanyId('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId());
        self::assertSame('failed', $stale?->getStatus());
        self::assertSame('internal', $stale->getErrorCategory());
    }

    public function testRetryExhaustionEmitsSafeErrorWithoutSchedulingAgain(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('MoySklad counterparty sync retry budget exhausted', self::callback(static function (array $context) use ($connection): bool {
            self::assertSame($connection->getCompanyId(), $context['companyId']);
            self::assertSame($connection->getId(), $context['connectionId']);
            self::assertSame('rate_limited', $context['category']);
            self::assertArrayHasKey('runId', $context);
            self::assertStringNotContainsString('private-response', json_encode($context, \JSON_THROW_ON_ERROR));

            return true;
        }));
        $handler = new SyncCounterpartiesHandler($this->action([
            new MockResponse('private-response', ['http_code' => 429]),
        ]), $bus, $logger);
        $handler(new SyncCounterpartiesMessage($connection->getCompanyId(), $connection->getId(), 3));
    }

    public function testConcurrentSessionLockPreventsSecondPass(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $other = DriverManager::getConnection($this->em()->getConnection()->getParams());
        $params = ['namespace' => 'moysklad-counterparty', 'key' => $connection->getId()];
        $other->fetchOne('SELECT pg_advisory_lock(hashtext(:namespace), hashtext(:key))', $params);
        try {
            $result = $this->action([])($connection->getCompanyId(), $connection->getId());
            self::assertNull($result);
            self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_sync_runs'));
        } finally {
            $other->fetchOne('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:key))', $params);
            $other->close();
        }
    }

    public function testFailedPageFlushRollsBackPageAndMarksRunFailed(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $page = json_decode($this->page('counterparties_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $page['rows'][1]['id'] = $page['rows'][0]['id'];

        try {
            $this->action([new MockResponse(json_encode($page, \JSON_THROW_ON_ERROR))])($connection->getCompanyId(), $connection->getId());
            self::fail('Duplicate external ID must fail the page.');
        } catch (CounterpartySyncException $e) {
            self::assertSame('internal', $e->category);
        }

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties'));
        $row = $this->em()->getConnection()->fetchAssociative('SELECT status, processed, error_category FROM moysklad_sync_runs WHERE connection_id = ?', [$connection->getId()]);
        self::assertIsArray($row);
        self::assertSame('failed', $row['status']);
        self::assertSame(0, (int) $row['processed']);
        self::assertSame('internal', $row['error_category']);
    }

    #[DataProvider('fatalPages')]
    public function testFatalPageKeepsOldCursorAndRows(int $status, string $body, string $category): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $this->action([
            new MockResponse($this->page('counterparties_active_page_0.json')),
            new MockResponse($this->page('counterparties_archived_page_0.json')),
        ])($connection->getCompanyId(), $connection->getId());
        $db = $this->em()->getConnection();
        $oldCursor = $db->fetchOne('SELECT last_completed_at FROM moysklad_sync_cursors WHERE connection_id = ?', [$connection->getId()]);
        try {
            $this->action([new MockResponse($body, ['http_code' => $status])])($connection->getCompanyId(), $connection->getId());
            self::fail('Fatal page expected.');
        } catch (CounterpartySyncException $e) {
            self::assertSame($category, $e->category);
        }
        self::assertSame($oldCursor, $db->fetchOne('SELECT last_completed_at FROM moysklad_sync_cursors WHERE connection_id = ?', [$connection->getId()]));
        self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties'));
        $run = static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'counterparty');
        self::assertSame($category, $run?->getErrorCategory());
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function fatalPages(): iterable
    {
        yield '401' => [401, '', 'auth'];
        yield '403' => [403, '', 'forbidden'];
        yield 'invalid response' => [200, '{', 'invalid_response'];
    }

    public function testCompletedPassWithoutRowsDoesNotDeletePreviouslyLoadedCounterparties(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $this->action([
            new MockResponse($this->page('counterparties_active_page_0.json')),
            new MockResponse($this->page('counterparties_archived_page_0.json')),
        ])($connection->getCompanyId(), $connection->getId());
        $run = $this->action([
            new MockResponse($this->page('counterparties_active_empty.json')),
            new MockResponse($this->page('counterparties_archived_empty.json')),
        ])($connection->getCompanyId(), $connection->getId());
        self::assertSame('succeeded', $run?->getStatus());
        self::assertSame(0, $run->getProcessed());
        self::assertSame(4, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties'));
    }

    public function testScheduledRetryRestartsAtZeroAndReusesCommittedRows(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $retry = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (SyncCounterpartiesMessage $message) use (&$retry): Envelope {
            $retry = $message;

            return new Envelope($message);
        });
        $firstHandler = new SyncCounterpartiesHandler($this->action([
            new MockResponse($this->page('counterparties_active_page_0.json')),
            new MockResponse('', ['http_code' => 503]),
        ]), $bus, new NullLogger());
        $firstHandler(new SyncCounterpartiesMessage($connection->getCompanyId(), $connection->getId()));
        self::assertInstanceOf(SyncCounterpartiesMessage::class, $retry);
        self::assertSame(1, $retry->attempt);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties'));

        $activeResponse = new MockResponse($this->page('counterparties_active_page_0.json'));
        $noMoreRetries = $this->createMock(MessageBusInterface::class);
        $noMoreRetries->expects(self::never())->method('dispatch');
        $retryHandler = new SyncCounterpartiesHandler($this->action([
            $activeResponse,
            new MockResponse($this->page('counterparties_archived_page_0.json')),
        ]), $noMoreRetries, new NullLogger());
        $retryHandler($retry);

        self::assertStringContainsString('offset=0', $activeResponse->getRequestUrl());
        self::assertSame(4, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_counterparties'));
        $run = static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'counterparty');
        self::assertSame('succeeded', $run?->getStatus());
        self::assertSame(2, $run->getCreated());
        self::assertSame(2, $run->getUnchanged());
    }

    private function verifiedConnection(): MoySkladConnection
    {
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $connection->bindAccount(self::ACCOUNT_ID);
        $connection->setAccessToken('test-secret');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());

        return $connection;
    }

    /** @param list<MockResponse> $responses */
    private function action(array $responses, ?LoggerInterface $logger = null): SyncCounterpartiesAction
    {
        return new SyncCounterpartiesAction(
            $this->em(),
            static::getContainer()->get(MoySkladConnectionWriteRepository::class),
            static::getContainer()->get(MoySkladCounterpartyRepository::class),
            static::getContainer()->get(MoySkladSyncCursorRepository::class),
            static::getContainer()->get(MoySkladSyncRunRepository::class),
            new MoySkladClient(new MockHttpClient($responses), 'https://example.test/api/remap/1.2'),
            new CounterpartyPageParser(),
            static::getContainer()->get(ConnectionTokenCodec::class),
            $logger ?? new NullLogger(),
        );
    }

    private function page(string $name, int $offset = 0): string
    {
        $body = file_get_contents(__DIR__.'/../../Fixtures/MoySklad/Counterparty/'.$name);
        self::assertIsString($body);
        $page = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        $page['meta']['limit'] = 100;
        $page['meta']['offset'] = $offset;

        return json_encode($page, \JSON_THROW_ON_ERROR);
    }
}
