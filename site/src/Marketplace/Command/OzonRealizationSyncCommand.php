<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Message\SyncOzonRealizationMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Загрузка отчётов о реализации Ozon (documentType = 'realization').
 *
 * Использование:
 *   # Прошлый месяц (автоматически)
 *   php bin/console app:marketplace:ozon-realization-sync
 *
 *   # Конкретный месяц
 *   php bin/console app:marketplace:ozon-realization-sync --year=2026 --month=2
 *
 *   # Конкретная компания
 *   php bin/console app:marketplace:ozon-realization-sync --company-id=uuid-here
 *
 * Крон (10-е число каждого месяца в 6:00):
 *   0 6 10 * * php bin/console app:marketplace:ozon-realization-sync
 */
#[AsCommand(
    name: 'app:marketplace:ozon-realization-sync',
    description: 'Load Ozon realization report (v2/finance/realization) into raw documents',
)]
final class OzonRealizationSyncCommand extends Command
{
    public function __construct(
        private readonly ActiveOzonConnectionsQuery $connectionsQuery,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('year', null, InputOption::VALUE_OPTIONAL, 'Year (default: previous month year)')
            ->addOption('month', null, InputOption::VALUE_OPTIONAL, 'Month 1-12 (default: previous month)')
            ->addOption('company-id', null, InputOption::VALUE_OPTIONAL, 'Sync only specific company UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        [$year, $month] = $this->resolvePeriod($input);

        $io->title(sprintf('Ozon Realization Sync — %d-%02d', $year, $month));

        // Проверяем что запрашиваем прошлый месяц или раньше
        $now = new \DateTimeImmutable();
        $firstDayOfCurrentMonth = $now->modify('first day of this month')->setTime(0, 0);
        $requestedPeriod = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));

        if ($requestedPeriod >= $firstDayOfCurrentMonth) {
            $io->error(sprintf(
                'Cannot load realization for %d-%02d: report is only available after 5th of the following month.',
                $year,
                $month,
            ));

            return Command::FAILURE;
        }

        $connections = $this->connectionsQuery->execute();

        // Фильтр по компании если передан
        $companyIdFilter = $input->getOption('company-id');
        if ($companyIdFilter) {
            $connections = array_filter(
                $connections,
                fn(array $c) => $c['company_id'] === $companyIdFilter,
            );

            if (empty($connections)) {
                $io->error(sprintf('No active Ozon connections found for company: %s', $companyIdFilter));

                return Command::FAILURE;
            }
        }

        if (empty($connections)) {
            $io->warning('No active Ozon connections found.');

            return Command::SUCCESS;
        }

        $dispatched = 0;
        foreach ($connections as $connection) {
            $this->messageBus->dispatch(new SyncOzonRealizationMessage(
                companyId:    $connection['company_id'],
                connectionId: $connection['connection_id'],
                year:         $year,
                month:        $month,
            ));

            $io->text(sprintf(
                '  → Dispatched for company %s (connection %s)',
                $connection['company_id'],
                $connection['connection_id'],
            ));

            $dispatched++;
        }

        $io->success(sprintf(
            'Dispatched %d realization sync jobs for %d-%02d',
            $dispatched,
            $year,
            $month,
        ));

        return Command::SUCCESS;
    }

    /**
     * Возвращает [year, month] — из опций или прошлый месяц по умолчанию.
     */
    private function resolvePeriod(InputInterface $input): array
    {
        $yearOption  = $input->getOption('year');
        $monthOption = $input->getOption('month');

        if ($yearOption !== null && $monthOption !== null) {
            return [(int)$yearOption, (int)$monthOption];
        }

        // По умолчанию — прошлый месяц
        $lastMonth = new \DateTimeImmutable('first day of last month');

        return [(int)$lastMonth->format('Y'), (int)$lastMonth->format('n')];
    }
}
