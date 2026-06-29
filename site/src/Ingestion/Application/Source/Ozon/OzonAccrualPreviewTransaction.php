<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;

final readonly class OzonAccrualPreviewTransaction
{
    public function __construct(
        public string $sourceKey,
        public string $operationGroupId,
        public string $date,
        public string $category,
        public string $component,
        public TransactionType $type,
        public TransactionDirection $direction,
        public int $amountMinor,
        public string $currency = 'RUB',
        public ?string $typeId = null,
        public ?string $externalCode = null,
        public ?string $providerLabel = null,
        public ?string $field = null,
        public ?string $unitNumber = null,
        public ?string $ozonCategoryCode = null,
        public ?string $ozonCategoryLabel = null,
        public ?string $ozonCategoryGroup = null,
        public ?string $ozonCategoryParent = null,
        public ?int $ozonCategorySortOrder = null,
        public bool $ozonCategoryKnown = true,
        public ?string $marketplaceSku = null,
        public ?string $supplierSku = null,
        public ?string $listingName = null,
    ) {
    }

    public function signedAmountMinor(): int
    {
        return TransactionDirection::OUT === $this->direction ? -$this->amountMinor : $this->amountMinor;
    }
}
