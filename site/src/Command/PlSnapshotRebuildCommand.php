<?php

namespace App\Command;

use App\Repository\CompanyRepository;
use App\Service\PLSnapshotBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:pl:snapshot:rebuild',
    description: 'Пересобирает месячные снапшоты P&L за диапазон периодов.'
)]
class PlSnapshotRebuildCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companyRepo,
        private readonly PLSnapshotBuilder $builder
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addArgument('from', InputArgument::REQUIRED) // YYYY-MM
            ->addArgument('to', InputArgument::REQUIRED);  // YYYY-MM
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = $this->companyRepo->find($input->getArgument('companyId'));
        $from = (string) $input->getArgument('from');
        $to   = (string) $input->getArgument('to');

        $this->builder->rebuildRange($company, $from, $to);
        $output->writeln('Done');

        return Command::SUCCESS;
    }
}
