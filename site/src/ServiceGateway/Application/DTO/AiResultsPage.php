<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class AiResultsPage
{
    /**
     * @param list<AiTaskResult> $items
     * @param list<string> $invalid результаты, не прошедшие разбор: `task_id` (если читается) и причина
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public array $invalid = [],
    ) {
    }
}
