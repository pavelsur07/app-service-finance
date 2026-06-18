<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Shared\Domain\ValueObject\Money;
use Webmozart\Assert\Assert;

final readonly class MappedTransaction
{
    /**
     * @param array<string, mixed> $sourceData
     */
    public function __construct(
        public string $externalId,
        public \DateTimeImmutable $externalUpdatedAt,
        public string $operationGroupId,
        public TransactionType $type,
        public TransactionDirection $direction,
        public Money $money,
        public \DateTimeImmutable $occurredAt,
        public string $sourceTz = 'UTC',
        public ?string $orderRef = null,
        public ?string $payoutRef = null,
        public ?string $counterpartyExternalKey = null,
        public ?string $counterpartyName = null,
        public ?string $description = null,
        public array $sourceData = [],
    ) {
        Assert::notEmpty($this->externalId);
        Assert::uuid($this->operationGroupId);
        Assert::notEmpty($this->sourceTz);

        if (null !== $this->counterpartyExternalKey) {
            Assert::notEmpty($this->counterpartyExternalKey);
        }

        if (null !== $this->counterpartyName) {
            Assert::notEmpty($this->counterpartyName);
        }
    }
}
