<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\DTO\MappedPreviewIssue;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Domain\Contract\PreviewIssueAwareMapperInterface;
use App\Ingestion\Domain\Contract\RawRecordAwareControlSumMapperInterface;
use App\Ingestion\Domain\Contract\SourceMapperInterface;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Shared\Domain\ValueObject\Money;

final readonly class WbFinanceSalesReportDetailedMapper implements SourceMapperInterface, RawRecordAwareControlSumMapperInterface, PreviewIssueAwareMapperInterface
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

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedPreviewIssue>
     */
    public function previewIssues(IngestRawRecord $rawRecord, iterable $rows): array
    {
        $preview = $this->previewMapper->preview($rawRecord->getCompanyId(), $rows);

        $issues = [];
        foreach ($preview->rowChecks as $check) {
            if (0 === $check->deltaMinor) {
                continue;
            }

            $issues[] = new MappedPreviewIssue(
                operationGroupId: $check->operationGroupId,
                kind: NormalizationIssueKind::SUM_MISMATCH,
                details: [
                    'message' => sprintf(
                        'WB finance payout check mismatch for row "%s": expected %d, actual %d.',
                        $check->rowKey,
                        $check->expectedNetMinor,
                        $check->actualNetMinor,
                    ),
                    'check' => 'wb_payout_check',
                    'rowKey' => $check->rowKey,
                    'expectedAmountMinor' => $check->expectedNetMinor,
                    'actualAmountMinor' => $check->actualNetMinor,
                    'deltaMinor' => $check->deltaMinor,
                    'currency' => $check->currency,
                    'operationGroupId' => $check->operationGroupId,
                ],
            );
        }

        foreach ($preview->unknownRows as $unknownRow) {
            if ([] === $unknownRow->nonZeroFields) {
                continue;
            }

            $issues[] = new MappedPreviewIssue(
                operationGroupId: null,
                kind: NormalizationIssueKind::UNKNOWN_FIELD,
                details: [
                    'message' => sprintf(
                        'WB finance row "%s" has unmapped non-zero fields: %s.',
                        $unknownRow->rowKey,
                        implode(', ', $unknownRow->nonZeroFields),
                    ),
                    'check' => 'wb_unknown_row',
                    'rowKey' => $unknownRow->rowKey,
                    'sellerOperName' => $unknownRow->sellerOperName,
                    'docTypeName' => $unknownRow->docTypeName,
                    'nonZeroFields' => $unknownRow->nonZeroFields,
                ],
            );
        }

        foreach ($preview->validationIssues as $validationIssue) {
            $issues[] = new MappedPreviewIssue(
                operationGroupId: $validationIssue->operationGroupId,
                kind: NormalizationIssueKind::UNKNOWN_FIELD,
                details: [
                    'message' => sprintf(
                        'WB finance row "%s" has invalid canonical input "%s": %s.',
                        $validationIssue->rowKey,
                        $validationIssue->field,
                        $validationIssue->reason,
                    ),
                    'check' => 'wb_invalid_canonical_input',
                    'rowKey' => $validationIssue->rowKey,
                    'field' => $validationIssue->field,
                    'reason' => $validationIssue->reason,
                    'operationGroupId' => $validationIssue->operationGroupId,
                ],
            );
        }

        return $issues;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value || '0' === $value ? null : $value;
    }
}
