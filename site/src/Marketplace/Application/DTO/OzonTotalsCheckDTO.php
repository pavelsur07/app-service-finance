<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Итоги Ozon из контрольной сверки `OzonTransactionTotalsCheck` — без самой
 * сущности, для модулей вне Marketplace.
 */
final readonly class OzonTotalsCheckDTO
{
    /**
     * @param array<string, mixed> $ozonTotals итоги Ozon как сохранены в сверке (`total_minor` и др.)
     */
    public function __construct(
        public array $ozonTotals,
        public \DateTimeImmutable $checkedAt,
    ) {
    }
}
