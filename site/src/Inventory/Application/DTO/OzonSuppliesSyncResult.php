<?php

declare(strict_types=1);

namespace App\Inventory\Application\DTO;

/**
 * Итог синхронизации поставок кабинета: записано (`written`) или причина пропуска.
 */
final readonly class OzonSuppliesSyncResult
{
    public const WRITTEN = 'written';
    public const DISABLED = 'disabled';
    public const NO_SNAPSHOT = 'no_snapshot';
    public const STALE = 'stale';
    public const NO_STOCK_SESSION = 'no_stock_session';

    public function __construct(
        public string $outcome,
        public ?string $snapshotDate = null,
        public int $items = 0,
        public int $rows = 0,
        public string $quantity = '0',
    ) {
    }
}
