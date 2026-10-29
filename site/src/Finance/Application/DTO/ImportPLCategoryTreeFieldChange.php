<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

/**
 * Одно поле совпавшей статьи, которое импорт перезапишет: значения уже в виде для экрана.
 * `affectsReport` — поле меняет цифры отчёта ОПиУ (место в дереве, тип, знак, формула), а не
 * только его вид; такие изменения экран предпросмотра выделяет.
 */
final readonly class ImportPLCategoryTreeFieldChange
{
    public function __construct(
        public string $field,
        public string $label,
        public string $from,
        public string $to,
        public bool $affectsReport,
    ) {
    }
}
