<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Enum\IngestSource;
use Webmozart\Assert\Assert;

final readonly class UpsertFinancialTransactionCommand
{
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public string $shopRef,
        public IngestSource $source,
        public MappedTransaction $mapped,
        public string $rawRecordId,
        public ?string $counterpartyId,
    ) {
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->connectionRef);
        Assert::uuid($this->rawRecordId);

        if (null !== $this->counterpartyId) {
            Assert::uuid($this->counterpartyId);
        }
    }
}
