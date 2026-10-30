<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

use App\ServiceGateway\Enum\AiResultStatus;

/**
 * Результат задачи ai-service (`ai-task/v1`, `task_result`). `output` — по контракту задачи,
 * null для `rejected`/`failed`.
 */
final readonly class AiTaskResult
{
    /**
     * @param array<string, mixed>|null $output
     * @param list<AiCheck> $checks
     * @param list<string> $models
     */
    public function __construct(
        public string $taskId,
        public string $taskType,
        public string $companyId,
        public AiResultStatus $status,
        public ?array $output,
        public array $checks,
        public ?float $confidence,
        public string $traceId,
        public array $models,
        public ?string $promptVersion,
        public int $inputTokens,
        public int $outputTokens,
        public ?string $errorCode,
        public \DateTimeImmutable $completedAt,
    ) {
    }
}
