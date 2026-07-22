<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\FinancialTransactionRepository;

final readonly class WbFinanceStaleComponentVoider
{
    public function __construct(private FinancialTransactionRepository $transactionRepository)
    {
    }

    /**
     * @param list<MappedTransaction> $mappedTransactions
     *
     * @return list<\DateTimeImmutable>
     */
    public function void(IngestRawRecord $rawRecord, array $mappedTransactions): array
    {
        if (IngestSource::WILDBERRIES !== $rawRecord->getSource()
            || WbResourceType::FINANCE_SALES_REPORT_DETAILED !== $rawRecord->getResourceType()) {
            return [];
        }

        $expected = [];
        foreach ($mappedTransactions as $transaction) {
            $expected[$this->key($transaction->externalId, $transaction->type->value)] = true;
        }

        $affectedDates = [];
        foreach ($this->transactionRepository->findByRawRecordId($rawRecord->getCompanyId(), $rawRecord->getId()) as $transaction) {
            if (isset($expected[$this->key($transaction->getExternalId(), $transaction->getType()->value)])) {
                continue;
            }

            if ($transaction->voidForReplay('wildberries_mapper_component_removed')) {
                $affectedDates[$transaction->getOccurredAt()->format('Y-m-d')] = $transaction->getOccurredAt();
            }
        }

        return array_values($affectedDates);
    }

    private function key(string $externalId, string $type): string
    {
        return $externalId."\0".$type;
    }
}
