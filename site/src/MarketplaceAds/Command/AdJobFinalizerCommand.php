<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finalizer cron для cron-driven Ozon Performance pipeline (Task-11.7).
 *
 * Сканирует все `AdLoadJob` в статусе RUNNING, агрегирует статусы их batch'ей
 * через {@see AdScheduledBatchRepository::countStatesForJob()} и выставляет
 * терминальный статус job'у, когда ВСЕ его batch'и — в терминальных
 * состояниях OK / FAILED / ABANDONED.
 *
 * Семантика:
 *  - есть хотя бы один PLANNED / IN_FLIGHT batch  → не трогаем (ждём);
 *  - все batch'и OK                                → job=COMPLETED;
 *  - все batch'и FAILED/ABANDONED (0 OK)           → job=FAILED с reason
 *    «All N batches failed»;
 *  - микс (есть OK и есть FAILED/ABANDONED)        → job=PARTIAL_SUCCESS
 *    с reason «N of M batches failed».
 *
 * Job без batch'ей — аномалия (Planner должен был создать хотя бы один),
 * логируется warning'ом и не финализируется, чтобы не маскировать проблему.
 *
 * {@see LockableTrait} — наложение cron-тиков на параллельные тики не мешает:
 * `markXxx` идемпотентен (UPDATE guard `status IN pending/running`), но
 * повторная отправка в БД одних и тех же UPDATE'ов — впустую. Лок снимает
 * это без ущерба корректности.
 *
 * Не подключён в cron в рамках Task-11.7 — все три команды (scheduler +
 * poller + finalizer) включатся одним релизом перед Task-11.9.
 */
#[AsCommand(
    name: 'app:marketplace-ads:finalizer',
    description: 'Finalize RUNNING jobs when all their scheduled batches are terminal',
)]
final class AdJobFinalizerCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly AdLoadJobRepositoryInterface $jobRepository,
        private readonly AdScheduledBatchRepository $batchRepository,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>Another finalizer is running, skipping.</comment>');

            return self::SUCCESS;
        }

        try {
            $jobs = $this->jobRepository->findAllRunning();

            if ([] === $jobs) {
                $output->writeln('<info>No RUNNING jobs.</info>');

                return self::SUCCESS;
            }

            $output->writeln(sprintf('Checking %d RUNNING jobs for finalization', count($jobs)));

            $finalized = 0;
            $stillRunning = 0;

            foreach ($jobs as $job) {
                try {
                    if ($this->tryFinalize($job->getId(), $job->getCompanyId())) {
                        ++$finalized;
                    } else {
                        ++$stillRunning;
                    }
                } catch (\Throwable $e) {
                    // Per-job isolation: сбой на одном job'е не прерывает сканирование остальных.
                    $this->logger->error('Finalizer: job processing failed', [
                        'jobId' => $job->getId(),
                        'companyId' => $job->getCompanyId(),
                        'error' => $e->getMessage(),
                        'exception' => $e::class,
                    ]);
                }
            }

            $output->writeln(sprintf(
                '<info>Totals: finalized=%d still_running=%d</info>',
                $finalized,
                $stillRunning,
            ));

            return self::SUCCESS;
        } finally {
            $this->release();
        }
    }

    /**
     * @return bool true если job финализирован, false если рано либо пропущен
     */
    private function tryFinalize(string $jobId, string $companyId): bool
    {
        $stats = $this->batchRepository->countStatesForJob($jobId, $companyId);

        if ([] === $stats) {
            // Job без batch'ей — Planner должен был создать хотя бы один.
            // Логируем и НЕ финализируем, чтобы не замаскировать проблему:
            // оператор увидит warning и сможет разобраться.
            $this->logger->warning('Finalizer: job has no batches, skipping', [
                'jobId' => $jobId,
                'companyId' => $companyId,
            ]);

            return false;
        }

        $planned = $stats['PLANNED'] ?? 0;
        $inFlight = $stats['IN_FLIGHT'] ?? 0;
        $ok = $stats['OK'] ?? 0;
        $failed = $stats['FAILED'] ?? 0;
        $abandoned = $stats['ABANDONED'] ?? 0;

        // Есть хотя бы один не-терминальный batch → рано финализировать.
        if ($planned > 0 || $inFlight > 0) {
            return false;
        }

        $total = $ok + $failed + $abandoned;
        $failedTotal = $failed + $abandoned;

        if ($ok === $total) {
            // Все batch'и OK — full success.
            $this->jobRepository->markCompleted($jobId, $companyId);
            $resolvedStatus = 'completed';
        } elseif (0 === $ok) {
            // Ни одного OK — full failure.
            $reason = sprintf('All %d batches failed', $failedTotal);
            $this->jobRepository->markFailed($jobId, $companyId, $reason);
            $resolvedStatus = 'failed';
        } else {
            // Микс — partial success.
            $reason = sprintf('%d of %d batches failed', $failedTotal, $total);
            $this->jobRepository->markPartialSuccess($jobId, $companyId, $reason);
            $resolvedStatus = 'partial_success';
        }

        $this->logger->info('Finalizer: job finalized', [
            'jobId' => $jobId,
            'companyId' => $companyId,
            'status' => $resolvedStatus,
            'ok' => $ok,
            'failed' => $failed,
            'abandoned' => $abandoned,
        ]);

        return true;
    }
}
