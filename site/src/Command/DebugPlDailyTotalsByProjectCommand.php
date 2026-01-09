<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:debug:pl-daily-totals:by-project',
    description: 'Показывает статистику pl_daily_totals по project_direction_id для указанной компании и периода.'
)]
class DebugPlDailyTotalsByProjectCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'UUID компании')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Дата начала (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Дата конца (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getOption('company');
        $fromArg = (string) $input->getOption('from');
        $toArg = (string) $input->getOption('to');

        if ('' === $companyId || '' === $fromArg || '' === $toArg) {
            $output->writeln('<error>Укажите --company, --from и --to.</error>');

            return Command::FAILURE;
        }

        try {
            $from = new \DateTimeImmutable($fromArg);
            $to = new \DateTimeImmutable($toArg);
        } catch (\Throwable) {
            $output->writeln('<error>Неверный формат дат. Используйте YYYY-MM-DD.</error>');

            return Command::FAILURE;
        }

        $sql = <<<SQL
            SELECT project_direction_id, COUNT(*) AS cnt, SUM(amount_income) AS inc, SUM(amount_expense) AS exp
            FROM pl_daily_totals
            WHERE company_id = :company AND date BETWEEN :from AND :to
            GROUP BY project_direction_id
            ORDER BY cnt DESC
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'company' => $companyId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);

        $table = new Table($output);
        $table
            ->setHeaders(['project_direction_id', 'cnt', 'inc', 'exp'])
            ->setRows($rows);
        $table->render();

        $nonZero = array_filter(
            $rows,
            static fn (array $row) => ((float) ($row['inc'] ?? 0)) !== 0.0 || ((float) ($row['exp'] ?? 0)) !== 0.0,
        );
        $output->writeln(sprintf('<info>project_direction_id с ненулевыми суммами: %d</info>', count($nonZero)));

        return Command::SUCCESS;
    }
}
