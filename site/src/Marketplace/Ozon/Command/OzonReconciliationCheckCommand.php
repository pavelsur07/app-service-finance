<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Command;

use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Ozon\Application\Action\RunOzonReconciliationAction;
use App\Marketplace\Ozon\Application\Reconciliation\ReconciliationMonth;
use App\Marketplace\Ozon\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Repository\OzonReconciliationLineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ночной гейт сверки с Ozon: пересчитывает снимки за текущий и прошлый месяц у всех компаний
 * с активным Ozon-подключением и сигналит, если данным нельзя доверять.
 *
 * Охват гейта равен охвату починки (`docs/workflow/health-gates.md`):
 * - красным считается расхождение «сырые данные ↔ учёт» — его чинит повторная загрузка дня
 *   (`app:marketplace:ozon-financial-reports:sync`) и пересчёт (`app:marketplace:reprocess`);
 * - расхождение «Реализация ↔ сырые данные» — данные самого Ozon, чинить нечем: `warning`, exit 0;
 * - «нет данных» (например, «Реализации» за текущий месяц ещё нет) — не красное.
 * Сбой сверки самой компании не обрывает обход и считается красным.
 * Команда пишет только снимки сверки.
 */
#[AsCommand(
    name: 'app:marketplace:ozon-reconciliation:check',
    description: 'Гейт: пересчёт сверки с Ozon за текущий и прошлый месяц, ненулевой exit при расхождении «сырьё ↔ учёт»',
)]
final class OzonReconciliationCheckCommand extends Command
{
    private const TIMEZONE = 'Europe/Moscow';
    private const MAX_LOGGED_COMPANIES = 20;

    public function __construct(
        private readonly ActiveOzonConnectionsQuery $connectionsQuery,
        private readonly RunOzonReconciliationAction $action,
        private readonly OzonReconciliationLineRepository $lineRepository,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Ограничить обход одной компанией')
            ->addOption('report-only', null, InputOption::VALUE_NONE, 'Пересчитать и вывести, не падать и не писать error');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyOption = $input->getOption('company-id');
        if (null !== $companyOption && (!is_string($companyOption) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyOption))) {
            $output->writeln('--company-id должен быть UUID');

            return self::INVALID;
        }
        $reportOnly = (bool) $input->getOption('report-only');

        $current = ReconciliationMonth::containing($this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE)));
        $months = [$current, ReconciliationMonth::containing($current->from->modify('-1 day'))];

        $companyIds = [];
        foreach ($this->connectionsQuery->execute(is_string($companyOption) ? $companyOption : null) as $connection) {
            $companyIds[(string) $connection['company_id']] = true;
        }
        $companyIds = array_keys($companyIds);

        $output->writeln('start');
        $output->writeln(sprintf('months: %s', implode(', ', array_map(static fn (ReconciliationMonth $m): string => $m->value(), $months))));

        $ledgerLines = 0;
        $realizationLines = 0;
        $failures = 0;
        $checked = 0;
        $redCompanies = [];
        $realizationCompanies = [];

        foreach ($companyIds as $companyId) {
            foreach ($months as $month) {
                try {
                    $run = ($this->action)($companyId, $month->from, $month->to());
                    ++$checked;

                    $ledger = 0;
                    $realization = 0;
                    foreach ($this->lineRepository->findByRun($companyId, $run->getId()) as $line) {
                        if ('' !== $line->getCategoryCode() || OzonReconciliationStatus::MISMATCH !== $line->getStatus()) {
                            continue;
                        }
                        if (OzonReconciliationCheck::RAW_VS_LEDGER === $line->getCheck()) {
                            ++$ledger;
                        } else {
                            ++$realization;
                        }
                    }

                    $ledgerLines += $ledger;
                    $realizationLines += $realization;
                    if ($ledger > 0) {
                        $redCompanies[$companyId] = true;
                    }
                    if ($realization > 0) {
                        $realizationCompanies[$companyId] = true;
                    }

                    $output->writeln(sprintf(
                        '%s company %s %s: %s, raw_vs_ledger mismatches %d, realization_vs_raw mismatches %d',
                        $ledger > 0 ? 'MISMATCH' : 'OK',
                        $companyId,
                        $month->value(),
                        $run->getOverallStatus()->value,
                        $ledger,
                        $realization,
                    ));
                } catch (\Throwable $e) {
                    ++$failures;
                    $output->writeln(sprintf('FAILED company %s %s: %s', $companyId, $month->value(), $e::class));
                    if (!$this->em->isOpen()) {
                        // Закрытый EntityManager дальше не восстановить: остаток обхода молча падал бы тем же.
                        $output->writeln('entity manager closed, stopping');
                        break 2;
                    }
                } finally {
                    $this->em->clear();
                }
            }
        }

        $output->writeln(sprintf('checked companies count: %d', count($companyIds)));
        $output->writeln(sprintf('checked snapshots count: %d', $checked));
        $output->writeln(sprintf('raw vs ledger mismatches count: %d', $ledgerLines));
        $output->writeln(sprintf('realization vs raw mismatches count: %d', $realizationLines));
        $output->writeln(sprintf('failures count: %d', $failures));
        $output->writeln('finish');

        if ($realizationLines > 0) {
            // Данные самого Ozon: чинить нечем, поэтому не инцидент. Видно в логе и на вкладке.
            $this->logger->warning('Ozon realization report disagrees with raw accruals.', [
                'mismatch_lines' => $realizationLines,
                'companies' => count($realizationCompanies),
            ]);
        }

        if (($ledgerLines > 0 || $failures > 0) && !$reportOnly) {
            // Один агрегированный error на запуск: человека будить один раз, а не по компании и строке.
            $this->logger->error('Ozon reconciliation found data that cannot be trusted.', [
                'checked_companies' => count($companyIds),
                'checked_snapshots' => $checked,
                'raw_vs_ledger_mismatch_lines' => $ledgerLines,
                'affected_companies' => count($redCompanies),
                'failures' => $failures,
                'company_ids' => array_slice(array_keys($redCompanies), 0, self::MAX_LOGGED_COMPANIES),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
