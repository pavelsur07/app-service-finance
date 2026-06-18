<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Shared\Domain\ValueObject\Money;

final readonly class OzonTransactionComponentMapper
{
    public function __construct(
        private OzonMoneyParser $moneyParser,
        private OzonOperationKey $operationKey,
    ) {
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedTransaction>
     */
    public function map(IngestRawRecord $rawRecord, iterable $rows, bool $realization): array
    {
        $transactions = [];

        foreach ($rows as $row) {
            $operationGroupId = $this->operationKey->operationGroupId($rawRecord->getCompanyId(), $row);
            $currency = $this->currency($row);
            $occurredAt = $this->occurredAt($row, $realization);
            $externalUpdatedAt = $this->externalUpdatedAt($row, $realization, $occurredAt);
            $orderRef = $this->orderRef($row);
            $description = $this->description($row);

            foreach ($this->components($row) as $component) {
                $transactions[] = new MappedTransaction(
                    externalId: $this->operationKey->transactionExternalId($row, $component['externalIdComponent']),
                    externalUpdatedAt: $externalUpdatedAt,
                    operationGroupId: $operationGroupId,
                    type: $component['type'],
                    direction: $component['direction'],
                    money: Money::fromMinor(abs($component['amountMinor']), $currency),
                    occurredAt: $occurredAt,
                    sourceTz: 'Europe/Moscow',
                    orderRef: $orderRef,
                    payoutRef: $this->payoutRef($row),
                    description: $component['description'] ?? $description,
                    sourceData: $row + [
                        '_ingestion_component' => $component['externalIdComponent'],
                        '_ingestion_resource' => $realization ? OzonResourceType::REALIZATION : OzonResourceType::DAILY_REPORT,
                    ],
                );
            }
        }

        return $transactions;
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedControlSum>
     */
    public function controlSumForRawRecord(IngestRawRecord $rawRecord, iterable $rows): array
    {
        $controlSums = [];

        foreach ($rows as $row) {
            $amountMinor = 0;
            foreach ($this->components($row) as $component) {
                $amountMinor += abs($component['amountMinor']);
            }

            if (0 === $amountMinor) {
                continue;
            }

            $controlSums[] = new MappedControlSum(
                operationGroupId: $this->operationKey->operationGroupId($rawRecord->getCompanyId(), $row),
                currency: $this->currency($row),
                amountMinor: $amountMinor,
            );
        }

        return $controlSums;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{externalIdComponent: string, type: TransactionType, direction: TransactionDirection, amountMinor: int, description?: string}>
     */
    private function components(array $row): array
    {
        $components = [];
        $operationType = (string) ($row['operation_type'] ?? '');
        $isRefund = 'ClientReturnAgentOperation' === $operationType;

        if ($isRefund) {
            $refundAmount = $this->firstMinor($row, ['amount', 'accruals_for_sale', 'seller_price', 'price']);
            if (0 !== $refundAmount) {
                $components[] = $this->component('refund', TransactionType::REFUND, TransactionDirection::OUT, $refundAmount, $this->description($row));
            }
        } else {
            $saleAmount = $this->firstMinor($row, ['accruals_for_sale', 'seller_price', 'price']);
            if (0 !== $saleAmount) {
                $components[] = $this->component('sale', TransactionType::SALE, $this->directionFromSigned($saleAmount), $saleAmount, $this->description($row));
            }
        }

        $saleCommission = $this->firstMinor($row, ['sale_commission_amount', 'sale_commission', 'commission_amount']);
        if (0 !== $saleCommission) {
            $components[] = $this->component('commission', TransactionType::COMMISSION, $this->directionFromSigned($saleCommission), $saleCommission, 'Ozon sale commission');
        }

        $delivery = $this->firstMinor($row, ['deliv_charge_amount', 'delivery_charge', 'delivery_commission']);
        if (0 !== $delivery) {
            $components[] = $this->component('logistics_delivery', TransactionType::LOGISTICS, $this->directionFromSigned($delivery), $delivery, 'Ozon delivery');
        }

        $returnDelivery = $this->firstMinor($row, ['return_delivery_charge_amount', 'return_delivery_charge', 'return_delivery_commission']);
        if (0 !== $returnDelivery) {
            $components[] = $this->component('logistics_return_delivery', TransactionType::LOGISTICS, $this->directionFromSigned($returnDelivery), $returnDelivery, 'Ozon return delivery');
        }

        foreach ($this->serviceAmounts($row) as $serviceKey => $amountMinor) {
            if (0 === $amountMinor) {
                continue;
            }

            $serviceType = $this->serviceType($serviceKey);
            $components[] = $this->component(
                sprintf('service_%s', $serviceKey),
                $serviceType,
                $this->directionFromSigned($amountMinor),
                $amountMinor,
                sprintf('Ozon service: %s', $serviceKey),
            );
        }

        $acquiring = $this->firstMinor($row, ['acquiring', 'acquiring_amount']);
        if (0 !== $acquiring) {
            $components[] = $this->component('acquiring', TransactionType::ACQUIRING, $this->directionFromSigned($acquiring), $acquiring, 'Ozon acquiring');
        }

        if ([] === $components) {
            $amount = $this->firstMinor($row, ['amount']);
            if (0 !== $amount) {
                $components[] = $this->component('other', TransactionType::OTHER, $this->directionFromSigned($amount), $amount, $this->description($row));
            }
        }

        return $components;
    }

    /**
     * @return array{externalIdComponent: string, type: TransactionType, direction: TransactionDirection, amountMinor: int, description?: string}
     */
    private function component(
        string $externalIdComponent,
        TransactionType $type,
        TransactionDirection $direction,
        int $amountMinor,
        ?string $description = null,
    ): array {
        return [
            'externalIdComponent' => $externalIdComponent,
            'type' => $type,
            'direction' => $direction,
            'amountMinor' => $amountMinor,
            'description' => $description,
        ];
    }

    private function firstMinor(array $row, array $fields): int
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && null !== $row[$field] && '' !== $row[$field]) {
                return $this->moneyParser->minor($row[$field]);
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, int>
     */
    private function serviceAmounts(array $row): array
    {
        $amounts = [];
        $serviceAmounts = $row['services_amounts'] ?? [];

        if (is_array($serviceAmounts)) {
            foreach ($serviceAmounts as $serviceName => $amount) {
                $key = $this->normalizeComponent((string) $serviceName);
                $amounts[$key] = ($amounts[$key] ?? 0) + $this->moneyParser->minor($amount);
            }
        }

        $services = $row['services'] ?? [];
        if (is_array($services)) {
            foreach ($services as $index => $service) {
                if (!is_array($service)) {
                    continue;
                }

                $name = (string) ($service['name'] ?? $service['service_name'] ?? $service['type'] ?? 'service_'.$index);
                $amount = $service['price'] ?? $service['amount'] ?? $service['cost'] ?? null;
                if (null === $amount || '' === $amount) {
                    continue;
                }

                $key = sprintf('%s_%d', $this->normalizeComponent($name), (int) $index);
                $amounts[$key] = ($amounts[$key] ?? 0) + $this->moneyParser->minor($amount);
            }
        }

        return $amounts;
    }

    private function serviceType(string $serviceKey): TransactionType
    {
        return match (true) {
            str_contains($serviceKey, 'marketplaceserviceitemreturnafterdelivtocustomer') => TransactionType::LOGISTICS,
            str_contains($serviceKey, 'marketplaceserviceitemdelivtocustomer') => TransactionType::LAST_MILE,
            default => TransactionType::FEE,
        };
    }

    private function directionFromSigned(int $amountMinor): TransactionDirection
    {
        return $amountMinor >= 0 ? TransactionDirection::IN : TransactionDirection::OUT;
    }

    private function currency(array $row): string
    {
        $currency = strtoupper((string) ($row['currency'] ?? $row['currency_code'] ?? $row['_header']['currency_code'] ?? 'RUB'));

        return 1 === preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'RUB';
    }

    private function occurredAt(array $row, bool $realization): \DateTimeImmutable
    {
        $fields = $realization
            ? ['operation_date', 'sale_date', 'return_date', 'report_date', 'realization_report_period_end']
            : ['operation_date', 'sale_date', 'return_date'];

        return $this->firstDate($row, $fields)
            ?? $this->firstDate($row, ['_header.stop_date', '_header.doc_date'])
            ?? new \DateTimeImmutable('@0');
    }

    private function externalUpdatedAt(array $row, bool $realization, \DateTimeImmutable $occurredAt): \DateTimeImmutable
    {
        if (!$realization) {
            return $this->firstDate($row, ['operation_date', 'sale_date', 'return_date']) ?? $occurredAt;
        }

        return $this->firstDate($row, [
            'realization_report_period_end',
            'report_date',
            '_header.stop_date',
            '_header.doc_date',
            'operation_date',
            'sale_date',
            'return_date',
        ]) ?? $occurredAt;
    }

    private function firstDate(array $row, array $fields): ?\DateTimeImmutable
    {
        foreach ($fields as $field) {
            $value = $this->fieldValue($row, $field);
            if (null === $value || '' === $value) {
                continue;
            }

            try {
                return new \DateTimeImmutable((string) $value);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function fieldValue(array $row, string $field): mixed
    {
        if (!str_contains($field, '.')) {
            return $row[$field] ?? null;
        }

        $value = $row;
        foreach (explode('.', $field) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }

    private function orderRef(array $row): ?string
    {
        $postingNumber = trim((string) ($row['posting']['posting_number'] ?? $row['posting_number'] ?? ''));

        return '' !== $postingNumber ? $postingNumber : null;
    }

    private function payoutRef(array $row): ?string
    {
        $payoutRef = trim((string) ($row['payout_ref'] ?? $row['realization_id'] ?? $row['_header']['doc_number'] ?? ''));

        return '' !== $payoutRef ? $payoutRef : null;
    }

    private function description(array $row): ?string
    {
        $description = trim((string) ($row['operation_type_name'] ?? $row['operation_type'] ?? $row['row_type'] ?? ''));

        return '' !== $description ? $description : null;
    }

    private function normalizeComponent(string $component): string
    {
        $normalized = strtolower(trim($component));
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? 'service';
        $normalized = trim($normalized, '_');

        return '' !== $normalized ? $normalized : 'service';
    }
}
