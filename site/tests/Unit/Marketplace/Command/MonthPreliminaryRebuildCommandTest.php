<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Command\MonthPreliminaryRebuildCommand;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MonthPreliminaryRebuildCommandTest extends TestCase
{
    public function testDispatchesMessagePerActiveSellerConnection(): void
    {
        $query = $this->createMock(ActiveSellerConnectionsQuery::class);
        $query->method('execute')->willReturn([
            ['id' => 'c1', 'company_id' => 'company-a', 'marketplace' => 'ozon'],
            ['id' => 'c2', 'company_id' => 'company-a', 'marketplace' => 'wildberries'],
            ['id' => 'c3', 'company_id' => 'company-b', 'marketplace' => 'ozon'],
        ]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatched): Envelope {
                self::assertInstanceOf(RebuildPreliminaryForPeriodMessage::class, $message);
                $dispatched[] = [
                    'companyId'   => $message->companyId,
                    'marketplace' => $message->marketplace,
                ];

                return new Envelope($message);
            });

        $logger = $this->createMock(LoggerInterface::class);

        $command = new MonthPreliminaryRebuildCommand($query, $bus, $logger);
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(3, $dispatched);
        self::assertSame(['companyId' => 'company-a', 'marketplace' => 'ozon'],        $dispatched[0]);
        self::assertSame(['companyId' => 'company-a', 'marketplace' => 'wildberries'], $dispatched[1]);
        self::assertSame(['companyId' => 'company-b', 'marketplace' => 'ozon'],        $dispatched[2]);
    }

    public function testEmptyConnectionListExitsSuccess(): void
    {
        $query = $this->createMock(ActiveSellerConnectionsQuery::class);
        $query->method('execute')->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);

        $command = new MonthPreliminaryRebuildCommand($query, $bus, $logger);
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Нет активных SELLER-подключений', $tester->getDisplay());
    }

    public function testContinuesAfterDispatchFailureOnOneConnection(): void
    {
        $query = $this->createMock(ActiveSellerConnectionsQuery::class);
        $query->method('execute')->willReturn([
            ['id' => 'c1', 'company_id' => 'company-a', 'marketplace' => 'ozon'],
            ['id' => 'c2', 'company_id' => 'company-b', 'marketplace' => 'wildberries'],
            ['id' => 'c3', 'company_id' => 'company-c', 'marketplace' => 'ozon'],
        ]);

        $attempted = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$attempted): Envelope {
                $attempted[] = $message->companyId;

                if ($message->companyId === 'company-b') {
                    throw new \RuntimeException('queue down');
                }

                return new Envelope($message);
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                '[PreliminaryRebuild] Dispatch failed',
                self::callback(static fn (array $ctx): bool => ($ctx['company_id'] ?? null) === 'company-b'),
            );

        $command = new MonthPreliminaryRebuildCommand($query, $bus, $logger);
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['company-a', 'company-b', 'company-c'], $attempted);
        self::assertStringContainsString('Отправлено 2', $tester->getDisplay());
        self::assertStringContainsString('ошибок: 1', $tester->getDisplay());
    }
}
