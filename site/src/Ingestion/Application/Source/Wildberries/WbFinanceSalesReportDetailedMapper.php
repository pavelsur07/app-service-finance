<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Domain\Contract\RawRecordAwareControlSumMapperInterface;
use App\Ingestion\Domain\Contract\SourceMapperInterface;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Shared\Domain\ValueObject\Money;

final readonly class WbFinanceSalesReportDetailedMapper implements SourceMapperInterface, RawRecordAwareControlSumMapperInterface
{
    public function __construct(private WbFinanceSalesReportDetailedPreviewMapper $previewMapper)
    {
    }

    public function source(): IngestSource
    {
        return IngestSource::WILDBERRIES;
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return [WbResourceType::FINANCE_SALES_REPORT_DETAILED];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedTransaction>
     */
    public function map(IngestRawRecord $rawRecord, iterable $rows): array
    {
        $preview = $this->previewMapper->preview($rawRecord->getCompanyId(), $rows);
        $this->assertPreviewIsWritable($preview);

        $transactions = [];
        foreach ($preview->transactions as $transaction) {
            $transactions[] = new MappedTransaction(
                externalId: $transaction->sourceKey,
                externalUpdatedAt: $rawRecord->getFetchedAt(),
                operationGroupId: $transaction->operationGroupId,
                type: $transaction->type,
                direction: $transaction->direction,
                money: Money::fromMinor($transaction->amountMinor, $transaction->currency),
                occurredAt: $transaction->occurredAt,
                sourceTz: $transaction->sourceTz,
                orderRef: $this->nonEmptyString($transaction->sourceData['srid'] ?? null),
                payoutRef: $this->nonEmptyString($transaction->sourceData['reportId'] ?? null),
                description: $transaction->description,
                sourceData: $transaction->sourceData,
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
        return [];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedControlSum>
     */
    public function controlSumForRawRecord(IngestRawRecord $rawRecord, iterable $rows): array
    {
        $preview = $this->previewMapper->preview($rawRecord->getCompanyId(), $rows);
        $this->assertPreviewIsWritable($preview);

        $amountsByGroup = [];
        foreach ($preview->transactions as $transaction) {
            $amountsByGroup[$transaction->operationGroupId] ??= [
                'currency' => $transaction->currency,
                'amountMinor' => 0,
            ];
            $amountsByGroup[$transaction->operationGroupId]['amountMinor'] += $transaction->amountMinor;
        }

        $controlSums = [];
        foreach ($amountsByGroup as $operationGroupId => $controlSum) {
            $controlSums[] = new MappedControlSum(
                operationGroupId: $operationGroupId,
                currency: $controlSum['currency'],
                amountMinor: $controlSum['amountMinor'],
            );
        }

        return $controlSums;
    }

    private function assertPreviewIsWritable(WbFinancePreviewResult $preview): void
    {
        $mismatches = array_values(array_filter(
            $preview->rowChecks,
            static fn (WbFinancePreviewRowCheck $check): bool => 0 !== $check->deltaMinor,
        ));
        if ([] !== $mismatches) {
            $first = $mismatches[0];

            throw new \RuntimeException(sprintf('WB finance payout check mismatch for row "%s": expected %d, actual %d.', $first->rowKey, $first->expectedNetMinor, $first->actualNetMinor));
        }

        $unknownRowsWithAmounts = array_values(array_filter(
            $preview->unknownRows,
            static fn (WbFinancePreviewUnknownRow $row): bool => [] !== $row->nonZeroFields,
        ));
        if ([] !== $unknownRowsWithAmounts) {
            $first = $unknownRowsWithAmounts[0];

            throw new \RuntimeException(sprintf('WB finance row "%s" has unmapped non-zero fields: %s.', $first->rowKey, implode(', ', $first->nonZeroFields)));
        }
    }

    private function nonEmptyString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value || '0' === $value ? null : $value;
    }
}
