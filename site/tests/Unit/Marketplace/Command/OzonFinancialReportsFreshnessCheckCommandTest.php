<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Command\OzonFinancialReportsFreshnessCheckCommand;
use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Infrastructure\Query\LatestOzonAccrualDocumentQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonFinancialReportsFreshnessCheckCommandTest extends TestCase
{
    public function testDocumentForYesterdayIsGreen(): void
    {
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1']],
            [['company_id' => 'c-1', 'last_day' => $this->yesterday()]],
        ));

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('OK company c-1', $tester->getDisplay());
    }

    public function testMissingDocumentIsFailure(): void
    {
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1']],
            [],
        ));

        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('MISSING company c-1', $tester->getDisplay());
    }

    public function testStaleDocumentIsFailure(): void
    {
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1']],
            [['company_id' => 'c-1', 'last_day' => '2026-09-07']],
        ));

        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('STALE company c-1', $tester->getDisplay());
    }

    public function testDocumentNewerThanYesterdayIsStillGreen(): void
    {
        // Загрузчик за сегодня не ходит, но документ за сегодня может появиться
        // после ручного прогона: гейт свежести не должен на этом краснеть.
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1']],
            [['company_id' => 'c-1', 'last_day' => $this->today()]],
        ));

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testNoActiveConnectionsIsSuccessNotFailure(): void
    {
        // Кабинета нет — чинить нечего; красный гейт здесь был бы ложным алертом.
        $tester = new CommandTester($this->command([], []));

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('active seller connections count: 0', $tester->getDisplay());
    }

    /**
     * @param list<array<string, mixed>> $connections
     * @param list<array<string, mixed>> $documents
     */
    private function command(array $connections, array $documents): OzonFinancialReportsFreshnessCheckCommand
    {
        $connectionsDbal = $this->createMock(Connection::class);
        $connectionsDbal->method('fetchAllAssociative')->willReturn($connections);

        $documentsDbal = $this->createMock(Connection::class);
        $documentsDbal->method('fetchAllAssociative')->willReturn($documents);

        return new OzonFinancialReportsFreshnessCheckCommand(
            new ActiveOzonConnectionsQuery($connectionsDbal),
            new LatestOzonAccrualDocumentQuery($documentsDbal),
            new NullLogger(),
        );
    }

    private function yesterday(): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))
            ->modify('-1 day')
            ->format('Y-m-d');
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
    }
}
