<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Ingestion\Enum\IngestSource;

/**
 * Системные контрагенты маркетплейсов (таблица system_counterparties).
 * UUID фиксированы: миграция Version20260619110000 вставляет те же значения.
 */
final class SystemCounterparties
{
    public const OZON_ID = '1cbbfc7c-72ad-5505-8743-be71bdde6dc1';
    public const WILDBERRIES_ID = '95d09265-b44f-5b95-a12c-f1e3332c657d';

    /**
     * @return array<string, array{source: IngestSource, name: string}>
     */
    public static function definitions(): array
    {
        return [
            self::OZON_ID => ['source' => IngestSource::OZON, 'name' => 'Ozon'],
            self::WILDBERRIES_ID => ['source' => IngestSource::WILDBERRIES, 'name' => 'Wildberries'],
        ];
    }
}
