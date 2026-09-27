<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Строка страницы /marketplace/cost-pl-mapping: категория затрат и её маппинг.
 * Без маппинга — plCategoryId null, includeInPl true, sortOrder 0: те же
 * значения, с которыми маппинг создаётся.
 */
final readonly class CostPLMappingRow
{
    public function __construct(
        public string $costCategoryId,
        public string $name,
        public string $code,
        public string $marketplace,
        public bool $isSystem,
        public bool $hasMapping,
        public ?string $plCategoryId,
        public bool $includeInPl,
        public int $sortOrder,
    ) {
    }
}
