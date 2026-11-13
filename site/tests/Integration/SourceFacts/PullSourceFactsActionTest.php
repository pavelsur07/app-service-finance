<?php

declare(strict_types=1);

namespace App\Tests\Integration\SourceFacts;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Facade\SourceFactsGatewayFacade;
use App\SourceFacts\Application\Action\PullSourceFactsAction;
use App\SourceFacts\Command\SourceFactsHealthCheckCommand;
use App\SourceFacts\Infrastructure\Query\SourceFactStore;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PullSourceFactsActionTest extends IntegrationTestCase
{
    private const DAY = '2026-11-11';

    private FakeSourceFactsClient $service;
    private TestHandler $logs;
    private MockClock $clock;
    private string $companyId;
    private string $connectionRef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FakeSourceFactsClient();
        $this->logs = new TestHandler();
        $this->clock = new MockClock('2026-11-12 08:00:00');

        $this->companyId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('sf-'.$this->companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build();
        $connection = new MarketplaceConnection(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON);
        $connection->setClientId('client-id');
        $connection->setApiKey('test-key');
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();
        $this->connectionRef = $connection->getId();
    }

    public function testAppliesBatchAndRepeatedDeliveryChangesNothing(): void
    {
        $batchId = $this->service->enqueue($this->connectionRef, self::DAY, 1, [
            [['ozon:accrual-by-day:1', ['accrual_id' => '1', 'amount' => 0.1]], ['ozon:accrual-by-day:2', ['accrual_id' => '2']]],
            [['ozon:accrual-by-day:3', ['accrual_id' => '3']], ['ozon:accrual-by-day:3', ['accrual_id' => '3']]],
        ]);
        $this->service->failPage['ack'] = new ServiceUnavailableException('ack lost');

        try {
            ($this->action())();
            self::fail('ack failure must interrupt the run');
        } catch (ServiceUnavailableException) {
        }
        $after = $this->counts();
        self::assertSame(['facts' => 3, 'periods' => 1, 'batches' => 1], $after, 'дубль записи внутри страницы не создаёт вторую строку');
        self::assertSame([], $this->service->acked);

        // ack не дошёл — сервис отдаёт пачку снова.
        unset($this->service->failPage['ack']);
        $result = ($this->action())();

        self::assertSame($after, $this->counts());
        self::assertSame([1, 0, 1], [$result->received, $result->applied, $result->superseded]);
        self::assertSame([$batchId], $this->service->acked);
        self::assertSame(SourceFactStore::STATUS_APPLIED, $this->connection->fetchOne('SELECT status FROM sf_batch'));
        self::assertSame(
            ['company_id' => $this->companyId, 'current_revision' => 1, 'fact_count' => 3, 'updated_at' => '2026-11-12 08:00:00'],
            $this->connection->fetchAssociative('SELECT company_id, current_revision, fact_count, updated_at FROM sf_period'),
        );
        self::assertSame('0.1', $this->connection->fetchOne("SELECT payload->>'amount' FROM sf_fact WHERE source_key = 'ozon:accrual-by-day:1'"));
    }

    public function testNewRevisionReplacesCurrentSetWithoutDeletingHistory(): void
    {
        $this->service->enqueue($this->connectionRef, self::DAY, 1, [[
            ['k:same', ['v' => 1]], ['k:changed', ['v' => 1]], ['k:gone', ['v' => 1]], ['k:returns', ['v' => 1]],
        ]]);
        $this->service->enqueue($this->connectionRef, self::DAY, 2, [[
            ['k:same', ['v' => 1]], ['k:changed', ['v' => 2]], ['k:new', ['v' => 1]],
        ]]);
        ($this->action())();

        self::assertSame(['k:changed' => 2, 'k:new' => 1, 'k:same' => 1], $this->current(2));
        self::assertSame(6, $this->counts()['facts'], 'старые версии остаются');
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT fact_count FROM sf_period'));

        // Запись исчезала в ревизии 2 и вернулась в 3 с тем же содержимым: в ревизии 2 она не действует.
        $this->service->enqueue($this->connectionRef, self::DAY, 3, [[['k:same', ['v' => 1]], ['k:returns', ['v' => 1]]]]);
        ($this->action())();

        self::assertSame(['k:returns' => 1, 'k:same' => 1], $this->current(3));
        self::assertSame(['k:changed' => 2, 'k:new' => 1, 'k:same' => 1], $this->current(2));
    }

    public function testStaleRevisionIsAcknowledgedWithoutRollingPeriodBack(): void
    {
        $this->service->enqueue($this->connectionRef, self::DAY, 2, [[['k:1', ['v' => 2]]]]);
        ($this->action())();
        $stale = $this->service->enqueue($this->connectionRef, self::DAY, 1, [[['k:1', ['v' => 1]]]]);
        $requests = $this->service->pageRequests;

        $result = ($this->action())();

        self::assertSame(1, $result->superseded);
        self::assertContains($stale, $this->service->acked);
        self::assertSame($requests, $this->service->pageRequests, 'страницы устаревшей ревизии не скачиваются');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT current_revision FROM sf_period'));
        self::assertSame(['k:1' => 2], $this->current(2));
    }

    public function testUnacceptableBatchesAreQuarantinedNotAcknowledgedAndDoNotBlockOthers(): void
    {
        $unknown = $this->service->enqueue(Uuid::uuid7()->toString(), self::DAY, 1, [[['k:1', ['v' => 1]]]]);
        $broken = $this->service->enqueue($this->connectionRef, '2026-11-10', 1, [[['k:1', ['v' => 1]]]]);
        $good = $this->service->enqueue($this->connectionRef, self::DAY, 1, [[['k:1', ['v' => 1]]]]);
        $this->service->failPage[$broken] = ContractViolationException::at('source-fact-envelope/v1', '$.schema_version', 'unsupported version');

        $result = ($this->action())();

        self::assertSame([3, 1, 2], [$result->received, $result->applied, $result->quarantined]);
        self::assertSame([$good], $this->service->acked);
        self::assertSame(
            [$broken => 'contract_violation', $unknown => 'unknown_connection'],
            $this->connection->fetchAllKeyValue("SELECT batch_id, reason FROM sf_batch WHERE status = 'quarantined' ORDER BY reason"),
        );
        self::assertCount(1, $this->errors());
        self::assertSame(2, $this->errors()[0]->context['count']);

        // Повтор: пачки приходят снова, но новых ошибок в журнал нет — их показывает гейт.
        ($this->action())();
        self::assertCount(1, $this->errors());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT min(attempts) FROM sf_batch WHERE status = :s', ['s' => 'quarantined']));

        $gate = new CommandTester(new SourceFactsHealthCheckCommand($this->action(), $this->store(), $this->clock, new Logger('test', [$this->logs])));
        self::assertSame(Command::FAILURE, $gate->execute([]));
        self::assertStringContainsString('2 quarantined, 0 stale streams', $gate->getDisplay());

        // Причина устранена — пачка применяется и выходит из карантина.
        unset($this->service->failPage[$broken]);
        ($this->action())();
        self::assertSame(SourceFactStore::STATUS_APPLIED, $this->connection->fetchOne('SELECT status FROM sf_batch WHERE batch_id = :b', ['b' => $broken]));
    }

    public function testUnavailableServiceInterruptsRunAndAcknowledgesNothing(): void
    {
        $batchId = $this->service->enqueue($this->connectionRef, self::DAY, 1, [[['k:1', ['v' => 1]]]]);
        $this->service->failPage[$batchId] = new ServiceUnavailableException('503');

        try {
            ($this->action())();
            self::fail('expected ServiceUnavailableException');
        } catch (ServiceUnavailableException) {
        }

        self::assertSame([], $this->service->acked);
        self::assertSame(['facts' => 0, 'periods' => 0, 'batches' => 0], $this->counts());
    }

    public function testCompanyOutsideAllowlistIsSkippedAndEmptyListDisablesPull(): void
    {
        $this->service->enqueue($this->connectionRef, self::DAY, 1, [[['k:1', ['v' => 1]]]]);

        $result = ($this->action(Uuid::uuid7()->toString()))();

        self::assertSame([1, 1], [$result->received, $result->skipped]);
        self::assertSame([], $this->service->acked);
        self::assertSame(0, $this->counts()['batches']);
        self::assertFalse($this->action('')->isEnabled());
        self::assertTrue($this->action(' '.strtoupper($this->companyId).' ')->isEnabled());
        self::assertSame(1, ($this->action(' '.strtoupper($this->companyId).' '))()->applied);
    }

    public function testGateIsGreenWhenFreshAndRedWhenStreamIsStale(): void
    {
        $this->service->enqueue($this->connectionRef, self::DAY, 1, [[['k:1', ['v' => 1]]]]);
        ($this->action())();
        $gate = new CommandTester(new SourceFactsHealthCheckCommand($this->action(), $this->store(), $this->clock, new Logger('test', [$this->logs])));

        self::assertSame(Command::SUCCESS, $gate->execute([]));

        $this->clock->modify('+37 hours');
        self::assertSame(Command::FAILURE, $gate->execute([]));
        self::assertStringContainsString('0 quarantined, 1 stale streams', $gate->getDisplay());
        self::assertSame([$this->companyId], $this->errors()[0]->context['companyIds']);

        $disabled = new CommandTester(new SourceFactsHealthCheckCommand($this->action(''), $this->store(), $this->clock, new Logger('test', [$this->logs])));
        self::assertSame(Command::SUCCESS, $disabled->execute([]));
    }

    private function action(string $companies = PullSourceFactsAction::ALL_COMPANIES): PullSourceFactsAction
    {
        /** @var MarketplaceFacade $marketplace */
        $marketplace = self::getContainer()->get(MarketplaceFacade::class);

        return new PullSourceFactsAction(
            new SourceFactsGatewayFacade($this->service, $marketplace),
            $this->store(),
            $this->connection,
            new Logger('test', [$this->logs]),
            $companies,
        );
    }

    private function store(): SourceFactStore
    {
        return new SourceFactStore($this->connection, $this->clock);
    }

    /**
     * @return array{facts: int, periods: int, batches: int}
     */
    private function counts(): array
    {
        return [
            'facts' => (int) $this->connection->fetchOne('SELECT count(*) FROM sf_fact'),
            'periods' => (int) $this->connection->fetchOne('SELECT count(*) FROM sf_period'),
            'batches' => (int) $this->connection->fetchOne('SELECT count(*) FROM sf_batch'),
        ];
    }

    /**
     * Действующие записи ревизии: source_key → payload.v.
     *
     * @return array<string, int>
     */
    private function current(int $revision): array
    {
        return array_map('intval', $this->connection->fetchAllKeyValue(
            "SELECT source_key, payload->>'v' FROM sf_fact WHERE company_id = :c AND first_revision <= :r AND last_seen_revision >= :r ORDER BY source_key",
            ['c' => $this->companyId, 'r' => $revision],
        ));
    }

    /**
     * @return list<\Monolog\LogRecord>
     */
    private function errors(): array
    {
        return array_values(array_filter($this->logs->getRecords(), static fn (\Monolog\LogRecord $r): bool => $r->level->value >= \Monolog\Level::Error->value));
    }
}
