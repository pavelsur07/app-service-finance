<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Entity\MarketplaceRawProcessingStepRun;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Message\StartMarketplaceRawProcessingMessage;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Обрабатывает запуск daily processing run.
 *
 * Для каждого step run в состоянии PENDING:
 *   1. Переводит в RUNNING.
 *   2. Dispatch ProcessMarketplaceRawDocumentCommand → async worker.
 *
 * Worker-safe: нет Request, Session, Security.
 * companyId передан через Message — не из сессии.
 *
 * Идемпотентен: при retry пропускает step runs не в PENDING,
 * flush() предшествует dispatch() — worker видит актуальный статус в БД.
 */
#[AsMessageHandler]
final class StartMarketplaceRawProcessingHandler
{
    public function __construct(
        private readonly MarketplaceRawProcessingRunRepository $runRepository,
        private readonly MarketplaceRawProcessingStepRunRepository $stepRunRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(StartMarketplaceRawProcessingMessage $message): void
    {
        $companyId       = $message->companyId;
        $processingRunId = $message->processingRunId;

        $run = $this->runRepository->findByIdAndCompany($processingRunId, $companyId);

        if ($run === null) {
            $this->logger->warning('[StartProcessing] Run not found, skipping', [
                'processing_run_id' => $processingRunId,
                'company_id'        => $companyId,
            ]);

            return;
        }

        $stepRuns = $this->stepRunRepository->findByRunId($companyId, $processingRunId);

        if (empty($stepRuns)) {
            $this->logger->warning('[StartProcessing] No step runs found for run', [
                'processing_run_id' => $processingRunId,
                'company_id'        => $companyId,
            ]);

            return;
        }

        // Phase 1: пометить PENDING-шаги как RUNNING
        $toDispatch = [];
        foreach ($stepRuns as $stepRun) {
            if ($stepRun->getStatus() !== PipelineStatus::PENDING) {
                continue;
            }

            $stepRun->markRunning();
            $toDispatch[] = $stepRun;
        }

        // Flush до dispatch — worker видит статус RUNNING в БД,
        // а не только в памяти текущего процесса.
        $this->entityManager->flush();

        // Phase 2: dispatch команды обработки для каждого шага
        $dispatched = 0;
        foreach ($toDispatch as $stepRun) {
            try {
                $this->bus->dispatch(new ProcessMarketplaceRawDocumentCommand(
                    $companyId,
                    $run->getRawDocumentId(),
                    $stepRun->getStep()->value,
                ));
                $dispatched++;
            } catch (\Throwable $e) {
                $this->logger->error('[StartProcessing] Failed to dispatch step command', [
                    'processing_run_id' => $processingRunId,
                    'step'              => $stepRun->getStep()->value,
                    'error'             => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        $this->logger->info('[StartProcessing] Dispatched step commands', [
            'processing_run_id' => $processingRunId,
            'dispatched'        => $dispatched,
            'steps'             => array_map(
                static fn(MarketplaceRawProcessingStepRun $sr) => $sr->getStep()->value,
                $toDispatch,
            ),
        ]);
    }
}
