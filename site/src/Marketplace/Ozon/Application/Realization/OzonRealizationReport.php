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

    /** @param array<string,mixed> $rawData */
    public static function hasMatchingHeader(array $rawData, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        $header = $rawData['result']['header'] ?? null;
        if (!is_array($header)) {
            return false;
        }
        foreach (['start_date' => $from, 'stop_date' => $to] as $field => $expected) {
            $value = $header[$field] ?? null;
            if (!is_string($value) || 1 !== preg_match('~^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$~Di', $value)) {
                return false;
            }
            try {
                $date = new \DateTimeImmutable($value);
            } catch (\Exception) {
                return false;
            }
            $errors = \DateTimeImmutable::getLastErrors();
            if ((false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $date->format('Y-m-d') !== $expected->format('Y-m-d')) {
                return false;
            }
        }

        return true;
    }
}
