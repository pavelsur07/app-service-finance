<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum IngestSource: string
{
    case OZON = 'ozon';
    case WILDBERRIES = 'wildberries';
    case OZON_PERFORMANCE = 'ozon_performance';
}
