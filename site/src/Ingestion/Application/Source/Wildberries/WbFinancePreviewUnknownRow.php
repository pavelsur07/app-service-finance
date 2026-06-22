<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

final readonly class WbFinancePreviewUnknownRow
{
    /**
     * @param list<string> $nonZeroFields
     */
    public function __construct(
        public string $rowKey,
        public string $date,
        public string $sellerOperName,
        public string $docTypeName,
        public array $nonZeroFields,
    ) {
    }
}
