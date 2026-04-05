<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\FinalizeMarketplaceRawProcessingCommand;
use App\Marketplace\Application\FinalizeMarketplaceRawProcessingAction;
use App\Marketplace\Message\FinalizeMarketplaceRawProcessingMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Финализирует daily processing run после завершения всех обязательных шагов.
 *
 * Worker-safe: нет Request, Session, Security.
 * companyId, processingRunId переданы через Message — не из сессии.
 *
 * Обработка ошибок:
 *   - DomainException (run не найден, уже terminal) →
 *     логируется, сообщение ackнуто без retry.
 *   - Прочие исключения (инфраструктура: DB down) →
 *     пробрасываются, Messenger делает retry по retry_strategy.
 */
#[AsMessageHandler]
final class FinalizeMarketplaceRawProcessingHandler
{
    public function __construct(
        private readonly FinalizeMarketplaceRawProcessingAction $action,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(FinalizeMarketplaceRawProcessingMessage $message): void
    {
        try {
            ($this->action)(new FinalizeMarketplaceRawProcessingCommand(
                $message->companyId,
                $message->processingRunId,
            ));
        } catch (\DomainException $e) {
            // Бизнес-ошибка (run не найден, уже terminal) — нет смысла делать retry.
            $this->logger->error('[FinalizeHandler] Finalization failed, no retry', [
                'processing_run_id' => $message->processingRunId,
                'company_id'        => $message->companyId,
                'error'             => $e->getMessage(),
            ]);
            // Не перебрасываем — Messenger считает сообщение обработанным.
        }
        // Прочие исключения (Throwable) пробрасываются → retry по messenger.yaml retry_strategy.
    }
}
