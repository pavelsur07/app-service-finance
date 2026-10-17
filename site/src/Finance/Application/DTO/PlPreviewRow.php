<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;

/**
 * Строка дерева ОПиУ в предпросмотре: значение по каждой колонке (ключ — PlPreviewColumn::$key).
 */
final readonly class PlPreviewRow
{
    /**
     * @param array<string, float> $values
     */
    public function __construct(
        public string $id,
        public ?string $code,
        public string $name,
        public int $level,
        public PLCategoryType $type,
        public PLValueFormat $format,
        public array $values,
    ) {
    }
}
