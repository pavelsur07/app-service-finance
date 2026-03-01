<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Command;

use App\Cash\Application\Message\ScoreCompanyCounterpartiesMessage;
use App\Company\Facade\CompanyFacade; // <- Зависимость из чужого модуля только через Facade!
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:cash:dispatch-scoring',
    description: 'Dispatches messages to recalculate counterparty scores for all active companies.'
)]
final class DispatchCounterpartyScoringCommand extends Command
{
    public function __construct(
        private readonly CompanyFacade $companyFacade, // Обращение к модулю Company
        private readonly MessageBusInterface $messageBus
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Получаем ID через законный контракт чужого модуля
        $activeCompanyIds = $this->companyFacade->getAllActiveCompanyIds();

        // 2. Отправляем в очередь (RabbitMQ/Redis)
        foreach ($activeCompanyIds as $companyId) {
            $this->messageBus->dispatch(
                new ScoreCompanyCounterpartiesMessage((string) $companyId)
            );
        }

        $output->writeln(sprintf('Dispatched %d messages to the queue.', count($activeCompanyIds)));

        return Command::SUCCESS;
    }
}
