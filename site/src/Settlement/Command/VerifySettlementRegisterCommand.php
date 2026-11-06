<?php

declare(strict_types=1);

namespace App\Settlement\Command;

use App\Settlement\Application\ReconcileSettlementRegisterAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:settlement:verify', description: 'Сверить весь регистр компании с первичными документами')]
final class VerifySettlementRegisterCommand extends Command
{
    public function __construct(private readonly ReconcileSettlementRegisterAction $reconcile)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('companyId', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = ($this->reconcile)((string) $input->getArgument('companyId'));
        } catch (\DomainException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Проверен весь регистр компании: движений %d, связей %d.', $result->expectedMovements, $result->expectedLinks));
        foreach ($result->differences as $difference) {
            $output->writeln('<error>'.$difference.'</error>');
        }

        return [] === $result->differences ? Command::SUCCESS : Command::FAILURE;
    }
}
