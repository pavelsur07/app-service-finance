<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Message\RunMarketplaceRawProcessingStepMessage;
use App\Marketplace\Message\StartMarketplaceRawProcessingMessage;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Обрабатывает запуск daily processing run.
 *
 * Для каждого PENDING step run диспатчит RunMarketplaceRawProcessingStepMessage.
 * Переход PENDING → RUNNING происходит в RunMarketplaceRawProcessingStepAction (шаг 7),
 * что делает handler идемпотентным при retry: не-PENDING шаги пропускаются,
 * а незадиспатченные PENDING-шаги будут отправлены повторно.
 *
 * Worker-safe: нет Request, Session, Security.
 */
#[AsMessageHandler]
final class StartMarketplaceRawProcessingHandler
{
    public function __construct(
        private readonly MarketplaceRawProcessingRunRepository $runRepository,
        private readonly MarketplaceRawProcessingStepRunRepository $stepRunRepository,
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

        // Dispatch сообщение для каждого PENDING-шага.
        // Не помечаем RUNNING здесь — RunMarketplaceRawProcessingStepAction делает это
        // атомарно перед вызовом processor (шаг 7). Это позволяет корректно повторить
        // StartMarketplaceRawProcessingMessage при сбое dispatch: на retry handler снова
        // найдёт незадиспатченные PENDING-шаги и отправит сообщения.
        $dispatched = 0;
        foreach ($stepRuns as $stepRun) {
            if ($stepRun->getStatus() !== PipelineStatus::PENDING) {
                continue;
            }

            try {
                $this->bus->dispatch(new RunMarketplaceRawProcessingStepMessage(
                    $companyId,
                    $processingRunId,
                    $stepRun->getId(),
                ));
                $dispatched++;
            } catch (\Throwable $e) {
                $this->logger->error('[StartProcessing] Failed to dispatch step message', [
                    'processing_run_id' => $processingRunId,
                    'step'              => $stepRun->getStep()->value,
                    'step_run_id'       => $stepRun->getId(),
                    'error'             => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        $this->logger->info('[StartProcessing] Dispatched step messages', [
            'processing_run_id' => $processingRunId,
            'dispatched'        => $dispatched,
        ]);
    }
}
