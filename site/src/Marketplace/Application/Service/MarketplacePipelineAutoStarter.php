<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Application\Command\StartMarketplaceRawProcessingCommand;
use App\Marketplace\Application\StartMarketplaceRawProcessingAction;
use App\Marketplace\Enum\PipelineTrigger;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use Psr\Log\LoggerInterface;

/**
 * Автозапуск daily pipeline после успешного raw import.
 *
 * Используется из SyncWbReportHandler, SyncOzonReportHandler, SyncConnectionAction.
 * Изолирует логику дедупликации и best-effort обработки ошибок.
 *
 * Дедупликация: если для raw document уже существует активный (не-terminal) run — пропустить.
 * Best-effort: любой Throwable логируется, вызывающий код не прерывается.
 */
final readonly class MarketplacePipelineAutoStarter
{
    public function __construct(
        private StartMarketplaceRawProcessingAction $startProcessingAction,
        private MarketplaceRawProcessingRunRepository $runRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Запускает daily pipeline для сохранённого raw document.
     * Не бросает исключений — ошибки логируются.
     */
    public function tryStart(string $companyId, string $rawDocId): void
    {
        $existingRun = $this->runRepository->findLatestByRawDocument($companyId, $rawDocId);
        if ($existingRun !== null && !$existingRun->getStatus()->isTerminal()) {
            $this->logger->info('[AutoStart] Active run already exists, skipping', [
                'raw_doc_id' => $rawDocId,
                'run_id'     => $existingRun->getId(),
                'status'     => $existingRun->getStatus()->value,
            ]);

            return;
        }

        try {
            ($this->startProcessingAction)(new StartMarketplaceRawProcessingCommand(
                $companyId,
                $rawDocId,
                PipelineTrigger::AUTO,
            ));

            $this->logger->info('[AutoStart] Daily pipeline started', [
                'company_id' => $companyId,
                'raw_doc_id' => $rawDocId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[AutoStart] Failed to start daily pipeline', [
                'company_id' => $companyId,
                'raw_doc_id' => $rawDocId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
