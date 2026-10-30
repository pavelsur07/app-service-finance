<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Метки точности ИИ-подсказок компании (ТЗ §6): явные решения и выведенные из правок.
 */
final readonly class CashCategorizationStats
{
    /**
     * @param array<string, int> $byStatus статус подсказки → число
     */
    public function __construct(
        public array $byStatus,
        /** Подсказка висела, а человек проставил другую статью правкой операции. */
        public int $corrected,
        /** Подсказка висела, а человек проставил ту же статью правкой операции. */
        public int $confirmedByEdit,
    ) {
    }

    public function count(string $status): int
    {
        return $this->byStatus[$status] ?? 0;
    }

    /** Доля верных среди решённых людьми; null — решений ещё нет. */
    public function precision(): ?float
    {
        $right = $this->count('accepted') + $this->confirmedByEdit;
        $decided = $right + $this->count('dismissed') + $this->corrected;

        return 0 === $decided ? null : $right / $decided;
    }
}
