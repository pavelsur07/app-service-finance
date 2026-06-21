<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use Ramsey\Uuid\Uuid;

final readonly class OzonAccrualByDayPreviewMapper
{
    public function __construct(private OzonMoneyParser $moneyParser)
    {
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<OzonAccrualPreviewTransaction>
     */
    public function preview(
        string $companyId,
        iterable $rows,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): array {
        $transactions = [];
        $rowIndex = 0;

        foreach ($rows as $row) {
            ++$rowIndex;

            $date = $this->stringValue($row['date'] ?? 'unknown');
            if (!$this->dateInWindow($date, $from, $to)) {
                continue;
            }

            $category = $this->stringValue($row['accrued_category'] ?? 'unknown');
            $accrualId = $this->accrualId($row, $rowIndex);
            $operationGroupId = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, $accrualId))->toString();
            $unitNumber = $this->optionalString($row['unit_number'] ?? null);

            match ($category) {
                'POSTING' => $this->collectPosting($transactions, $row, $operationGroupId, $date, $category, $accrualId, $unitNumber),
                'ITEM' => $this->collectItemFees($transactions, $row['item_fees'] ?? null, $operationGroupId, $date, $category, $accrualId, $unitNumber),
                'NON_ITEM' => $this->collectNonItemFee($transactions, $row['non_item_fee'] ?? null, $operationGroupId, $date, $category, $accrualId, $unitNumber),
                'CONTAINER' => $this->collectContainerFees($transactions, $row['container_fees'] ?? null, $operationGroupId, $date, $category, $accrualId, $unitNumber),
                default => null,
            };
        }

        return $transactions;
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     * @param array<string, mixed> $row
     */
    private function collectPosting(
        array &$transactions,
        array $row,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        ?string $unitNumber,
    ): void {
        $posting = $row['posting'] ?? null;
        if (!is_array($posting)) {
            return;
        }

        $products = $posting['products'] ?? [];
        if (!is_array($products)) {
            return;
        }

        foreach ($products as $productIndex => $product) {
            if (!is_array($product)) {
                continue;
            }

            $this->collectCommission($transactions, $product['commission'] ?? null, $operationGroupId, $date, $category, $accrualId, (int) $productIndex, $unitNumber);
            $this->collectDeliveryServices($transactions, $product['delivery']['services'] ?? null, $operationGroupId, $date, $category, $accrualId, (int) $productIndex, $unitNumber);
        }
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectCommission(
        array &$transactions,
        mixed $commission,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        int $productIndex,
        ?string $unitNumber,
    ): void {
        if (!is_array($commission)) {
            return;
        }

        $amount = $this->moneyObjectMinor($commission['commission'] ?? $commission['sale_commission'] ?? null);
        if (0 === $amount) {
            return;
        }

        $this->add(
            transactions: $transactions,
            operationGroupId: $operationGroupId,
            date: $date,
            category: $category,
            accrualId: $accrualId,
            component: sprintf('commission:product-%d', $productIndex),
            type: TransactionType::COMMISSION,
            signedAmountMinor: $amount,
            field: array_key_exists('commission', $commission) ? 'commission' : 'sale_commission',
            unitNumber: $unitNumber,
        );
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectDeliveryServices(
        array &$transactions,
        mixed $services,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        int $productIndex,
        ?string $unitNumber,
    ): void {
        if (!is_array($services)) {
            return;
        }

        foreach ($services as $serviceIndex => $service) {
            if (!is_array($service)) {
                continue;
            }

            $typeId = $this->typeId($service);
            $amount = $this->moneyObjectMinor($service['accrued'] ?? null);
            if (0 === $amount) {
                continue;
            }

            $this->add(
                transactions: $transactions,
                operationGroupId: $operationGroupId,
                date: $date,
                category: $category,
                accrualId: $accrualId,
                component: sprintf('delivery:product-%d:service-%d:type-%s', $productIndex, (int) $serviceIndex, $typeId),
                type: TransactionType::FEE,
                signedAmountMinor: $amount,
                typeId: $typeId,
                unitNumber: $unitNumber,
            );
        }
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectItemFees(
        array &$transactions,
        mixed $itemFeesBlock,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        ?string $unitNumber,
    ): void {
        if (!is_array($itemFeesBlock)) {
            return;
        }

        $skuFeeGroups = $itemFeesBlock['fees'] ?? [];
        if (!is_array($skuFeeGroups)) {
            return;
        }

        foreach ($skuFeeGroups as $groupIndex => $skuFeeGroup) {
            if (!is_array($skuFeeGroup) || !is_array($skuFeeGroup['fees'] ?? null)) {
                continue;
            }

            foreach ($skuFeeGroup['fees'] as $feeIndex => $fee) {
                $this->collectTypedFee(
                    transactions: $transactions,
                    fee: $fee,
                    operationGroupId: $operationGroupId,
                    date: $date,
                    category: $category,
                    accrualId: $accrualId,
                    componentPrefix: sprintf('item_fee:group-%d:fee-%d', (int) $groupIndex, (int) $feeIndex),
                    type: TransactionType::FEE,
                    unitNumber: $unitNumber,
                );
            }
        }
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectNonItemFee(
        array &$transactions,
        mixed $fee,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        ?string $unitNumber,
    ): void {
        $this->collectTypedFee(
            transactions: $transactions,
            fee: $fee,
            operationGroupId: $operationGroupId,
            date: $date,
            category: $category,
            accrualId: $accrualId,
            componentPrefix: 'non_item_fee',
            type: TransactionType::OTHER,
            unitNumber: $unitNumber,
        );
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectContainerFees(
        array &$transactions,
        mixed $value,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        ?string $unitNumber,
        string $path = 'container_fee',
    ): void {
        if (!is_array($value)) {
            return;
        }

        $this->collectTypedFee(
            transactions: $transactions,
            fee: $value,
            operationGroupId: $operationGroupId,
            date: $date,
            category: $category,
            accrualId: $accrualId,
            componentPrefix: $path,
            type: TransactionType::FEE,
            unitNumber: $unitNumber,
        );

        foreach ($value as $childKey => $child) {
            if (is_array($child)) {
                $this->collectContainerFees($transactions, $child, $operationGroupId, $date, $category, $accrualId, $unitNumber, sprintf('%s:%s', $path, $this->normalizeComponent((string) $childKey)));
            }
        }
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectTypedFee(
        array &$transactions,
        mixed $fee,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        string $componentPrefix,
        TransactionType $type,
        ?string $unitNumber,
    ): void {
        if (!is_array($fee) || !array_key_exists('accrued', $fee)) {
            return;
        }

        $typeId = $this->typeId($fee);
        $amount = $this->moneyObjectMinor($fee['accrued']);
        if (0 === $amount) {
            return;
        }

        $this->add(
            transactions: $transactions,
            operationGroupId: $operationGroupId,
            date: $date,
            category: $category,
            accrualId: $accrualId,
            component: sprintf('%s:type-%s', $componentPrefix, $typeId),
            type: $type,
            signedAmountMinor: $amount,
            typeId: $typeId,
            unitNumber: $unitNumber,
        );
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function add(
        array &$transactions,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        string $component,
        TransactionType $type,
        int $signedAmountMinor,
        ?string $typeId = null,
        ?string $field = null,
        ?string $unitNumber = null,
    ): void {
        $transactions[] = new OzonAccrualPreviewTransaction(
            sourceKey: sprintf('ozon:accrual-by-day:%s:%s', $accrualId, $this->normalizeComponent($component)),
            operationGroupId: $operationGroupId,
            date: $date,
            category: $category,
            component: $component,
            type: $type,
            direction: $this->directionFromSigned($signedAmountMinor),
            amountMinor: abs($signedAmountMinor),
            typeId: $typeId,
            field: $field,
            unitNumber: $unitNumber,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function accrualId(array $row, int $rowIndex): string
    {
        $accrualId = $this->optionalString($row['accrual_id'] ?? null);
        if (null !== $accrualId) {
            return $accrualId;
        }

        $json = json_encode($row, \JSON_THROW_ON_ERROR);

        return sprintf('fallback-%d-%s', $rowIndex, substr(hash('sha256', $json), 0, 16));
    }

    /**
     * @param array<string, mixed> $fee
     */
    private function typeId(array $fee): string
    {
        return $this->stringValue($fee['type_id'] ?? $fee['typeId'] ?? 'unknown');
    }

    private function moneyObjectMinor(mixed $value): int
    {
        if (is_array($value) && array_key_exists('amount', $value)) {
            return $this->moneyParser->minor($value['amount']);
        }

        return $this->moneyParser->minor($value);
    }

    private function directionFromSigned(int $amountMinor): TransactionDirection
    {
        return $amountMinor >= 0 ? TransactionDirection::IN : TransactionDirection::OUT;
    }

    private function dateInWindow(string $date, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): bool
    {
        if (null === $from && null === $to) {
            return true;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        if (null !== $from && $date < $from->format('Y-m-d')) {
            return false;
        }

        return null === $to || $date <= $to->format('Y-m-d');
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' !== $value ? $value : null;
    }

    private function stringValue(mixed $value): string
    {
        return $this->optionalString($value) ?? 'unknown';
    }

    private function normalizeComponent(string $component): string
    {
        $normalized = strtolower(trim($component));
        $normalized = preg_replace('/[^a-z0-9_:-]+/', '_', $normalized) ?? 'component';
        $normalized = trim($normalized, '_:-');

        return '' !== $normalized ? $normalized : 'component';
    }
}
