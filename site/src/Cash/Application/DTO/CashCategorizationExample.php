<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Как компания сама разнесла похожую операцию (человек или автоправило, не ИИ) — пример во входе
 * categorize_transactions (`examples`).
 */
final readonly class CashCategorizationExample
{
    public function __construct(
        /** INFLOW | OUTFLOW */
        public string $direction,
        public ?string $description,
        public ?string $counterparty,
        public string $categoryId,
    ) {
    }
}
