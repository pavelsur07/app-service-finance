<?php

declare(strict_types=1);

namespace App\Billing\Command;

use App\Billing\Application\ScheduleExpiryRemindersAction;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron: напоминания владельцам кабинетов об окончании оплаченного и пробного периода.
 * Повторный запуск в тот же день писем не дублирует.
 */
#[AsCommand(name: 'app:billing:expiry-reminders', description: 'Поставить в очередь напоминания об окончании оплаты на сегодня')]
final class ExpiryRemindersCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly ScheduleExpiryRemindersAction $schedule,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Уже выполняется.');

            return Command::SUCCESS;
        }

        try {
            $this->logger->info('Billing: напоминания об окончании оплаты — старт');
            $result = ($this->schedule)();
            $this->logger->info('Billing: напоминания об окончании оплаты — финиш', $result);
            $output->writeln(sprintf('К отправке сегодня: %d, поставлено: %d, уже отправляли: %d, сбоев: %d.', $result['due'], $result['queued'], $result['skipped'], $result['failed']));

            return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
