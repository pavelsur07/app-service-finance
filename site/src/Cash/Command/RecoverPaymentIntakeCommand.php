<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:cash:recover-payment-intake', description: 'Повторяет долговечные заявки разбора и уведомления об изменении ДДС компании')]
final class RecoverPaymentIntakeCommand extends Command
{
    public function __construct(private readonly PaymentIntakeAction $action)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('company-id', InputArgument::REQUIRED, 'UUID компании');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = $input->getArgument('company-id');
        if (!\is_string($companyId) || !Uuid::isValid($companyId)) {
            $output->writeln('<error>Укажите UUID компании.</error>');

            return Command::INVALID;
        }
        $output->writeln(sprintf('Восстановлено заявок: %d', $this->action->recover($companyId)));

        return Command::SUCCESS;
    }
}
