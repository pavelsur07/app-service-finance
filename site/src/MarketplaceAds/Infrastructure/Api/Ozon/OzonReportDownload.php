<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

/**
 * Сырой ответ /api/client/statistics/report — контейнер для bronze-слоя.
 *
 * Ozon Performance API отдаёт отчёт либо как обычный CSV, либо (чаще для длинных
 * диапазонов и мульти-файловых отчётов) как ZIP-архив с одним/несколькими CSV
 * внутри. Bronze-слой должен хранить bytes «как есть» — поэтому $rawBytes
 * всегда содержит оригинальный ответ Ozon без модификаций.
 *
 * $csvParts — список CSV verbatim (по одному на файл из ZIP, или один элемент
 * для plain-CSV). Используется парсером convertCsvToRows*, чтобы каждый CSV
 * обработать независимо со своим preamble'ом (в новом Ozon-формате у каждого
 * CSV в ZIP — собственная preamble-строка с campaign_id конкретной кампании,
 * и «склеивать их всех в одну строку» = терять campaign attribution).
 *
 * $csvContent — legacy-поле: concat всех CSV-частей с удалением первой строки
 * у частей 2+ (обратная совместимость с bronze-инспекцией и старыми тестами).
 * Для парсинга НЕ используется.
 *
 * @param list<string> $csvParts
 */
final readonly class OzonReportDownload
{
    public function __construct(
        public string $rawBytes,
        public string $csvContent,
        public array $csvParts,
        public bool $wasZip,
        public int $sizeBytes,
        public string $sha256,
        public string $reportUuid,
        public int $filesInZip,
    ) {
    }
}
