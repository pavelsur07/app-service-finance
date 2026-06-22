<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

final readonly class WbFinancePreviewRowCheck
{
    public function __construct(
        public string $rowKey,
        public string $operationGroupId,
        public string $date,
        public string $currency,
        public int $expectedNetMinor,
        public int $actualNetMinor,
        public int $deltaMinor,
        public int $transactionCount,
        public string $sellerOperName,
        public string $docTypeName,
    ) {
    }
}
