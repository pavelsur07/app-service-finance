<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\RunMarketplaceRawProcessingStepCommand;
use App\Marketplace\Application\RunMarketplaceRawProcessingStepAction;
use App\Marketplace\Message\RunMarketplaceRawProcessingStepMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Запускает выполнение одного шага daily processing run.
 *
 * Worker-safe: нет Request, Session, Security.
 * companyId, processingRunId, stepRunId переданы через Message — не из сессии.
 *
 * Обработка ошибок:
 *   - DomainException (бизнес-ошибка: шаг failed, инвалидное состояние) →
 *     логируется, сообщение ackнуто без retry. Step помечен FAILED в БД.
 *   - Прочие исключения (инфраструктура: DB down, etc.) →
 *     пробрасываются, Messenger делает retry по retry_strategy.
 */
#[AsMessageHandler]
final class RunMarketplaceRawProcessingStepHandler
{
    public function __construct(
        private readonly RunMarketplaceRawProcessingStepAction $action,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RunMarketplaceRawProcessingStepMessage $message): void
    {
        try {
            ($this->action)(new RunMarketplaceRawProcessingStepCommand(
                $message->companyId,
                $message->processingRunId,
                $message->stepRunId,
            ));
        } catch (\DomainException $e) {
            // Бизнес-ошибка: шаг уже помечен FAILED в Action.
            // Нет смысла делать retry — проблема в данных, не в инфраструктуре.
            $this->logger->error('[RunStepHandler] Step execution failed, no retry', [
                'step_run_id'       => $message->stepRunId,
                'processing_run_id' => $message->processingRunId,
                'company_id'        => $message->companyId,
                'error'             => $e->getMessage(),
            ]);
            // Не перебрасываем — Messenger считает сообщение обработанным.
        }
        // Прочие исключения (Throwable) пробрасываются → retry по messenger.yaml retry_strategy.
    }
}
