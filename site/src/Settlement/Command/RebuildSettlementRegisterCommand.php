<?php

declare(strict_types=1);

namespace App\Settlement\Command;

use App\Settlement\Application\ReconcileSettlementRegisterAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:settlement:rebuild', description: 'Восстановить производный регистр компании; по умолчанию dry-run')]
final class RebuildSettlementRegisterCommand extends Command
{
    public function __construct(private readonly ReconcileSettlementRegisterAction $reconcile)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('companyId', InputArgument::REQUIRED)->addOption('execute', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $execute = (bool) $input->getOption('execute');
        try {
            $result = ($this->reconcile)((string) $input->getArgument('companyId'), $execute);
        } catch (\DomainException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('%s: движений %d, связей %d, типов расхождений %d.', $execute ? 'Восстановлено' : 'Dry-run, без записи', $result->expectedMovements, $result->expectedLinks, count($result->differences)));

        return Command::SUCCESS;
    }
}
