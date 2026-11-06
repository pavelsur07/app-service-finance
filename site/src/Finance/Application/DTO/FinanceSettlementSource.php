<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLDocumentSource;

final readonly class FinanceSettlementSource
{
    /** @param list<string> $operationIds */
    public function __construct(public string $id, public string $companyId, public ?string $counterpartyId, public \DateTimeImmutable $date, public bool $deleted, public DocumentStatus $status, public DocumentType $type, public array $operationIds, public string $fingerprint, public ?PLDocumentSource $source, public ?string $cashTransactionId)
    {
    }
}
