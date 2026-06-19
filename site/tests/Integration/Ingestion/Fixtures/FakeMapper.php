<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Domain\Contract\SourceMapperInterface;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Shared\Domain\ValueObject\Money;
use Ramsey\Uuid\Uuid;

final class FakeMapper implements SourceMapperInterface
{
    public function source(): IngestSource
    {
        return IngestSource::WILDBERRIES;
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return [FakeConnector::RESOURCE_TYPE];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedTransaction>
     */
    public function map(IngestRawRecord $rawRecord, iterable $rows): array
    {
        $transactions = [];

        foreach ($rows as $row) {
            if (($row['failMapper'] ?? false) === true) {
                throw new \RuntimeException('Fake mapper failure requested by fixture row.');
            }

            $currency = strtoupper((string) ($row['currency'] ?? 'RUB'));
            $transactions[] = new MappedTransaction(
                externalId: (string) ($row['externalId'] ?? $rawRecord->getExternalId()),
                externalUpdatedAt: new \DateTimeImmutable((string) ($row['externalUpdatedAt'] ?? '2026-06-18T10:00:00+00:00')),
                operationGroupId: (string) ($row['operationGroupId'] ?? Uuid::uuid7()->toString()),
                type: TransactionType::from((string) ($row['type'] ?? TransactionType::SALE->value)),
                direction: TransactionDirection::from((string) ($row['direction'] ?? TransactionDirection::IN->value)),
                money: Money::fromMinor((int) ($row['amountMinor'] ?? 12345), $currency),
                occurredAt: new \DateTimeImmutable((string) ($row['occurredAt'] ?? '2026-06-18T09:30:00+00:00')),
                sourceTz: (string) ($row['sourceTz'] ?? 'UTC'),
                orderRef: isset($row['orderRef']) ? (string) $row['orderRef'] : null,
                payoutRef: isset($row['payoutRef']) ? (string) $row['payoutRef'] : null,
                counterpartyExternalKey: isset($row['counterpartyExternalKey']) ? (string) $row['counterpartyExternalKey'] : null,
                counterpartyName: isset($row['counterpartyName']) ? (string) $row['counterpartyName'] : null,
                description: isset($row['description']) ? (string) $row['description'] : null,
                sourceData: $row,
            );
        }

        return $transactions;
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedControlSum>
     */
    public function controlSum(iterable $rows): array
    {
        $controlSums = [];

        foreach ($rows as $row) {
            $controlSums[] = new MappedControlSum(
                operationGroupId: (string) ($row['operationGroupId'] ?? Uuid::uuid7()->toString()),
                currency: strtoupper((string) ($row['controlCurrency'] ?? $row['currency'] ?? 'RUB')),
                amountMinor: (int) ($row['controlAmountMinor'] ?? $row['amountMinor'] ?? 12345),
            );
        }

        return $controlSums;
    }
}
