<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Performance;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Область замера на запуск marketplace-команды (ночной синк, переобработка, закрытие
 * месяца из cron). Остальные команды, включая messenger:consume, не замеряются: у
 * воркера область — сообщение ({@see MessengerPerformanceSubscriber}).
 */
final class ConsolePerformanceSubscriber implements EventSubscriberInterface
{
    private const PREFIX = 'app:marketplace:';
    private const EXCLUDED = ['app:marketplace:perf-report'];

    public function __construct(private readonly PerformanceRecorder $recorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        if (!$this->recorder->isEnabled() || null === $name || !str_starts_with($name, self::PREFIX) || \in_array($name, self::EXCLUDED, true)) {
            return;
        }

        $this->recorder->beginScope(job: $name);
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $this->recorder->endScope(Command::SUCCESS === $event->getExitCode() ? PerformanceOutcome::Ok : PerformanceOutcome::Error);
    }
}
