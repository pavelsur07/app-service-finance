<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Command\OzonRealizationSyncCommand;
use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Message\SyncOzonRealizationMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OzonRealizationSyncCommandTest extends TestCase
{
    private const COMPANY_1 = '11111111-1111-1111-1111-000000000001';
    private const CONNECTION_1 = '22222222-2222-2222-2222-000000000001';

    /**
     * Регрессия GlitchTip issue 135.
     *
     * ActiveOzonConnectionsQuery возвращает ключ `id`, а команда читала
     * `$connection['connection_id']` (такого ключа нет) → null → TypeError в
     * конструкторе SyncOzonRealizationMessage (requires string $connectionId).
     * До фикла тест падает с TypeError, после — зелёный.
     */
    public function testDispatchesRealizationMessageWithConnectionIdFromQuery(): void
    {
        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->method('execute')->willReturn([
            ['id' => self::CONNECTION_1, 'company_id' => self::COMPANY_1, 'client_id' => null, 'finance_lock_before' => null],
        ]);

        $messages = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$messages): Envelope {
                $messages[] = $message;

                return new Envelope($message);
            });

        $tester = new CommandTester(new OzonRealizationSyncCommand($query, $bus));
        // Заведомо прошлый период — проходит guard «отчёт доступен только после следующего месяца».
        $exitCode = $tester->execute(['--year' => '2020', '--month' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, $messages);

        $message = $messages[0];
        self::assertInstanceOf(SyncOzonRealizationMessage::class, $message);
        self::assertSame(self::CONNECTION_1, $message->connectionId);
        self::assertNotSame('', $message->connectionId);
        self::assertSame(self::COMPANY_1, $message->companyId);
        self::assertSame(2020, $message->year);
        self::assertSame(1, $message->month);
    }
}
