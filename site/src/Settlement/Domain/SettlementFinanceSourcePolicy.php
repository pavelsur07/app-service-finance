<?php

declare(strict_types=1);

namespace App\Settlement\Domain;

use App\Finance\Application\DTO\FinanceSettlementSource;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;

final readonly class SettlementFinanceSourcePolicy
{
    public function assertUsable(FinanceSettlementSource $source): void
    {
        if ($source->deleted || null === $source->counterpartyId || DocumentStatus::ACTIVE !== $source->status || null !== $source->source || !in_array($source->type, [DocumentType::OTHER, DocumentType::OBLIGATIONS, DocumentType::CASHFLOW_EXPENSE], true)) {
            throw new \DomainException('Документ ОПиУ не подходит для создания обязательства.');
        }
    }
}
