<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Решения людей по уверенным подсказкам компании — основание открыть автоприменение (этап 2.3).
 */
final readonly class CashAiAutoApplyGateStats
{
    public function __construct(
        /** Принято или та же статья правкой операции. */
        public int $right,
        /** Отклонено, другая статья правкой, исправленное человеком автоприменение. */
        public int $wrong,
        public int $autoApplied,
        /** Автоприменённая статья с тех пор изменена. */
        public int $autoCorrected,
    ) {
    }

    public function decisions(): int
    {
        return $this->right + $this->wrong;
    }

    public function precision(): ?float
    {
        return 0 === $this->decisions() ? null : $this->right / $this->decisions();
    }
}
