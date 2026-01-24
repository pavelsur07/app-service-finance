<?php

namespace App\Tests\Unit\Command;

use App\Cash\Command\CashAutoRulesEnqueueCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

final class CashAutoRulesEnqueueCommandTest extends TestCase
{
    public function testExecuteFailsWhenAccountsContainNonUuid(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $command = new CashAutoRulesEnqueueCommand($bus);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'companyId' => '19621cff-b028-45d9-9193-11f47ad9a8b2',
            '--accounts' => 'acc-1,acc-2',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Опция --accounts должна содержать UUID', $tester->getDisplay());
    }
}
