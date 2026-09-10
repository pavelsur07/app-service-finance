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
    public function testProcessedDocumentForYesterdayIsGreen(): void
    {
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1']],
            [['company_id' => 'c-1']],
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

    public function testOnlyOneOfTwoCabinetsClosedIsFailure(): void
    {
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1'], ['company_id' => 'c-2', 'id' => 'conn-2']],
            [['company_id' => 'c-1']],
        ));

        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('OK company c-1', $tester->getDisplay());
        self::assertStringContainsString('MISSING company c-2', $tester->getDisplay());
        self::assertStringContainsString('missing count: 1', $tester->getDisplay());
    }

    public function testQueryAsksForYesterdayExactlyAndOnlyProcessedDocuments(): void
    {
        // Регрессия. Прежняя версия брала максимум по периоду и любой статус
        // кроме failed: документ за сегодня выдавался бы за доказательство
        // вчерашнего, а застрявшая обработка — за готовые финансовые строки.
        $params = [];
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1']],
            [['company_id' => 'c-1']],
            $params,
        ));

        $tester->execute([]);

        $yesterday = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))
            ->modify('-1 day')
            ->format('Y-m-d');

        self::assertSame($yesterday, $params['day'] ?? null);
        self::assertSame('completed', $params['completed'] ?? null);
        self::assertSame('accrual_by_day', $params['documentType'] ?? null);
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
     * @param array<string, mixed> $capturedParams
     */
    private function command(array $connections, array $documents, array &$capturedParams = []): OzonFinancialReportsFreshnessCheckCommand
    {
        $connectionsDbal = $this->createMock(Connection::class);
        $connectionsDbal->method('fetchAllAssociative')->willReturn($connections);

        $documentsDbal = $this->createMock(Connection::class);
        $documentsDbal->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use ($documents, &$capturedParams): array {
                $capturedParams = $params;

                return $documents;
            },
        );

        return new OzonFinancialReportsFreshnessCheckCommand(
            new ActiveOzonConnectionsQuery($connectionsDbal),
            new LatestOzonAccrualDocumentQuery($documentsDbal),
            new NullLogger(),
        );
    }
}
