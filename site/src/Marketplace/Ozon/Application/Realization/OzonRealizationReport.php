<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Realization;

use App\Marketplace\Enum\MarketplaceRawFormat;

/**
 * Идентичность отчёта «Реализация» Ozon в таблице статусов отчётов:
 * ключ — компания × маркетплейс × `REPORT_TYPE` × первый день отчётного месяца.
 */
final class OzonRealizationReport
{
    public const REPORT_TYPE = 'ozon_realization';

    public static function apiEndpoint(): string
    {
        return MarketplaceRawFormat::OZON_REALIZATION_V2->value;
    }

    public static function businessDate(int $year, int $month): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    }
}
