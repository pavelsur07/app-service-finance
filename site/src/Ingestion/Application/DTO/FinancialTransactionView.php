<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Read-only projection of a FinancialTransaction exposed across module boundaries.
 *
 * The IngestionFacade returns these instead of the Doctrine entity so consumers
 * (Finance, Marketplace, …) never receive a managed, mutable entity. Enum-backed
 * fields are exposed as their scalar `value` for stable serialization.
 */
final readonly class FinancialTransactionView
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $shopRef,
        public string $source,
        public string $externalId,
        public string $operationGroupId,
        public string $type,
        public string $direction,
        public int $amountMinor,
        public string $currency,
        public \DateTimeImmutable $occurredAt,
        public string $sourceTz,
        public ?string $orderRef,
        public ?string $payoutRef,
        public ?string $counterpartyId,
        public ?string $listingId,
        public ?string $listingSku,
        public ?string $description,
        public string $rawRecordId,
    ) {
    }
}
