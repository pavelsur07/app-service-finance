<?php

declare(strict_types=1);

namespace App\Tests\Integration\ServiceGateway;

use App\ServiceGateway\Application\Action\RefreshServiceCoverageAction;
use App\ServiceGateway\Command\ServiceCoverageRefreshCommand;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\Tests\Builders\ServiceGateway\ServiceCoverageRowBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ServiceCoverageRefreshCommandTest extends IntegrationTestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logs = new TestHandler();
    }

    /**
     * @return list<array{string, string}>
     */
    private function logs(): array
    {
        return array_values(array_map(static fn ($r): array => [strtolower($r->level->getName()), $r->message], $this->logs->getRecords()));
    }

    public function testRefreshFillsReadModelAndLogsStartFinish(): void
    {
        $tester = $this->tester(new FakeServiceCoverageClient());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('ozon-fetcher: 3 rows, 4 cabinets', $tester->getDisplay());
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM service_coverage_row'));
        self::assertSame('2026-10-18 09:10:00', $this->connection->fetchOne('SELECT refreshed_at FROM service_coverage_snapshot'));
        self::assertSame([
            ['info', 'Service coverage refresh started.'],
            ['info', 'Service coverage refresh finished.'],
        ], $this->logs());
    }

    public function testDisabledExitsCleanly(): void
    {
        $client = new FakeServiceCoverageClient(enabled: false);
        $tester = $this->tester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(0, $client->calls);
        self::assertSame([['info', 'Service coverage refresh is disabled.']], $this->logs());
    }

    public function testUnavailableServiceKeepsReadModelWithWarning(): void
    {
        $this->seedRow();
        $tester = $this->tester(new FakeServiceCoverageClient(ServiceUnavailableException::status('ozon-fetcher', 503)));

        self::assertSame(Command::SUCCESS, $tester->execute([]), 'ожидаемый сбой не краснит cron');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM service_coverage_row'));
        self::assertTrue($this->logs->hasRecordThatContains('service unavailable', Level::Warning));
    }

    public function testContractViolationKeepsReadModelWithError(): void
    {
        $this->seedRow();
        $tester = $this->tester(new FakeServiceCoverageClient(ContractViolationException::at('service-coverage/v1', '$', 'broken')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM service_coverage_row'));
        self::assertTrue($this->logs->hasRecordThatContains('contract violation', Level::Error));
    }

    private function seedRow(): void
    {
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->build());
        $this->em->flush();
        $this->em->clear();
    }

    private function tester(FakeServiceCoverageClient $client): CommandTester
    {
        /** @var RefreshServiceCoverageAction $action */
        $action = self::getContainer()->get(RefreshServiceCoverageAction::class);

        return new CommandTester(new ServiceCoverageRefreshCommand($client, $action, new MockClock('2026-10-18 09:10:00'), new Logger('test', [$this->logs])));
    }
}
