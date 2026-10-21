<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

/**
 * Формула итоговой статьи, которую дополнение дерева заменяет: статьи маркетплейса входят в итог.
 */
final readonly class PLFormulaChange
{
    public function __construct(
        public string $code,
        public string $name,
        public string $from,
        public string $to,
    ) {
    }
}
