<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Итог ручной синхронизации подключения: сколько асинхронных задач поставлено.
 *
 * Окно (`firstDay`/`lastDay`, Y-m-d) и `clampedToSafeDay` заполняются только для
 * Ozon by-day; у WB окно режет планировщик статусов, и здесь оно `null`.
 */
final readonly class SyncConnectionResult
{
    public function __construct(
        public int $scheduledCount,
        public ?string $firstDay,
        public ?string $lastDay,
        public bool $clampedToSafeDay,
    ) {
    }
}
