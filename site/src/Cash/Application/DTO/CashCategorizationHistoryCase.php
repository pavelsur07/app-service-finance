<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Кейс золотого набора: операция и статья, которую ей вручную проставил человек.
 */
final readonly class CashCategorizationHistoryCase
{
    public function __construct(
        public CashCategorizationOperation $operation,
        public string $categoryId,
    ) {
    }
}
