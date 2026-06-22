<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;

final readonly class WbFinancePreviewTransaction
{
    /**
     * @param array<string, mixed> $sourceData
     */
    public function __construct(
        public string $sourceKey,
        public string $operationGroupId,
        public string $component,
        public TransactionType $type,
        public TransactionDirection $direction,
        public int $amountMinor,
        public string $currency,
        public \DateTimeImmutable $occurredAt,
        public string $sourceTz,
        public ?string $field,
        public string $sellerOperName,
        public string $docTypeName,
        public string $description,
        public array $sourceData = [],
    ) {
    }

    public function signedAmountMinor(): int
    {
        return TransactionDirection::OUT === $this->direction
            ? -$this->amountMinor
            : $this->amountMinor;
    }
}
