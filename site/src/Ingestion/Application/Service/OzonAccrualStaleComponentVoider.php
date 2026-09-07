<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\FinancialTransactionRepository;

final readonly class OzonAccrualStaleComponentVoider
{
    public function __construct(private FinancialTransactionRepository $transactionRepository)
    {
    }

    /**
     * @param list<MappedTransaction> $mappedTransactions
     */
    public function void(IngestRawRecord $rawRecord, array $mappedTransactions): void
    {
        if (IngestSource::OZON !== $rawRecord->getSource()
            || OzonResourceType::ACCRUAL_BY_DAY !== $rawRecord->getResourceType()) {
            return;
        }

        $expected = [];
        foreach ($mappedTransactions as $transaction) {
            $expected[$this->key($transaction->externalId, $transaction->type->value)] = true;
        }

        foreach ($this->transactionRepository->findByRawRecordId($rawRecord->getCompanyId(), $rawRecord->getId()) as $transaction) {
            if (isset($expected[$this->key($transaction->getExternalId(), $transaction->getType()->value)])) {
                continue;
            }

            $transaction->voidForReplay('ozon_mapper_component_retyped');
        }
    }

    private function key(string $externalId, string $type): string
    {
        return $externalId."\0".$type;
    }
}
