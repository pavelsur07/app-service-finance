<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum NormalizationIssueKind: string
{
    case SUM_MISMATCH = 'sum_mismatch';
    case MAPPER_FAILURE = 'mapper_failure';
    case UNKNOWN_FIELD = 'unknown_field';
    case CURRENCY_MISMATCH = 'currency_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::SUM_MISMATCH => 'Сумма не сошлась',
            self::MAPPER_FAILURE => 'Ошибка маппинга',
            self::UNKNOWN_FIELD => 'Неизвестное поле',
            self::CURRENCY_MISMATCH => 'Несовпадение валют',
        };
    }
}
