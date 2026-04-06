<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;

/**
 * Команда запуска пакетной обработки всех RawDocument за указанный месяц
 * по конкретному маркетплейсу компании.
 */
final readonly class BulkProcessMonthCommand
{
    public function __construct(
        public string          $companyId,
        public MarketplaceType $marketplace,
        public int             $year,
        public int             $month,
    ) {
    }
}
