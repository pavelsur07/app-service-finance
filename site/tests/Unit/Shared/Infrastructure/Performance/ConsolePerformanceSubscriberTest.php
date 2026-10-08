<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Performance;

use App\Shared\Infrastructure\Performance\ConsolePerformanceSubscriber;
use App\Shared\Infrastructure\Performance\PerformanceOutcome;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ConsolePerformanceSubscriberTest extends TestCase
{
    public function testMarketplaceCommandGetsItsOwnScopeWithExitCodeOutcome(): void
    {
        $handler = new TestHandler();
        $subscriber = new ConsolePerformanceSubscriber(new PerformanceRecorder(new Logger('performance', [$handler]), true));
        $command = new Command('app:marketplace:wb-financial-reports:sync');

        $subscriber->onCommand(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), Command::FAILURE));

        [$event] = array_map(static fn ($r): array => $r->context, $handler->getRecords());
        self::assertSame(['handler', 'app:marketplace:wb-financial-reports:sync', 'error'], [$event['stage'], $event['job'], $event['outcome']]);
    }

    public function testWorkerCommandDoesNotCloseTheMessageScope(): void
    {
        $handler = new TestHandler();
        $recorder = new PerformanceRecorder(new Logger('performance', [$handler]), true);
        $subscriber = new ConsolePerformanceSubscriber($recorder);
        $consume = new Command('messenger:consume');

        $subscriber->onCommand(new ConsoleCommandEvent($consume, new ArrayInput([]), new NullOutput()));
        // Сообщение не завершилось (убит обработчик) — область осталась открытой.
        $recorder->beginScope('ProcessDayReportMessage');
        $subscriber->onTerminate(new ConsoleTerminateEvent($consume, new ArrayInput([]), new NullOutput(), Command::SUCCESS));

        self::assertSame([], $handler->getRecords());
        self::assertTrue($recorder->hasScope());
        $recorder->endScope(PerformanceOutcome::Unknown);
    }
}
