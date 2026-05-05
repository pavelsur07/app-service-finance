<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Command\OzonDailySyncCommand;
use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Message\SyncOzonReportMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OzonDailySyncCommandTest extends TestCase
{
    private const COMPANY_1 = '11111111-1111-1111-1111-000000000001';
    private const COMPANY_2 = '11111111-1111-1111-1111-000000000002';
    private const CONNECTION_1 = '22222222-2222-2222-2222-000000000001';
    private const CONNECTION_2 = '22222222-2222-2222-2222-000000000002';

    public function testDispatchesFourteenMessagesForSingleConnectionWithExpectedDates(): void
    {
        $referenceToday = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));

        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->method('execute')->willReturn([
            ['id' => self::CONNECTION_1, 'company_id' => self::COMPANY_1],
        ]);

        $messages = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::exactly(14))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$messages): Envelope {
                $messages[] = $message;

                return new Envelope($message);
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(14))->method('info');

        $tester = $this->makeTester($query, $bus, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(14, $messages);

        foreach ($messages as $message) {
            self::assertInstanceOf(SyncOzonReportMessage::class, $message);
            self::assertSame(self::COMPANY_1, $message->companyId);
            self::assertSame(self::CONNECTION_1, $message->connectionId);
            self::assertNotNull($message->date);
        }

        self::assertDatesCoverRollingD1ToD14($messages, $referenceToday);
        self::assertStringContainsString('Отправлено 14 задач', $tester->getDisplay());
    }

    public function testDispatchesTwentyEightMessagesForTwoConnections(): void
    {
        $referenceToday = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));

        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->method('execute')->willReturn([
            ['id' => self::CONNECTION_1, 'company_id' => self::COMPANY_1],
            ['id' => self::CONNECTION_2, 'company_id' => self::COMPANY_2],
        ]);

        $messagesByConnection = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::exactly(28))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$messagesByConnection): Envelope {
                self::assertInstanceOf(SyncOzonReportMessage::class, $message);
                $messagesByConnection[$message->connectionId][] = $message;

                return new Envelope($message);
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(28))->method('info');

        $tester = $this->makeTester($query, $bus, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $messagesByConnection);
        self::assertArrayHasKey(self::CONNECTION_1, $messagesByConnection);
        self::assertArrayHasKey(self::CONNECTION_2, $messagesByConnection);

        self::assertCount(14, $messagesByConnection[self::CONNECTION_1]);
        self::assertCount(14, $messagesByConnection[self::CONNECTION_2]);

        foreach ($messagesByConnection[self::CONNECTION_1] as $message) {
            self::assertSame(self::COMPANY_1, $message->companyId);
            self::assertSame(self::CONNECTION_1, $message->connectionId);
        }

        foreach ($messagesByConnection[self::CONNECTION_2] as $message) {
            self::assertSame(self::COMPANY_2, $message->companyId);
            self::assertSame(self::CONNECTION_2, $message->connectionId);
        }

        self::assertDatesCoverRollingD1ToD14($messagesByConnection[self::CONNECTION_1], $referenceToday);
        self::assertDatesCoverRollingD1ToD14($messagesByConnection[self::CONNECTION_2], $referenceToday);
        self::assertStringContainsString('Отправлено 28 задач', $tester->getDisplay());
    }

    public function testDoesNotDispatchWhenNoActiveConnections(): void
    {
        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->method('execute')->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $tester = $this->makeTester($query, $bus, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Нет активных Ozon-подключений для синхронизации.', $tester->getDisplay());
    }

    /**
     * @param list<SyncOzonReportMessage> $messages
     */
    private static function assertDatesCoverRollingD1ToD14(array $messages, \DateTimeImmutable $referenceToday): void
    {
        $expectedDates = [];
        for ($offset = 1; $offset <= 14; $offset++) {
            $expectedDates[] = $referenceToday->modify(sprintf('-%d day', $offset))->format('Y-m-d');
        }

        $actualDates = array_map(static fn (SyncOzonReportMessage $message): ?string => $message->date, $messages);

        self::assertContains($referenceToday->modify('-1 day')->format('Y-m-d'), $actualDates);
        self::assertEqualsCanonicalizing($expectedDates, $actualDates);
        self::assertNotContains($referenceToday->format('Y-m-d'), $actualDates, 'D-0 must not be dispatched');
        self::assertNotContains($referenceToday->modify('-15 day')->format('Y-m-d'), $actualDates, 'D-15 must not be dispatched');
    }

    private function makeTester(
        ActiveOzonConnectionsQuery $query,
        MessageBusInterface $bus,
        LoggerInterface $logger,
    ): CommandTester {
        $command = new OzonDailySyncCommand($query, $bus, $logger);

        return new CommandTester($command);
    }
}
