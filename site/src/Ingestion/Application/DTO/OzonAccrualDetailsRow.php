<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Строка отчёта кабинета Ozon «Детализация начислений» — только поля, нужные для
 * вывода группы типа начисления. Названия товаров и прочее не читаются.
 */
final readonly class OzonAccrualDetailsRow
{
    public function __construct(
        public string $date,
        public string $groupLabel,
        public string $typeName,
        public ?string $sku,
        public int $amountMinor,
    ) {
    }
}
