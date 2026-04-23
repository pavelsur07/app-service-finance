<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\Service;

/**
 * Определяет расширение файла-отчёта Ozon Performance (csv / zip) по содержимому.
 *
 * Magic bytes — источник истины: Ozon / CDN / прокси могут отдать ZIP с
 * Content-Type `text/csv` (наблюдалось в проде на перезапакованных ответах).
 * Поэтому сначала проверяем `PK\x03\x04` (ZIP local file header), а Content-Type
 * используем только как fallback, если тело короче 4 байт или не начинается
 * с известного magic'а.
 *
 * Используется из двух точек:
 *  - {@see \App\MarketplaceAds\MessageHandler\DownloadOzonAdReportHandler}
 *    (старый Messenger-pipeline);
 *  - {@see \App\MarketplaceAds\Command\AdBatchPollerCommand}
 *    (новый cron-driven pipeline, Task-11.6).
 */
final class OzonReportExtensionDetector
{
    public static function detect(string $body, ?string $contentType): string
    {
        // ZIP local file header — 4 байта "PK\x03\x04". Истина по контенту.
        if (\strlen($body) >= 4 && "PK\x03\x04" === substr($body, 0, 4)) {
            return 'zip';
        }

        // Content-Type: fallback. Парсим только первый сегмент до ";", lower-case.
        $ct = strtolower(trim(explode(';', $contentType ?? '')[0] ?? ''));
        if (\in_array($ct, ['application/zip', 'application/x-zip-compressed'], true)) {
            return 'zip';
        }

        return 'csv';
    }
}
