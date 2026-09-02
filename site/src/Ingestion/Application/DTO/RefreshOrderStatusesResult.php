<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Итог одного прогона перепроса статусов.
 *
 * Счётчики разделены намеренно: «опрошено» и «изменилось» отвечают на разные
 * вопросы, и один общий счётчик скрыл бы случай, когда опрос идёт, а статусы
 * почему-то никогда не меняются.
 */
final readonly class RefreshOrderStatusesResult
{
    public function __construct(
        public int $polled = 0,
        public int $changed = 0,
        public int $missing = 0,
        public int $stopped = 0,
        public int $failedConnections = 0,
    ) {
    }

    public function with(
        int $polled = 0,
        int $changed = 0,
        int $missing = 0,
        int $stopped = 0,
        int $failedConnections = 0,
    ): self {
        return new self(
            $this->polled + $polled,
            $this->changed + $changed,
            $this->missing + $missing,
            $this->stopped + $stopped,
            $this->failedConnections + $failedConnections,
        );
    }
}
