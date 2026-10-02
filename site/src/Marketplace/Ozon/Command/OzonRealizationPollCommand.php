<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Command;

use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\SyncOzonRealizationMessage;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationPollWindow;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Часовой опрос готовности отчёта «Реализация» Ozon за прошлый месяц.
 *
 * Окно — `OzonRealizationPollWindow` (с 18:00 МСК 1-го до конца 8-го числа); вне него команда выходит с кодом 0.
 * В окне для каждой компании с активным seller-подключением ставит загрузку (`SyncOzonRealizationMessage`),
 * если пара не в терминальном статусе и не ждёт `nextRetryAt`. Команда тонкая: сама ничего не загружает.
 * Статус пары живёт в `marketplace_financial_report_sync_statuses` (`OzonRealizationReport::REPORT_TYPE`).
 */
#[AsCommand(
    name: 'app:marketplace:ozon-realization-poll',
    description: 'Опрос готовности отчёта «Реализация» Ozon за прошлый месяц (окно: 18:00 МСК 1-го — 8-е число)',
)]
final class OzonRealizationPollCommand extends Command
{
    /** Статусы, которые опрос не трогает: отчёт получен либо ждёт человека (ключи, закрытый месяц, неустранимая ошибка). */
    private const TERMINAL_STATUSES = [
        FinancialReportSyncStatus::SUCCESS,
        FinancialReportSyncStatus::AUTH_FAILED,
        FinancialReportSyncStatus::FAILED_FINAL,
        FinancialReportSyncStatus::CONFLICT,
    ];

    public function __construct(
        private readonly ActiveOzonConnectionsQuery $connectionsQuery,
        private readonly MarketplaceFinancialReportSyncStatusRepository $statusRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Ограничить опрос одной компанией')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что было бы поставлено, ничего не записывая');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyOption = $input->getOption('company-id');
        if (null !== $companyOption && (!is_string($companyOption) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyOption))) {
            $output->writeln('--company-id должен быть UUID');

            return self::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $now = $this->clock->now();
        $window = OzonRealizationPollWindow::at($now);
        if (null === $window) {
            $output->writeln('outside poll window: nothing to do');

            return self::SUCCESS;
        }

        $businessDate = $window->businessDate();
        $output->writeln(sprintf('start: report %04d-%02d', $window->reportYear, $window->reportMonth));

        // Статус хранится по компании: одна задача на компанию (seller-подключение у компании и так одно — уникальный индекс).
        $connections = [];
        foreach ($this->connectionsQuery->execute(is_string($companyOption) ? $companyOption : null) as $connection) {
            $connections[(string) $connection['company_id']] ??= (string) $connection['id'];
        }

        $queued = 0;
        $skippedTerminal = 0;
        $skippedNotDue = 0;

        foreach ($connections as $companyId => $connectionId) {
            $status = $this->statusRepository->findByBusinessDay($companyId, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, $businessDate);

            if (null !== $status && in_array($status->getStatus(), self::TERMINAL_STATUSES, true)) {
                ++$skippedTerminal;
                $output->writeln(sprintf('SKIP company %s: %s', $companyId, $status->getStatus()->value));

                continue;
            }

            // Срок повтора проверяем здесь для всех статусов: claimForQueue() уважает его не у всех (например, у EMPTY — нет).
            if (null !== $status && null !== $status->getNextRetryAt() && $status->getNextRetryAt() > $now) {
                ++$skippedNotDue;
                $output->writeln(sprintf('SKIP company %s: not due', $companyId));

                continue;
            }

            if ($dryRun) {
                ++$queued;
                $output->writeln(sprintf('WOULD QUEUE company %s (dry-run)', $companyId));

                continue;
            }

            $claimed = $this->statusRepository->claimForQueue(
                $connectionId,
                $companyId,
                MarketplaceType::OZON,
                OzonRealizationReport::REPORT_TYPE,
                OzonRealizationReport::apiEndpoint(),
                $businessDate,
                FinancialReportSyncMode::POLL,
                false,
                $now,
            );

            if (null === $claimed) {
                ++$skippedNotDue;
                $output->writeln(sprintf('SKIP company %s: not due', $companyId));

                continue;
            }

            $this->messageBus->dispatch(new SyncOzonRealizationMessage($companyId, $connectionId, $window->reportYear, $window->reportMonth));
            ++$queued;
            $output->writeln(sprintf('QUEUED company %s', $companyId));
        }

        $output->writeln(sprintf('companies: %d, queued: %d, skipped terminal: %d, skipped not due: %d', count($connections), $queued, $skippedTerminal, $skippedNotDue));
        $output->writeln('finish');

        $this->logger->info('Ozon realization poll finished', [
            'report_year' => $window->reportYear,
            'report_month' => $window->reportMonth,
            'companies' => count($connections),
            'queued' => $queued,
            'skipped_terminal' => $skippedTerminal,
            'skipped_not_due' => $skippedNotDue,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
