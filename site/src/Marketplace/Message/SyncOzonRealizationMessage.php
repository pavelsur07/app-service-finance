<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Загрузка отчёта о реализации Ozon за конкретный месяц.
 * Источник: POST /v2/finance/realization
 * documentType: 'realization'
 */
final readonly class SyncOzonRealizationMessage
{
    public function __construct(
        public string $companyId,
        public string $connectionId,
        public int $year,
        public int $month,
    ) {
    }
}
