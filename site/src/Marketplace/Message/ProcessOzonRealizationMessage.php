<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Автообработка загруженного отчёта «Реализация» Ozon: строки в `marketplace_ozon_realizations` и пересчёт сверки.
 * Ставится после загрузки отчёта (`SyncOzonRealizationHandler`) и опросом для «залипших» пар.
 */
final readonly class ProcessOzonRealizationMessage
{
    public function __construct(
        public string $companyId,
        public string $connectionId,
        public string $rawDocumentId,
        public int $year,
        public int $month,
    ) {
    }
}
