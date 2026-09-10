<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Command\OzonAccrualByDaySyncCommand;
use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OzonAccrualByDaySyncCommandTest extends TestCase
{
    public function testDispatchesOneMessagePerConnectionPerDay(): void
    {
        $messages = [];
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1'], ['company_id' => 'c-2', 'id' => 'conn-2']],
            $messages,
        ));

        $tester->execute(['--days-back' => '3']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(6, $messages);
        self::assertContainsOnlyInstancesOf(SyncOzonAccrualByDayMessage::class, $messages);

        $dates = array_values(array_unique(array_map(static fn (SyncOzonAccrualByDayMessage $m): string => $m->date, $messages)));
        self::assertCount(3, $dates, 'Три дня окна, каждый по одному разу на подключение.');
    }

    public function testRejectsOutOfRangeWindowInsteadOfFloodingTheQueue(): void
    {
        $messages = [];
        $tester = new CommandTester($this->command([['company_id' => 'c-1', 'id' => 'conn-1']], $messages));

        $tester->execute(['--days-back' => '0']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame([], $messages);
    }

    public function testNoActiveConnectionsIsSuccessNotFailure(): void
    {
        $messages = [];
        $tester = new CommandTester($this->command([], $messages));

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $messages);
    }

    /**
     * @param list<array<string, mixed>> $connections
     * @param list<object> $messages
     */
    private function command(array $connections, array &$messages): OzonAccrualByDaySyncCommand
    {
        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->method('execute')->willReturn($connections);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$messages): Envelope {
            $messages[] = $message;

            return new Envelope($message);
        });

        return new OzonAccrualByDaySyncCommand($query, $bus, new NullLogger());
    }
}
