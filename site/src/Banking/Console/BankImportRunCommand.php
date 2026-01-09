<?php

namespace App\Banking\Console;

use App\Banking\Service\BankImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bank:import:run', description: 'Run bank import for a company and provider')]
final class BankImportRunCommand extends Command
{
    public function __construct(private BankImportService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('company', InputArgument::REQUIRED, 'Company UUID/ID')
            ->addArgument('provider', InputArgument::REQUIRED, 'Provider code (alfa|sber|tinkoff|demo|...)')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'ISO date, default -30 days')
            ->addOption('until', null, InputOption::VALUE_OPTIONAL, 'ISO date, default now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = (string) $input->getArgument('company');
        $provider = (string) $input->getArgument('provider');

        $since = $input->getOption('since') ? new \DateTimeImmutable((string) $input->getOption('since')) : null;
        $until = $input->getOption('until') ? new \DateTimeImmutable((string) $input->getOption('until')) : null;

        $this->service->run($company, $provider, $since, $until);
        $output->writeln('<info>Bank import finished.</info>');

        return Command::SUCCESS;
    }
}
