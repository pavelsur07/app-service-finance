<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Performance;

/**
 * Этапы диагностических замеров Marketplace M1. Значения — часть формата лога
 * (`var/log/performance-*.jsonl`) и читаются отчётом; переименование ломает старые файлы.
 *
 * Вложенность: `financial_mapping` и `financial_posting` измеряются внутри
 * `processor_total`, `source_normalize` (Ozon extract*) — тоже. Сумма этапов поэтому
 * не равна `handler`. Подробности — docs/architecture/marketplace-audit/09-m1-diagnostics.md.
 */
enum PerformanceStage: string
{
    case ApiFetch = 'api_fetch';
    case StorageRead = 'storage_read';
    case StorageWrite = 'storage_write';
    case SourceParse = 'source_parse';
    case SourceNormalize = 'source_normalize';
    case FinancialMapping = 'financial_mapping';
    case FinancialPosting = 'financial_posting';
    case ProcessorTotal = 'processor_total';
    case QueueWait = 'queue_wait';
    case Handler = 'handler';
}
