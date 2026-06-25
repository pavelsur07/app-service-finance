<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Domain\Contract\RawRecordAwareControlSumMapperInterface;
use App\Ingestion\Domain\Contract\SourceMapperInterface;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Shared\Domain\ValueObject\Money;

final readonly class OzonAccrualByDayMapper implements SourceMapperInterface, RawRecordAwareControlSumMapperInterface
{
    private const SOURCE_TZ = 'Europe/Moscow';

    public function __construct(private OzonAccrualByDayPreviewMapper $previewMapper)
    {
    }

    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return [OzonResourceType::ACCRUAL_BY_DAY];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedTransaction>
     */
    public function map(IngestRawRecord $rawRecord, iterable $rows): array
    {
        $transactions = [];

        foreach ($this->previewMapper->preview($rawRecord->getCompanyId(), $rows, includeSaleRefund: true, recordUnknownCategories: true) as $row) {
            $transactions[] = new MappedTransaction(
                externalId: $row->sourceKey,
                externalUpdatedAt: $rawRecord->getFetchedAt(),
                operationGroupId: $row->operationGroupId,
                type: $row->type,
                direction: $row->direction,
                money: Money::fromMinor($row->amountMinor, $row->currency),
                occurredAt: $this->occurredAt($row->date),
                sourceTz: self::SOURCE_TZ,
                description: $this->description($row),
                sourceData: [
                    '_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY,
                    '_ingestion_component' => $row->component,
                    '_ingestion_category' => $row->category,
                    '_ingestion_type_id' => $row->typeId,
                    '_ingestion_field' => $row->field,
                    '_ingestion_unit_number' => $row->unitNumber,
                    '_ingestion_source_key' => $row->sourceKey,
                    '_ozon_category_code' => $row->ozonCategoryCode,
                    '_ozon_category_label' => $row->ozonCategoryLabel,
                    '_ozon_category_group' => $row->ozonCategoryGroup,
                    '_ozon_category_parent' => $row->ozonCategoryParent,
                    '_ozon_category_sort_order' => $row->ozonCategorySortOrder,
                    '_ozon_category_known' => $row->ozonCategoryKnown,
                    'date' => $row->date,
                    'accrued_category' => $row->category,
                    'component' => $row->component,
                    'type_id' => $row->typeId,
                    'field' => $row->field,
                    'unit_number' => $row->unitNumber,
                ],
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
        $amountsByGroup = [];
        foreach ($this->previewMapper->preview($rawRecord->getCompanyId(), $rows, includeSaleRefund: true) as $row) {
            $amountsByGroup[$row->operationGroupId] ??= ['currency' => $row->currency, 'amountMinor' => 0];
            $amountsByGroup[$row->operationGroupId]['amountMinor'] += $row->amountMinor;
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

    private function occurredAt(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%s 00:00:00', $date), new \DateTimeZone(self::SOURCE_TZ));
    }

    private function description(OzonAccrualPreviewTransaction $row): string
    {
        if (null !== $row->ozonCategoryLabel) {
            return sprintf('Ozon: %s', $row->ozonCategoryLabel);
        }

        if (null !== $row->typeId) {
            return sprintf('Ozon accrual %s %s', strtolower($row->category), $row->typeId);
        }

        return sprintf('Ozon accrual %s %s', strtolower($row->category), $row->field ?? $row->component);
    }
}
