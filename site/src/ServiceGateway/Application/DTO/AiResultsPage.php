<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class AiResultsPage
{
    /**
     * @param list<AiTaskResult> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
