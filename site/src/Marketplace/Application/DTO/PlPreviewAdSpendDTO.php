<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Рекламный расход маркетплейса за период из рекламной статистики: по листингам и всего.
 * Суммы — положительный расход, decimal-строки. Всего ≥ сумма по листингам: кампании без
 * разбивки по товарам учтены только во «всего».
 */
final readonly class PlPreviewAdSpendDTO
{
    /**
     * @param array<string, string> $byListing listingId → расход
     */
    public function __construct(
        public array $byListing,
        public string $total,
    ) {
    }

    public function unallocated(): string
    {
        $allocated = '0';
        foreach ($this->byListing as $amount) {
            // bcmath_compat объявляет bcadd() без типа результата.
            /** @var numeric-string $allocated */
            $allocated = bcadd($allocated, $amount, 2);
        }

        return bcsub($this->total, $allocated, 2);
    }
}
