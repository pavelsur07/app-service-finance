<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class CoverageCellView
{
    public function __construct(
        public string $date,
        public string $shopRef,
        public string $resourceType,
        public int $rawCount,
        public int $txCount,
        public int $issueCount,
        public ?string $lastFetchedAt,
    ) {
    }

    /**
     * @return array{date: string, shop_ref: string, resource_type: string, raw_count: int, tx_count: int, issue_count: int, last_fetched_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'shop_ref' => $this->shopRef,
            'resource_type' => $this->resourceType,
            'raw_count' => $this->rawCount,
            'tx_count' => $this->txCount,
            'issue_count' => $this->issueCount,
            'last_fetched_at' => $this->lastFetchedAt,
        ];
    }
}
