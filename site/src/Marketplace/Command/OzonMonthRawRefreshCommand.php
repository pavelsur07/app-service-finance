<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Application\OzonMonthRawRefreshPlanner;
use App\Marketplace\Message\SyncOzonReportMessage;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:marketplace:ozon-month-raw-refresh',
    description: 'Месячный raw-refresh Ozon по дням месяца',
)]
final class OzonMonthRawRefreshCommand extends Command
{
    public function __construct(
        private readonly OzonMonthRawRefreshPlanner $planner,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Год периода (YYYY)')
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Месяц периода (1..12)')
            ->addOption('previous-month', null, InputOption::VALUE_NONE, 'Использовать предыдущий календарный месяц')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Ограничить план по company UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать план без dispatch в очередь');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $period = $this->resolvePeriod($input, $io);
        if (null === $period) {
            return Command::FAILURE;
        }

        $companyId = $input->getOption('company-id');
        $plan = $this->planner->plan($period['year'], $period['month'], is_string($companyId) ? $companyId : null);

        if ([] === $plan) {
            $io->success('План пуст: нет элементов для выбранного периода и фильтра компании.');

            return Command::SUCCESS;
        }

        if (true === $input->getOption('dry-run')) {
            $this->renderPlanTable($output, $plan);
            $io->success('Dry-run завершен: сообщения не отправлялись.');

            return Command::SUCCESS;
        }

        $total = \count($plan);
        $plannedDispatched = 0;
        $skipped = 0;
        $skippedFinanceLocked = 0;

        foreach ($plan as $item) {
            if ('planned' !== $item->status) {
                $skipped++;
                if ('finance_locked' === $item->skippedReason) {
                    $skippedFinanceLocked++;
                }

                continue;
            }

            $this->messageBus->dispatch(new SyncOzonReportMessage(
                companyId: $item->companyId,
                connectionId: $item->connectionId,
                date: $item->date,
            ));

            $plannedDispatched++;
        }

        if (0 === $plannedDispatched) {
            $io->success('Нет planned-дат для dispatch: все элементы пропущены.');
        }

        $io->section('Summary');
        $io->writeln(sprintf('total items: %d', $total));
        $io->writeln(sprintf('planned dispatched: %d', $plannedDispatched));
        $io->writeln(sprintf('skipped: %d', $skipped));
        $io->writeln(sprintf('skipped by finance_locked: %d', $skippedFinanceLocked));

        return Command::SUCCESS;
    }

    private function resolvePeriod(InputInterface $input, SymfonyStyle $io): ?array
    {
        $previousMonth = true === $input->getOption('previous-month');
        $yearRaw = $input->getOption('year');
        $monthRaw = $input->getOption('month');

        $yearProvided = is_string($yearRaw) && '' !== trim($yearRaw);
        $monthProvided = is_string($monthRaw) && '' !== trim($monthRaw);

        if ($previousMonth && ($yearProvided || $monthProvided)) {
            $io->error('Нельзя использовать --previous-month одновременно с --year/--month.');

            return null;
        }

        if ($previousMonth) {
            $periodDate = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->modify('first day of previous month');

            return [
                'year' => (int) $periodDate->format('Y'),
                'month' => (int) $periodDate->format('n'),
            ];
        }

        if ($yearProvided xor $monthProvided) {
            $io->error('Параметры --year и --month должны быть указаны вместе.');

            return null;
        }

        if (!$yearProvided && !$monthProvided) {
            $io->error('Укажите период: либо --previous-month, либо пару --year и --month.');

            return null;
        }

        if (!is_string($yearRaw) || !is_string($monthRaw) || !ctype_digit($yearRaw) || !ctype_digit($monthRaw)) {
            $io->error('Параметры --year и --month должны быть целыми числовыми значениями.');

            return null;
        }

        $year = (int) $yearRaw;
        $month = (int) $monthRaw;

        if ($year < 2000) {
            $io->error('Некорректный --year: укажите год >= 2000.');

            return null;
        }

        if ($month < 1 || $month > 12) {
            $io->error('Некорректный --month: допустимые значения 1..12.');

            return null;
        }

        return ['year' => $year, 'month' => $month];
    }

    private function renderPlanTable(OutputInterface $output, array $plan): void
    {
        $table = new Table($output);
        $table->setHeaders(['company_id', 'connection_id', 'date', 'status', 'skipped_reason']);

        foreach ($plan as $item) {
            $table->addRow([
                $item->companyId,
                $item->connectionId,
                $item->date,
                $item->status,
                $item->skippedReason,
            ]);
        }

        $table->render();
    }
}
