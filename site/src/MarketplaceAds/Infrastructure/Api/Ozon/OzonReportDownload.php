<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

/**
 * Сырой ответ /api/client/statistics/report — контейнер для bronze-слоя.
 *
 * Ozon Performance API отдаёт отчёт либо как обычный CSV, либо (чаще для длинных
 * диапазонов и мульти-файловых отчётов) как ZIP-архив с одним/несколькими CSV
 * внутри. Bronze-слой должен хранить bytes «как есть» — поэтому $rawBytes
 * всегда содержит оригинальный ответ Ozon без модификаций, а $csvContent —
 * уже распакованный и готовый к парсингу CSV (для plain-CSV они идентичны).
 */
final readonly class OzonReportDownload
{
    public function __construct(
        public string $rawBytes,
        public string $csvContent,
        public bool $wasZip,
        public int $sizeBytes,
        public string $sha256,
        public string $reportUuid,
        public int $filesInZip,
    ) {
    }
}
