<?php

namespace App\Command;

use App\Message\Ozon\SyncOzonOrders;
use App\Repository\CompanyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'ozon:orders:enqueue')]
final class OzonOrdersEnqueueCommand extends Command
{
    public function __construct(
        private CompanyRepository $companies,
        private MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $list = $this->companies->findAllWithOzonCredentials();
        $count = 0;

        foreach ($list as $company) {
            $id = $company->getId();
            // Две схемы: FBS и FBO. Окно и статус рассчитает/подтянет Handler.
            $this->bus->dispatch(new SyncOzonOrders((string) $id, 'FBS'));
            $this->bus->dispatch(new SyncOzonOrders((string) $id, 'FBO'));
            $count += 2;
        }

        $output->writeln(sprintf('Enqueued %d tasks for %d companies', $count, count($list)));

        return Command::SUCCESS;
    }
}
