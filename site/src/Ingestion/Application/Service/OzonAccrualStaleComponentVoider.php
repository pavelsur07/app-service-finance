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
     * Гасилка обязана смотреть ровно на те дни, которые разбирались.
     *
     * Реплей сохранённого сырья ограничивается днями, которыми снапшот владеет,
     * и строки за остальные его дни в `$mappedTransactions` не попадают. Без
     * такого же ограничения здесь они выглядят «исчезнувшими из выгрузки» и
     * гасятся — хотя живые и принадлежат этому же сырью. Так 18.09.2026 на проде
     * обнулились 19 дней, 26 496 строк.
     *
     * Пустой список — без ограничения: обычная нормализация разбирает снапшот
     * целиком, и всё, чего в наборе нет, действительно устарело.
     *
     * @param list<MappedTransaction> $mappedTransactions
     * @param list<string> $restrictToDates дни в формате Y-m-d
     */
    public function void(IngestRawRecord $rawRecord, array $mappedTransactions, array $restrictToDates = []): void
    {
        if (IngestSource::OZON !== $rawRecord->getSource()
            || OzonResourceType::ACCRUAL_BY_DAY !== $rawRecord->getResourceType()) {
            return;
        }

        $expected = [];
        foreach ($mappedTransactions as $transaction) {
            $expected[$this->key($transaction->externalId, $transaction->type->value)] = true;
        }

        $allowedDates = array_fill_keys($restrictToDates, true);

        foreach ($this->transactionRepository->findByRawRecordId($rawRecord->getCompanyId(), $rawRecord->getId()) as $transaction) {
            if ([] !== $allowedDates && !isset($allowedDates[$transaction->getOccurredAt()->format('Y-m-d')])) {
                continue;
            }

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
