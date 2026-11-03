<?php

declare(strict_types=1);

namespace App\Tests\Integration\ServiceGateway;

use App\ServiceGateway\Application\Action\RefreshServiceCoverageAction;
use App\ServiceGateway\Command\ServiceCoverageRefreshCommand;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\ServiceCoverageClientInterface;
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

    public function testRefreshesEveryServiceIntoItsOwnReadModel(): void
    {
        $tester = $this->tester(new FakeServiceCoverageClient(), new FakeServiceCoverageClient(service: 'wb-fetcher'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('ozon-fetcher: 3 rows, 4 cabinets', $tester->getDisplay());
        self::assertStringContainsString('wb-fetcher: 3 rows, 4 cabinets', $tester->getDisplay());
        self::assertSame(
            [['service' => 'ozon-fetcher', 'n' => 3], ['service' => 'wb-fetcher', 'n' => 3]],
            $this->connection->fetchAllAssociative('SELECT service, count(*) AS n FROM service_coverage_row GROUP BY service ORDER BY service'),
        );
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM service_coverage_snapshot'));
    }

    public function testUnavailableServiceDoesNotStopOthers(): void
    {
        $this->seedRow('wb-fetcher');
        $tester = $this->tester(
            new FakeServiceCoverageClient(ServiceUnavailableException::status('wb-fetcher', 503), service: 'wb-fetcher'),
            new FakeServiceCoverageClient(),
        );

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM service_coverage_row WHERE service = 'wb-fetcher'"), 'строки WB прежние');
        self::assertSame(3, (int) $this->connection->fetchOne("SELECT count(*) FROM service_coverage_row WHERE service = 'ozon-fetcher'"));
        self::assertTrue($this->logs->hasRecordThatContains('service unavailable', Level::Warning));
    }

    public function testContractViolationOfOneServiceFailsRunButRefreshesOthers(): void
    {
        $tester = $this->tester(
            new FakeServiceCoverageClient(ContractViolationException::at('service-coverage/v1', '$', 'broken'), service: 'wb-fetcher'),
            new FakeServiceCoverageClient(),
        );

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(3, (int) $this->connection->fetchOne("SELECT count(*) FROM service_coverage_row WHERE service = 'ozon-fetcher'"));
    }

    public function testDisabledServiceIsSkippedOthersRefreshed(): void
    {
        $wb = new FakeServiceCoverageClient(enabled: false, service: 'wb-fetcher');
        $tester = $this->tester(new FakeServiceCoverageClient(), $wb);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(0, $wb->calls);
        self::assertStringContainsString('wb-fetcher: disabled', $tester->getDisplay());
        self::assertTrue($this->logs->hasRecordThatContains('Service coverage refresh is disabled.', Level::Info));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM service_coverage_row'));
    }

    public function testContainerRegistersOzonAndWbClients(): void
    {
        /** @var ServiceCoverageRefreshCommand $command */
        $command = self::getContainer()->get(ServiceCoverageRefreshCommand::class);
        $clients = (new \ReflectionProperty($command, 'clients'))->getValue($command);
        self::assertIsIterable($clients);
        $services = [];
        foreach ($clients as $client) {
            self::assertInstanceOf(ServiceCoverageClientInterface::class, $client);
            $services[] = $client->service();
        }
        self::assertSame(['ozon-fetcher', 'wb-fetcher'], $services);
    }

    private function seedRow(string $service = 'ozon-fetcher'): void
    {
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->withService($service)->build());
        $this->em->flush();
        $this->em->clear();
    }

    private function tester(FakeServiceCoverageClient ...$clients): CommandTester
    {
        /** @var RefreshServiceCoverageAction $action */
        $action = self::getContainer()->get(RefreshServiceCoverageAction::class);

        return new CommandTester(new ServiceCoverageRefreshCommand($clients, $action, new MockClock('2026-10-18 09:10:00'), new Logger('test', [$this->logs])));
    }
}
