<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\FinalizeMarketplaceRawProcessingCommand;
use App\Marketplace\Entity\MarketplaceRawProcessingStepRun;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Финализирует daily processing run после завершения всех обязательных шагов.
 *
 * Логика:
 *   - Run уже в terminal-статусе → return (идемпотентно).
 *   - Есть незавершённые (не-terminal) шаги → return (ждём).
 *   - Все шаги terminal и нет FAILED → run = COMPLETED + aggregated summary/details.
 *   - Хотя бы один шаг FAILED → run = FAILED + lastErrorMessage + summary/details.
 *
 * Вызывается из FinalizeMarketplaceRawProcessingHandler после каждого
 * терминального события шага. Идемпотентен: повторный вызов безопасен.
 */
final class FinalizeMarketplaceRawProcessingAction
{
    public function __construct(
        private readonly MarketplaceRawProcessingRunRepository $runRepository,
        private readonly MarketplaceRawProcessingStepRunRepository $stepRunRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(FinalizeMarketplaceRawProcessingCommand $cmd): void
    {
        // 1. Загрузить run с IDOR-проверкой
        $run = $this->runRepository->findByIdAndCompany($cmd->processingRunId, $cmd->companyId);
        if ($run === null) {
            throw new \DomainException(sprintf(
                'Processing run "%s" not found for company "%s".',
                $cmd->processingRunId,
                $cmd->companyId,
            ));
        }

        // 2. Идемпотентность: run уже в terminal-статусе — ничего не делаем
        if ($run->getStatus()->isTerminal()) {
            $this->logger->info('[Finalize] Run already in terminal state, skipping', [
                'processing_run_id' => $cmd->processingRunId,
                'status'            => $run->getStatus()->value,
            ]);

            return;
        }

        // 3. Загрузить все шаги run
        $stepRuns = $this->stepRunRepository->findByRunId($cmd->companyId, $cmd->processingRunId);

        if (empty($stepRuns)) {
            // Шагов нет — run тривиально завершён (warning, но не ошибка)
            $this->logger->warning('[Finalize] No step runs found, completing run immediately', [
                'processing_run_id' => $cmd->processingRunId,
            ]);

            $run->markCompleted(['steps_total' => 0, 'steps_completed' => 0, 'steps_failed' => 0]);
            $this->entityManager->flush();

            return;
        }

        // 4. Проверить: все ли шаги в terminal-статусе?
        foreach ($stepRuns as $stepRun) {
            if (!$stepRun->getStatus()->isTerminal()) {
                $this->logger->info('[Finalize] Step still running, deferring finalization', [
                    'processing_run_id' => $cmd->processingRunId,
                    'step'              => $stepRun->getStep()->value,
                    'status'            => $stepRun->getStatus()->value,
                ]);

                return;
            }
        }

        // 5. Все шаги terminal — агрегировать summary и details
        $failedStepRuns = array_values(array_filter(
            $stepRuns,
            static fn(MarketplaceRawProcessingStepRun $sr) => $sr->getStatus() === PipelineStatus::FAILED,
        ));

        $summary = $this->buildSummary($stepRuns, $failedStepRuns);
        $details = $this->buildDetails($stepRuns);

        // 6. Финализировать run
        if (count($failedStepRuns) > 0) {
            $errorMessage = $this->buildErrorMessage($failedStepRuns);

            $run->markFailed($errorMessage, $summary, $details);

            $this->logger->error('[Finalize] Run finalized as FAILED', [
                'processing_run_id' => $cmd->processingRunId,
                'failed_steps'      => array_map(
                    static fn(MarketplaceRawProcessingStepRun $sr) => $sr->getStep()->value,
                    $failedStepRuns,
                ),
                'error'             => $errorMessage,
            ]);
        } else {
            $run->markCompleted($summary, $details);

            $this->logger->info('[Finalize] Run finalized as COMPLETED', [
                'processing_run_id' => $cmd->processingRunId,
                'total_processed'   => $summary['total_processed'],
            ]);
        }

        $this->entityManager->flush();
    }

    /**
     * @param MarketplaceRawProcessingStepRun[] $stepRuns
     * @param MarketplaceRawProcessingStepRun[] $failedStepRuns
     * @return array<string, int>
     */
    private function buildSummary(array $stepRuns, array $failedStepRuns): array
    {
        return [
            'total_processed' => (int) array_sum(array_map(
                static fn(MarketplaceRawProcessingStepRun $sr) => $sr->getProcessedCount(),
                $stepRuns,
            )),
            'total_failed' => (int) array_sum(array_map(
                static fn(MarketplaceRawProcessingStepRun $sr) => $sr->getFailedCount(),
                $stepRuns,
            )),
            'total_skipped' => (int) array_sum(array_map(
                static fn(MarketplaceRawProcessingStepRun $sr) => $sr->getSkippedCount(),
                $stepRuns,
            )),
            'steps_total'     => count($stepRuns),
            'steps_completed' => count($stepRuns) - count($failedStepRuns),
            'steps_failed'    => count($failedStepRuns),
        ];
    }

    /**
     * @param MarketplaceRawProcessingStepRun[] $stepRuns
     * @return array{steps: array<int, array<string, mixed>>}
     */
    private function buildDetails(array $stepRuns): array
    {
        $steps = array_map(static function (MarketplaceRawProcessingStepRun $sr): array {
            $step = [
                'step'      => $sr->getStep()->value,
                'status'    => $sr->getStatus()->value,
                'processed' => $sr->getProcessedCount(),
                'failed'    => $sr->getFailedCount(),
                'skipped'   => $sr->getSkippedCount(),
            ];

            if ($sr->getErrorMessage() !== null) {
                $step['error'] = $sr->getErrorMessage();
            }

            return $step;
        }, $stepRuns);

        return ['steps' => $steps];
    }

    /**
     * @param MarketplaceRawProcessingStepRun[] $failedStepRuns
     */
    private function buildErrorMessage(array $failedStepRuns): string
    {
        $parts = array_map(
            static fn(MarketplaceRawProcessingStepRun $sr) => sprintf(
                '[%s] %s',
                $sr->getStep()->value,
                $sr->getErrorMessage() ?? 'Unknown error',
            ),
            $failedStepRuns,
        );

        return implode('; ', $parts);
    }
}
