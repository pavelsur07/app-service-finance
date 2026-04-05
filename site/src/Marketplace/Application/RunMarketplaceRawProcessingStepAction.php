<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\RunMarketplaceRawProcessingStepCommand;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Domain\Service\ResolveMarketplaceRawProcessingProfile;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Выполняет один шаг daily processing run через существующий raw processor.
 *
 * Действия:
 *   1. Загрузить run и step run по companyId (IDOR-защита).
 *   2. Проверить принадлежность step run к run.
 *   3. Идемпотентно пропустить уже завершённые (terminal) шаги.
 *   4. Загрузить raw document, проверить companyId.
 *   5. Убедиться, что шаг входит в processing profile документа.
 *   6. Перевести step в RUNNING (если ещё PENDING).
 *   7. Найти нужный processor через registry (marketplace + step).
 *   8. Выполнить processor->process(); обновить counts и status step run.
 *   9. При ошибке — пометить step как FAILED, rethrow.
 *
 * НЕ изменяет ReprocessMarketplacePeriodAction и ручной flow.
 */
final class RunMarketplaceRawProcessingStepAction
{
    public function __construct(
        private readonly MarketplaceRawProcessingRunRepository $runRepository,
        private readonly MarketplaceRawProcessingStepRunRepository $stepRunRepository,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly MarketplaceRawProcessorRegistryInterface $processorRegistry,
        private readonly ResolveMarketplaceRawProcessingProfile $profileResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return int количество обработанных записей
     * @throws \DomainException если run/step не найдены, шаг не в профиле, или обработка провалилась
     */
    public function __invoke(RunMarketplaceRawProcessingStepCommand $cmd): int
    {
        // 1. Загрузить run
        $run = $this->runRepository->findByIdAndCompany($cmd->processingRunId, $cmd->companyId);
        if ($run === null) {
            throw new \DomainException(sprintf(
                'Processing run "%s" not found for company "%s".',
                $cmd->processingRunId,
                $cmd->companyId,
            ));
        }

        // 2. Загрузить step run
        $stepRun = $this->stepRunRepository->findByIdAndCompany($cmd->stepRunId, $cmd->companyId);
        if ($stepRun === null) {
            throw new \DomainException(sprintf(
                'Step run "%s" not found for company "%s".',
                $cmd->stepRunId,
                $cmd->companyId,
            ));
        }

        // 3. Проверить принадлежность step run к run
        if ($stepRun->getProcessingRunId() !== $run->getId()) {
            throw new \DomainException(sprintf(
                'Step run "%s" does not belong to processing run "%s".',
                $cmd->stepRunId,
                $cmd->processingRunId,
            ));
        }

        // 4. Идемпотентно пропустить terminal-шаги
        if ($stepRun->getStatus()->isTerminal()) {
            $this->logger->info('[RunStep] Step run already in terminal state, skipping', [
                'step_run_id' => $cmd->stepRunId,
                'step'        => $stepRun->getStep()->value,
                'status'      => $stepRun->getStatus()->value,
            ]);

            return 0;
        }

        // 5. Загрузить raw document (с IDOR-проверкой)
        $doc = $this->rawDocumentRepository->find($run->getRawDocumentId());
        if ($doc === null || $doc->getCompany()->getId() !== $cmd->companyId) {
            throw new \DomainException(sprintf(
                'Raw document "%s" not found for company "%s".',
                $run->getRawDocumentId(),
                $cmd->companyId,
            ));
        }

        // 6. Проверить, что шаг входит в processing profile
        $profile = $this->profileResolver->resolve($doc->getMarketplace(), $doc->getDocumentType());
        if (!$profile->requiresStep($stepRun->getStep())) {
            throw new \DomainException(sprintf(
                'Step "%s" is not part of the processing profile for document type "%s".',
                $stepRun->getStep()->value,
                $doc->getDocumentType(),
            ));
        }

        // 7. Перевести в RUNNING, если ещё PENDING
        if ($stepRun->getStatus() === PipelineStatus::PENDING) {
            $stepRun->markRunning();
            $this->entityManager->flush();
        }

        // 8. Найти processor по marketplace + step
        $processor = $this->processorRegistry->get(
            $doc->getMarketplace()->value,
            $doc->getMarketplace(),
            $stepRun->getStep()->value,
        );

        // 9. Выполнить шаг
        try {
            $processedCount = $processor->process($cmd->companyId, $run->getRawDocumentId());

            $stepRun->markCompleted($processedCount, 0, 0);
            $this->entityManager->flush();

            $this->logger->info('[RunStep] Step completed', [
                'step_run_id'    => $cmd->stepRunId,
                'step'           => $stepRun->getStep()->value,
                'processed'      => $processedCount,
            ]);

            return $processedCount;
        } catch (\Throwable $e) {
            $this->logger->error('[RunStep] Step failed', [
                'step_run_id' => $cmd->stepRunId,
                'step'        => $stepRun->getStep()->value,
                'error'       => $e->getMessage(),
            ]);

            try {
                $stepRun->markFailed($e->getMessage() ?: 'Unknown processing error');
                $this->entityManager->flush();
            } catch (\Throwable $flushError) {
                $this->logger->error('[RunStep] Failed to persist FAILED status', [
                    'step_run_id' => $cmd->stepRunId,
                    'error'       => $flushError->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
