<?php

declare(strict_types=1);

namespace App\ServiceGateway\Message;

use Webmozart\Assert\Assert;

/**
 * Поставить задачу в ai-service. Транспорт — `async_sync` (HTTP к сервису).
 * Все идентификаторы зафиксированы при создании: ретраи шлют тот же `Idempotency-Key`.
 */
final readonly class SubmitAiTaskMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $taskId,
        public string $taskType,
        public string $companyId,
        public string $idempotencyKey,
        public string $requestedBy,
        public string $traceId,
        public string $createdAt,
        public array $payload,
    ) {
        Assert::uuid($taskId);
        Assert::uuid($companyId);
        Assert::uuid($traceId);
        Assert::regex($taskType, '/^[a-z][a-z0-9_]{1,63}$/D');
        Assert::lengthBetween($idempotencyKey, 1, 200);
    }
}
