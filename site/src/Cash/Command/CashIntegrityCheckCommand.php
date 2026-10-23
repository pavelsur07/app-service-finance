<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\CloseLostCashFileImportJobsAction;
use App\Cash\Application\CloseOpenImportLogsAction;
use App\Cash\Application\RecalculateAllocatedAmountsAction;
use App\Cash\Infrastructure\Query\CashIntegrityCompaniesQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only гейт целостности ДДС: состояния, которые этап 4 cash-pl-idempotency нашёл спустя месяцы.
 *
 * - потерянные задания файлового импорта — dry-run `CloseLostCashFileImportJobsAction` (`queued`
 *   старше 24 ч, сообщения нет ни в failed, ни в очереди Redis); починка —
 *   `app:cash:close-lost-import-jobs`;
 * - `allocated_amount` ≠ сумме живых документов ОПиУ — dry-run `RecalculateAllocatedAmountsAction`;
 *   починка — `app:cash:recalculate-allocated-amounts`;
 * - журнал импорта без времени завершения дольше 24 ч, не ждущий живого задания, — dry-run
 *   `CloseOpenImportLogsAction`; починка — `app:cash:close-open-import-logs`.
 *
 * Предикаты — те же, что у ремонта (охват проверки = охват починки). Находки — один агрегированный
 * `error` со стабильным текстом + exit 1. Очередь Redis недоступна — отдельный `error`: потерю
 * доказать нельзя, а «0 потерянных» было бы ложью.
 */
#[AsCommand(
    name: 'app:cash:integrity-check',
    description: 'Read-only gate: потерянные задания импорта ДДС, устаревший allocated_amount, незакрытые журналы импорта.',
)]
final class CashIntegrityCheckCommand extends Command
{
    private const STALE_AGE_HOURS = 24;

    public function __construct(
        private readonly CashIntegrityCompaniesQuery $companies,
        private readonly CloseLostCashFileImportJobsAction $lostJobs,
        private readonly RecalculateAllocatedAmountsAction $allocatedAmounts,
        private readonly CloseOpenImportLogsAction $openImportLogs,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('start');

        $lostJobs = [];
        $unavailable = null;
        $createdBefore = new \DateTimeImmutable(sprintf('-%d hours', self::STALE_AGE_HOURS));
        try {
            foreach ($this->companies->withQueuedImportJobsBefore($createdBefore) as $companyId) {
                $candidates = ($this->lostJobs)($companyId, $createdBefore, false)['candidates'];
                if ($candidates > 0) {
                    $lostJobs[$companyId] = $candidates;
                }
            }
        } catch (\Throwable $exception) {
            $unavailable = $exception;
        }

        $staleAllocated = [];
        $staleAllocatedCompanies = $this->companies->withLiveTransactions();
        foreach ($staleAllocatedCompanies as $companyId) {
            $candidates = ($this->allocatedAmounts)($companyId, false)['candidates'];
            if ($candidates > 0) {
                $staleAllocated[$companyId] = $candidates;
            }
        }

        $openLogs = [];
        $logsStartedBefore = new \DateTimeImmutable(sprintf('-%d hours', self::STALE_AGE_HOURS), new \DateTimeZone('UTC'));
        foreach ($this->companies->withOpenImportLogsBefore($logsStartedBefore) as $companyId) {
            $candidates = ($this->openImportLogs)($companyId, $logsStartedBefore, false)['candidates'];
            if ($candidates > 0) {
                $openLogs[$companyId] = $candidates;
            }
        }

        $output->writeln(null === $unavailable
            ? sprintf('lost import jobs: %d', array_sum($lostJobs))
            : sprintf('<error>lost import jobs: UNAVAILABLE: %s</error>', OutputFormatter::escape($unavailable->getMessage())));
        foreach ($lostJobs as $companyId => $count) {
            $output->writeln(sprintf('  %s: %d', $companyId, $count));
        }
        $output->writeln(sprintf('stale allocated_amount: %d (companies checked: %d)', array_sum($staleAllocated), \count($staleAllocatedCompanies)));
        foreach ($staleAllocated as $companyId => $count) {
            $output->writeln(sprintf('  %s: %d', $companyId, $count));
        }
        $output->writeln(sprintf('open import logs: %d', array_sum($openLogs)));
        foreach ($openLogs as $companyId => $count) {
            $output->writeln(sprintf('  %s: %d', $companyId, $count));
        }
        $output->writeln('finish');

        $status = Command::SUCCESS;
        if (null !== $unavailable) {
            $this->logger->error('Cash integrity check unavailable', ['exception' => $unavailable]);
            $status = Command::FAILURE;
        }
        if ([] !== $lostJobs || [] !== $staleAllocated || [] !== $openLogs) {
            $this->logger->error('Cash integrity check failed', [
                'lost_import_jobs' => array_sum($lostJobs),
                'lost_import_jobs_by_company' => $lostJobs,
                'stale_allocated_amount' => array_sum($staleAllocated),
                'stale_allocated_amount_by_company' => $staleAllocated,
                'open_import_logs' => array_sum($openLogs),
                'open_import_logs_by_company' => $openLogs,
            ]);
            $status = Command::FAILURE;
        }

        return $status;
    }
}
