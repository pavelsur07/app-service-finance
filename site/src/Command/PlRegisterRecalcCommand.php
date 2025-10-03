<?php

namespace App\Command;

use App\Repository\CompanyRepository;
use App\Service\PLRegisterUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:pl:register:recalc',
    description: 'Пересчитывает регистр P&L за диапазон дат.'
)]
class PlRegisterRecalcCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companyRepo,
        private readonly PLRegisterUpdater $updater,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addArgument('from', InputArgument::REQUIRED) // YYYY-MM-DD
            ->addArgument('to', InputArgument::REQUIRED);  // YYYY-MM-DD
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = $this->companyRepo->find($input->getArgument('companyId'));
        $from = new \DateTimeImmutable($input->getArgument('from'));
        $to = new \DateTimeImmutable($input->getArgument('to'));

        $this->updater->recalcRange($company, $from, $to);
        $output->writeln('Done');

        return Command::SUCCESS;
    }
}
