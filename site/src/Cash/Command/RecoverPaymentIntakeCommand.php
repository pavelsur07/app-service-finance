<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Cron: событие после commit подтверждения MCP-черновика могло не уйти — отправляем повторно. */
#[AsCommand(name: 'app:cash:recover-payment-intake', description: 'Повторно отправляет уведомления об изменении ДДС после подтверждения черновиков')]
final class RecoverPaymentIntakeCommand extends Command
{
    public function __construct(private readonly PaymentIntakeAction $action, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $recovered = 0;
        $failed = 0;
        foreach ($this->action->pendingEventCompanyIds() as $companyId) {
            try {
                $recovered += $this->action->recoverEvents($companyId, true);
            } catch (\Throwable $exception) {
                ++$failed;
                $this->logger->error('Payment draft cash event recovery failed.', ['companyId' => $companyId, 'exception' => $exception]);
            }
        }
        $output->writeln(sprintf('Отправлено событий: %d, компаний со сбоем: %d', $recovered, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
