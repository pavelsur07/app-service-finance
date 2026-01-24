<?php

namespace App\Cash\Command;

use App\Repository\CompanyRepository;
use App\Service\DailyBalanceRecalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:daily-balance:recalc',
    description: 'Пересчитывает ежедневные остатки MoneyAccountDailyBalance за указанный период.'
)]
class DailyBalanceRecalcCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companyRepo,
        private readonly DailyBalanceRecalculator $recalculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED, 'UUID компании')
            ->addArgument('from', InputArgument::REQUIRED, 'Дата начала (YYYY-MM-DD)')
            ->addArgument('to', InputArgument::REQUIRED, 'Дата конца (YYYY-MM-DD)')
            ->addOption(
                'accounts',
                null,
                InputOption::VALUE_OPTIONAL,
                'Список ID счетов через запятую (если не указан — все счета компании)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getArgument('companyId');
        $fromArg = (string) $input->getArgument('from');
        $toArg = (string) $input->getArgument('to');

        $company = $this->companyRepo->find($companyId);
        if (!$company) {
            $output->writeln(sprintf('<error>Компания %s не найдена</error>', $companyId));

            return Command::FAILURE;
        }

        try {
            $from = new \DateTimeImmutable($fromArg);
            $to = new \DateTimeImmutable($toArg);
        } catch (\Throwable) {
            $output->writeln('<error>Неверный формат дат. Используйте YYYY-MM-DD.</error>');

            return Command::FAILURE;
        }

        $accountsOpt = $input->getOption('accounts');
        /** @var string|null $accountsOpt */
        $accountIds = null;
        if (null !== $accountsOpt && '' !== trim($accountsOpt)) {
            $accountIds = array_values(array_filter(
                array_map('trim', explode(',', $accountsOpt)),
                static fn ($v) => '' !== $v
            ));
        }

        $this->recalculator->recalcRange($company, $from, $to, $accountIds);

        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }
}
