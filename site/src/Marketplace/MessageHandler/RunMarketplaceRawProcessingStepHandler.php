<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\RunMarketplaceRawProcessingStepCommand;
use App\Marketplace\Application\RunMarketplaceRawProcessingStepAction;
use App\Marketplace\Message\FinalizeMarketplaceRawProcessingMessage;
use App\Marketplace\Message\RunMarketplaceRawProcessingStepMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Запускает выполнение одного шага daily processing run.
 * После терминального завершения шага (COMPLETED или FAILED без retry)
 * диспатчит FinalizeMarketplaceRawProcessingMessage для проверки финализации run.
 *
 * Worker-safe: нет Request, Session, Security.
 *
 * Обработка ошибок:
 *   - DomainException (бизнес-ошибка: шаг FAILED, no retry) →
 *     логируется, dispatch Finalize, сообщение ackнуто.
 *   - Прочие исключения (инфраструктура: DB down) →
 *     пробрасываются (Finalize НЕ диспатчится — шаг ещё RUNNING, будет retry).
 */
#[AsMessageHandler]
final class RunMarketplaceRawProcessingStepHandler
{
    public function __construct(
        private readonly RunMarketplaceRawProcessingStepAction $action,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RunMarketplaceRawProcessingStepMessage $message): void
    {
        $shouldFinalize = false;

        try {
            ($this->action)(new RunMarketplaceRawProcessingStepCommand(
                $message->companyId,
                $message->processingRunId,
                $message->stepRunId,
            ));
            $shouldFinalize = true; // шаг COMPLETED — инициировать финализацию
        } catch (\DomainException $e) {
            // Бизнес-ошибка: шаг уже помечен FAILED в Action.
            // Нет смысла делать retry — инициируем финализацию run.
            $this->logger->error('[RunStepHandler] Step execution failed, no retry', [
                'step_run_id'       => $message->stepRunId,
                'processing_run_id' => $message->processingRunId,
                'company_id'        => $message->companyId,
                'error'             => $e->getMessage(),
            ]);
            $shouldFinalize = true; // шаг FAILED терминально — инициировать финализацию
            // Не перебрасываем — Messenger считает сообщение обработанным.
        }
        // Прочие исключения (Throwable) пробрасываются → retry по messenger.yaml.
        // Finalize НЕ диспатчится: шаг остаётся в RUNNING, повторная попытка ещё впереди.

        if ($shouldFinalize) {
            $this->bus->dispatch(new FinalizeMarketplaceRawProcessingMessage(
                $message->companyId,
                $message->processingRunId,
            ));
        }
    }
}
