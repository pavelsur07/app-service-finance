<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Command;

use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationPollWindow;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\OzonRealizationAppliedQuery;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт «Реализации» Ozon: после окна опроса (с 9-го числа) у каждой компании с активным seller-подключением
 * отчёт за прошлый месяц обязан быть получен и применён в учёт. Read-only.
 *
 * Охват гейта равен охвату починки (`docs/workflow/health-gates.md`):
 * - красным считается отсутствие отчёта, ошибка ключа и сбой обработки — чинится ручной загрузкой/«Применить выручку»
 *   или обновлением ключа; после этого пара становится `success` или строки появляются в `marketplace_ozon_realizations`;
 * - `conflict` (этап «Продажи/возвраты» закрыт) — `warning`: отчёт получен, а решение о закрытом месяце за человеком;
 * - гейт работает с 9-го по 16-е число (неделя на починку), дальше молчит, чтобы не краснеть до конца месяца;
 * - `error` и ненулевой exit code — один раз, 9-го числа (человека будят один раз); с 10-го по 16-е отсутствие отчёта —
 *   `warning` и строки в выводе: компания без продаж за месяц или с пустым отчётом Ozon иначе краснила бы гейт без возможности починить.
 */
#[AsCommand(
    name: 'app:marketplace:ozon-realization-check',
    description: 'Гейт: «Реализация» Ozon за прошлый месяц получена и применена (проверяется с 9-го по 16-е число)',
)]
final class OzonRealizationCheckCommand extends Command
{
    private const FIRST_DAY = OzonRealizationPollWindow::LAST_DAY + 1;
    private const LAST_DAY = 16;
    private const MAX_LOGGED_COMPANIES = 20;

    public function __construct(
        private readonly ActiveOzonConnectionsQuery $connectionsQuery,
        private readonly MarketplaceFinancialReportSyncStatusRepository $statusRepository,
        private readonly OzonRealizationAppliedQuery $appliedQuery,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Ограничить проверку одной компанией')
            ->addOption('report-only', null, InputOption::VALUE_NONE, 'Показать итог, не падать и не писать error');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyOption = $input->getOption('company-id');
        if (null !== $companyOption && (!is_string($companyOption) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyOption))) {
            $output->writeln('--company-id должен быть UUID');

            return self::INVALID;
        }
        $reportOnly = (bool) $input->getOption('report-only');

        $local = $this->clock->now()->setTimezone(new \DateTimeZone(OzonRealizationPollWindow::TIMEZONE));
        $day = (int) $local->format('j');
        if ($day < self::FIRST_DAY || $day > self::LAST_DAY) {
            $output->writeln('outside gate period: nothing to check');

            return self::SUCCESS;
        }

        $monthStart = $local->modify('first day of last month')->setTime(0, 0);
        $output->writeln(sprintf('start: report %s', $monthStart->format('Y-m')));

        $ok = 0;
        $conflicts = 0;
        $missing = [];

        foreach ($this->connectionsQuery->execute(is_string($companyOption) ? $companyOption : null) as $connection) {
            $companyId = (string) $connection['company_id'];
            if (isset($missing[$companyId])) {
                continue;
            }

            $status = $this->statusRepository->findByBusinessDay($companyId, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, $monthStart);

            if (FinancialReportSyncStatus::SUCCESS === $status?->getStatus() || $this->appliedQuery->isApplied($companyId, $monthStart)) {
                ++$ok;
                $output->writeln(sprintf('OK company %s', $companyId));

                continue;
            }

            if (FinancialReportSyncStatus::CONFLICT === $status?->getStatus()) {
                ++$conflicts;
                $output->writeln(sprintf('CONFLICT company %s: stage closed, report not applied', $companyId));

                continue;
            }

            $state = $status?->getStatus()->value ?? 'never_polled';
            $missing[$companyId] = $state;
            $output->writeln(sprintf('MISSING company %s: %s', $companyId, $state));
        }

        $output->writeln(sprintf('ok count: %d', $ok));
        $output->writeln(sprintf('conflict count: %d', $conflicts));
        $output->writeln(sprintf('missing count: %d', count($missing)));
        $output->writeln('finish');

        if ($conflicts > 0) {
            $this->logger->warning('Ozon realization not applied: sales/returns stage is closed.', ['report_month' => $monthStart->format('Y-m'), 'companies' => $conflicts]);
        }

        if ([] === $missing || $reportOnly) {
            return self::SUCCESS;
        }

        if ($day > self::FIRST_DAY) {
            $this->logger->warning('Ozon realization report is still missing.', [
                'report_month' => $monthStart->format('Y-m'),
                'missing' => count($missing),
                'company_ids' => array_slice(array_keys($missing), 0, self::MAX_LOGGED_COMPANIES),
            ]);

            return self::SUCCESS;
        }

        // Один агрегированный error на запуск: человека будить один раз, а не по компании.
        $this->logger->error('Ozon realization report is missing after the polling window.', [
            'report_month' => $monthStart->format('Y-m'),
            'missing' => count($missing),
            'company_ids' => array_slice(array_keys($missing), 0, self::MAX_LOGGED_COMPANIES),
            'states' => array_count_values($missing),
        ]);

        return self::FAILURE;
    }
}
