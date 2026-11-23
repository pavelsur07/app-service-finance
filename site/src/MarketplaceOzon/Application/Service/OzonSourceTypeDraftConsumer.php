<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Application\Service;

use App\MarketplaceOzon\Application\Action\RequestOzonSourceTypeDraftsAction;
use App\MarketplaceOzon\Infrastructure\Query\OzonTranslationStore;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;

/**
 * Ответ `marketplace_source_type_draft` → черновик в `moz_source_type.ai_draft`. Вызывается импортом
 * ServiceGateway внутри его транзакции. Код, по которому администратор уже решил, не трогается.
 */
final readonly class OzonSourceTypeDraftConsumer implements AiTaskResultConsumerInterface
{
    public function __construct(
        private OzonTranslationStore $store,
    ) {
    }

    public function supports(string $taskType): bool
    {
        return RequestOzonSourceTypeDraftsAction::TASK_TYPE === $taskType;
    }

    public function consume(AiTaskResult $result): void
    {
        $sourceType = $this->store->sourceTypeByDraftTask($result->taskId);
        if (null === $sourceType) {
            return;
        }

        $draft = ['task_id' => $result->taskId, 'status' => $result->status->value, 'completed_at' => $result->completedAt->format(\DATE_ATOM)];
        $output = $result->output;
        if (null !== $output && !\in_array($result->status, [AiResultStatus::FAILED, AiResultStatus::REJECTED], true)) {
            $draft += [
                'kind' => \is_string($output['kind'] ?? null) ? $output['kind'] : null,
                'new_kind' => \is_array($output['new_kind'] ?? null) ? $output['new_kind'] : null,
                'title' => \is_string($output['title'] ?? null) ? $output['title'] : null,
                'reason' => \is_string($output['reason'] ?? null) ? $output['reason'] : null,
                'confidence' => $result->confidence,
                'model' => [] === $result->models ? null : $result->models[array_key_last($result->models)],
            ];
        } else {
            $draft['error'] = $result->errorCode ?? $result->status->value;
        }

        $this->store->saveDraft($sourceType, $draft);
    }
}
