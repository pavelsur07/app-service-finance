<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Service;

use App\MoySklad\Application\Action\RequestStoreAccountSuggestionsAction;
use App\MoySklad\Infrastructure\Query\StoreAccountSuggestionStore;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;

/**
 * Ответ `moysklad_store_account_suggest` → строка предложения. accepted — уверенное (входит в «Принять все»),
 * needs_review — показывается «проверьте». rejected — предложения нет окончательно; failed (сбой моделей,
 * неизвестная задача при раннем деплое сайта) — склад спросят снова. Назначение не пишется никогда.
 * Код счёта сверяется с компанией при показе и принятии. Идемпотентен: повтор того же task_id пишет то же.
 */
final readonly class StoreAccountSuggestionConsumer implements AiTaskResultConsumerInterface
{
    public function __construct(private StoreAccountSuggestionStore $store)
    {
    }

    public function supports(string $taskType): bool
    {
        return RequestStoreAccountSuggestionsAction::TASK_TYPE === $taskType;
    }

    public function consume(AiTaskResult $result): void
    {
        if (AiResultStatus::FAILED === $result->status) {
            $this->store->fail($result->companyId, $result->taskId, $result->completedAt);

            return;
        }
        $output = $result->output;
        $usable = null !== $output && \in_array($result->status, [AiResultStatus::ACCEPTED, AiResultStatus::NEEDS_REVIEW], true);
        $code = $usable && \is_string($output['account_code'] ?? null) ? $output['account_code'] : null;
        $excluded = $usable && null === $code && true === ($output['excluded'] ?? null);
        $reason = $usable && \is_string($output['reason'] ?? null) ? mb_substr($output['reason'], 0, 500) : null;

        $this->store->complete($result->companyId, $result->taskId, $code, $excluded, AiResultStatus::ACCEPTED === $result->status && (null !== $code || $excluded), $reason, $result->completedAt);
    }
}
