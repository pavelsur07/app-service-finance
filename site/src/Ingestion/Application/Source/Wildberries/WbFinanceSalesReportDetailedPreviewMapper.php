<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use Ramsey\Uuid\Uuid;

final readonly class WbFinanceSalesReportDetailedPreviewMapper
{
    private const SOURCE_TZ = 'UTC';

    /**
     * @param iterable<array<string, mixed>> $rows
     */
    public function preview(string $companyId, iterable $rows): WbFinancePreviewResult
    {
        $transactions = [];
        $rowChecks = [];
        $unknownRows = [];
        $scannedRows = 0;
        $emptyRows = 0;

        foreach ($rows as $rowIndex => $row) {
            if (($row['_ingestion_empty'] ?? false) === true) {
                ++$emptyRows;
                continue;
            }

            ++$scannedRows;
            $rowKey = $this->rowKey($row, (int) $rowIndex);
            $operationGroupId = Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                sprintf('%s:wildberries:sales-report-detailed:%s', $companyId, $rowKey),
            )->toString();
            $currency = $this->currency($row);
            $docTypeName = $this->string($row, 'docTypeName', 'doc_type_name');
            $sellerOperName = $this->string($row, 'sellerOperName', 'supplier_oper_name');
            $occurredAt = $this->operationDate($row);
            $rowTransactionsStart = count($transactions);

            $this->collectSaleRefundComponents($transactions, $row, $operationGroupId, $rowKey, $currency, $occurredAt, $sellerOperName, $docTypeName);
            $this->collectCostComponents($transactions, $row, $operationGroupId, $rowKey, $currency, $occurredAt, $sellerOperName, $docTypeName);

            $rowTransactions = array_slice($transactions, $rowTransactionsStart);
            if ([] !== $rowTransactions && $this->isSaleOrReturn($docTypeName) && $this->hasPayoutCheckFields($row)) {
                $expectedNetMinor = $this->minor($row, 'forPay', 'ppvz_for_pay');
                if ($this->isReturn($docTypeName)) {
                    $expectedNetMinor *= -1;
                }

                $payoutTransactions = $this->payoutCheckTransactions($rowTransactions);
                $actualNetMinor = array_sum(array_map(
                    static fn (WbFinancePreviewTransaction $transaction): int => $transaction->signedAmountMinor(),
                    $payoutTransactions,
                ));

                $rowChecks[] = new WbFinancePreviewRowCheck(
                    rowKey: $rowKey,
                    operationGroupId: $operationGroupId,
                    date: $occurredAt->format('Y-m-d'),
                    currency: $currency,
                    expectedNetMinor: $expectedNetMinor,
                    actualNetMinor: $actualNetMinor,
                    deltaMinor: $actualNetMinor - $expectedNetMinor,
                    transactionCount: count($payoutTransactions),
                    sellerOperName: $sellerOperName,
                    docTypeName: $docTypeName,
                );
            }

            if ([] === $rowTransactions && ('' !== $sellerOperName || '' !== $docTypeName)) {
                $unknownRows[] = new WbFinancePreviewUnknownRow(
                    rowKey: $rowKey,
                    date: $occurredAt->format('Y-m-d'),
                    sellerOperName: $sellerOperName,
                    docTypeName: $docTypeName,
                    nonZeroFields: $this->nonZeroKnownMoneyFields($row),
                );
            }
        }

        return new WbFinancePreviewResult($transactions, $rowChecks, $unknownRows, $scannedRows, $emptyRows);
    }

    /**
     * @param list<WbFinancePreviewTransaction> $transactions
     * @param array<string, mixed>              $row
     */
    private function collectSaleRefundComponents(
        array &$transactions,
        array $row,
        string $operationGroupId,
        string $rowKey,
        string $currency,
        \DateTimeImmutable $occurredAt,
        string $sellerOperName,
        string $docTypeName,
    ): void {
        if (!$this->isSaleOrReturn($docTypeName)) {
            return;
        }

        $retailMinor = $this->minor($row, 'retailPriceWithDisc', 'retail_price_withdisc_rub');
        if (0 === $retailMinor) {
            $retailMinor = $this->minor($row, 'retailAmount', 'retail_amount');
        }

        $forPayMinor = $this->minor($row, 'forPay', 'ppvz_for_pay');
        $acquiringMinor = $this->minor($row, 'acquiringFee', 'acquiring_fee');
        $commissionMinor = $retailMinor - $forPayMinor - $acquiringMinor;
        $isReturn = $this->isReturn($docTypeName);

        if (0 !== $retailMinor) {
            $this->add(
                transactions: $transactions,
                operationGroupId: $operationGroupId,
                rowKey: $rowKey,
                component: $isReturn ? 'refund' : 'sale',
                type: $isReturn ? TransactionType::REFUND : TransactionType::SALE,
                signedAmountMinor: $isReturn ? -abs($retailMinor) : abs($retailMinor),
                currency: $currency,
                occurredAt: $occurredAt,
                field: 0 !== $this->minor($row, 'retailPriceWithDisc', 'retail_price_withdisc_rub') ? 'retailPriceWithDisc' : 'retailAmount',
                sellerOperName: $sellerOperName,
                docTypeName: $docTypeName,
                description: $isReturn ? 'WB refund gross amount' : 'WB sale gross amount',
                row: $row,
            );
        }

        if (0 !== $commissionMinor) {
            $this->add(
                transactions: $transactions,
                operationGroupId: $operationGroupId,
                rowKey: $rowKey,
                component: 'commission',
                type: TransactionType::COMMISSION,
                signedAmountMinor: $isReturn ? abs($commissionMinor) : -abs($commissionMinor),
                currency: $currency,
                occurredAt: $occurredAt,
                field: 'retailPriceWithDisc-forPay-acquiringFee',
                sellerOperName: $sellerOperName,
                docTypeName: $docTypeName,
                description: 'WB marketplace commission',
                row: $row,
            );
        }

        if (0 !== $acquiringMinor) {
            $this->add(
                transactions: $transactions,
                operationGroupId: $operationGroupId,
                rowKey: $rowKey,
                component: 'acquiring',
                type: TransactionType::ACQUIRING,
                signedAmountMinor: $isReturn ? abs($acquiringMinor) : -abs($acquiringMinor),
                currency: $currency,
                occurredAt: $occurredAt,
                field: 'acquiringFee',
                sellerOperName: $sellerOperName,
                docTypeName: $docTypeName,
                description: 'WB acquiring fee',
                row: $row,
            );
        }
    }

    /**
     * @param list<WbFinancePreviewTransaction> $transactions
     * @param array<string, mixed>              $row
     */
    private function collectCostComponents(
        array &$transactions,
        array $row,
        string $operationGroupId,
        string $rowKey,
        string $currency,
        \DateTimeImmutable $occurredAt,
        string $sellerOperName,
        string $docTypeName,
    ): void {
        $normalizedOperation = mb_strtolower($sellerOperName);
        $deliveryServiceMinor = $this->minor($row, 'deliveryService', 'delivery_rub');
        if (0 !== $deliveryServiceMinor && (
            str_contains($normalizedOperation, 'логист')
            || 0 !== $this->minor($row, 'deliveryAmount', 'delivery_amount')
            || 0 !== $this->minor($row, 'returnAmount', 'return_amount')
        )) {
            $component = 'logistics';
            if (0 !== $this->minor($row, 'deliveryAmount', 'delivery_amount')) {
                $component = 'logistics_delivery';
            } elseif (0 !== $this->minor($row, 'returnAmount', 'return_amount')) {
                $component = 'logistics_return';
            } elseif (str_contains($normalizedOperation, 'коррек')) {
                $component = 'logistics_correction';
            }

            $this->addCost(
                transactions: $transactions,
                operationGroupId: $operationGroupId,
                rowKey: $rowKey,
                component: $component,
                type: TransactionType::LOGISTICS,
                signedAmountMinor: $this->costSignedAmountMinor($deliveryServiceMinor),
                currency: $currency,
                occurredAt: $occurredAt,
                field: 'deliveryService',
                sellerOperName: $sellerOperName,
                docTypeName: $docTypeName,
                description: 'WB logistics',
                row: $row,
            );
        }

        $this->addCostField($transactions, $row, $operationGroupId, $rowKey, $currency, $occurredAt, $sellerOperName, $docTypeName, 'storage', TransactionType::STORAGE, 'paidStorage', 'storage_fee', 'WB storage fee');
        $this->addCostField($transactions, $row, $operationGroupId, $rowKey, $currency, $occurredAt, $sellerOperName, $docTypeName, 'acceptance', TransactionType::ACCEPTANCE, 'paidAcceptance', 'acceptance', 'WB acceptance fee');
        $this->addCostField($transactions, $row, $operationGroupId, $rowKey, $currency, $occurredAt, $sellerOperName, $docTypeName, 'penalty', TransactionType::PENALTY, 'penalty', null, 'WB penalty');
        $this->addCostField($transactions, $row, $operationGroupId, $rowKey, $currency, $occurredAt, $sellerOperName, $docTypeName, 'deduction', TransactionType::ADJUSTMENT, 'deduction', null, 'WB deduction');
        $this->addCostField($transactions, $row, $operationGroupId, $rowKey, $currency, $occurredAt, $sellerOperName, $docTypeName, 'warehouse_logistics', TransactionType::LOGISTICS, 'rebillLogisticCost', 'rebill_logistic_cost', 'WB warehouse logistics');
        $this->addCostField($transactions, $row, $operationGroupId, $rowKey, $currency, $occurredAt, $sellerOperName, $docTypeName, 'pvz_processing', TransactionType::LOGISTICS, 'ppvzReward', 'ppvz_reward', 'WB PVZ processing');

        $additionalPaymentMinor = $this->minor($row, 'additionalPayment', 'additional_payment');
        if (0 !== $additionalPaymentMinor) {
            $this->add(
                transactions: $transactions,
                operationGroupId: $operationGroupId,
                rowKey: $rowKey,
                component: 'additional_payment',
                type: TransactionType::BONUS,
                signedAmountMinor: $additionalPaymentMinor,
                currency: $currency,
                occurredAt: $occurredAt,
                field: 'additionalPayment',
                sellerOperName: $sellerOperName,
                docTypeName: $docTypeName,
                description: 'WB additional payment',
                row: $row,
            );
        }

        $cashbackDiscountMinor = $this->minor($row, 'cashbackDiscount', 'cashback_discount');
        if (0 !== $cashbackDiscountMinor) {
            $this->add(
                transactions: $transactions,
                operationGroupId: $operationGroupId,
                rowKey: $rowKey,
                component: 'loyalty_discount_compensation',
                type: TransactionType::BONUS,
                signedAmountMinor: $cashbackDiscountMinor,
                currency: $currency,
                occurredAt: $occurredAt,
                field: 'cashbackDiscount',
                sellerOperName: $sellerOperName,
                docTypeName: $docTypeName,
                description: 'WB loyalty discount compensation',
                row: $row,
            );
        }
    }

    /**
     * @param list<WbFinancePreviewTransaction> $transactions
     * @param array<string, mixed>              $row
     */
    private function addCostField(
        array &$transactions,
        array $row,
        string $operationGroupId,
        string $rowKey,
        string $currency,
        \DateTimeImmutable $occurredAt,
        string $sellerOperName,
        string $docTypeName,
        string $component,
        TransactionType $type,
        string $camelField,
        ?string $snakeField,
        string $description,
    ): void {
        $amountMinor = null === $snakeField
            ? $this->minor($row, $camelField)
            : $this->minor($row, $camelField, $snakeField);

        if (0 === $amountMinor) {
            return;
        }

        $this->addCost(
            transactions: $transactions,
            operationGroupId: $operationGroupId,
            rowKey: $rowKey,
            component: $component,
            type: $type,
            signedAmountMinor: $this->costSignedAmountMinor($amountMinor),
            currency: $currency,
            occurredAt: $occurredAt,
            field: $camelField,
            sellerOperName: $sellerOperName,
            docTypeName: $docTypeName,
            description: $description,
            row: $row,
        );
    }

    /**
     * @param list<WbFinancePreviewTransaction> $transactions
     * @param array<string, mixed>              $row
     */
    private function addCost(
        array &$transactions,
        string $operationGroupId,
        string $rowKey,
        string $component,
        TransactionType $type,
        int $signedAmountMinor,
        string $currency,
        \DateTimeImmutable $occurredAt,
        string $field,
        string $sellerOperName,
        string $docTypeName,
        string $description,
        array $row,
    ): void {
        $this->add($transactions, $operationGroupId, $rowKey, $component, $type, $signedAmountMinor, $currency, $occurredAt, $field, $sellerOperName, $docTypeName, $description, $row);
    }

    private function costSignedAmountMinor(int $amountMinor): int
    {
        return -abs($amountMinor);
    }

    /**
     * @param list<WbFinancePreviewTransaction> $transactions
     *
     * @return list<WbFinancePreviewTransaction>
     */
    private function payoutCheckTransactions(array $transactions): array
    {
        return array_values(array_filter(
            $transactions,
            static fn (WbFinancePreviewTransaction $transaction): bool => in_array(
                $transaction->type,
                [TransactionType::SALE, TransactionType::REFUND, TransactionType::COMMISSION, TransactionType::ACQUIRING],
                true,
            ),
        ));
    }

    /**
     * @param list<WbFinancePreviewTransaction> $transactions
     * @param array<string, mixed>              $row
     */
    private function add(
        array &$transactions,
        string $operationGroupId,
        string $rowKey,
        string $component,
        TransactionType $type,
        int $signedAmountMinor,
        string $currency,
        \DateTimeImmutable $occurredAt,
        ?string $field,
        string $sellerOperName,
        string $docTypeName,
        string $description,
        array $row,
    ): void {
        if (0 === $signedAmountMinor) {
            return;
        }

        $transactions[] = new WbFinancePreviewTransaction(
            sourceKey: sprintf('wb:sales-report-detailed:%s:%s', $this->normalizeComponent($rowKey), $component),
            operationGroupId: $operationGroupId,
            component: $component,
            type: $type,
            direction: $this->direction($signedAmountMinor),
            amountMinor: abs($signedAmountMinor),
            currency: $currency,
            occurredAt: $occurredAt,
            sourceTz: self::SOURCE_TZ,
            field: $field,
            sellerOperName: $sellerOperName,
            docTypeName: $docTypeName,
            description: $description,
            sourceData: [
                '_ingestion_resource' => WbResourceType::FINANCE_SALES_REPORT_DETAILED,
                '_ingestion_component' => $component,
                '_ingestion_field' => $field,
                '_ingestion_source_key' => sprintf('wb:sales-report-detailed:%s:%s', $this->normalizeComponent($rowKey), $component),
                'rrdId' => $this->string($row, 'rrdId', 'rrd_id'),
                'reportId' => $this->string($row, 'reportId', 'realizationreport_id'),
                'sellerOperName' => $sellerOperName,
                'docTypeName' => $docTypeName,
                'srid' => $this->string($row, 'srid'),
                'nmId' => $this->string($row, 'nmId', 'nm_id'),
                'sku' => $this->string($row, 'sku', 'barcode'),
                'retailPriceWithDisc' => $this->raw($row, 'retailPriceWithDisc', 'retail_price_withdisc_rub'),
                'forPay' => $this->raw($row, 'forPay', 'ppvz_for_pay'),
                'acquiringFee' => $this->raw($row, 'acquiringFee', 'acquiring_fee'),
                'ppvzReward' => $this->raw($row, 'ppvzReward', 'ppvz_reward'),
                'cashbackDiscount' => $this->raw($row, 'cashbackDiscount', 'cashback_discount'),
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function nonZeroKnownMoneyFields(array $row): array
    {
        $fields = [
            ['retailPriceWithDisc', 'retail_price_withdisc_rub'],
            ['forPay', 'ppvz_for_pay'],
            ['acquiringFee', 'acquiring_fee'],
            ['deliveryService', 'delivery_rub'],
            ['paidStorage', 'storage_fee'],
            ['paidAcceptance', 'acceptance'],
            ['penalty'],
            ['deduction'],
            ['rebillLogisticCost', 'rebill_logistic_cost'],
            ['additionalPayment', 'additional_payment'],
            ['ppvzReward', 'ppvz_reward'],
            ['cashbackDiscount', 'cashback_discount'],
            ['loyaltyDiscount', 'loyalty_discount'],
            ['cashbackAmount', 'cashback_amount'],
        ];

        $nonZero = [];
        foreach ($fields as $fieldAliases) {
            if (0 !== $this->minor($row, ...$fieldAliases)) {
                $nonZero[] = $fieldAliases[0];
            }
        }

        return $nonZero;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowKey(array $row, int $rowIndex): string
    {
        foreach (['rrdId', 'rrd_id', 'srid', 'shkId', 'shk_id', 'orderId', 'order_id'] as $key) {
            $value = $this->string($row, $key);
            if ('' !== $value && '0' !== $value) {
                return $value;
            }
        }

        $reportId = $this->string($row, 'reportId', 'realizationreport_id');
        if ('' !== $reportId) {
            return sprintf('%s:row-%d', $reportId, $rowIndex);
        }

        return sprintf('row-%d', $rowIndex);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function operationDate(array $row): \DateTimeImmutable
    {
        foreach (['saleDt', 'sale_dt', 'rrDate', 'rr_dt', 'createDate', 'create_dt', 'dateFrom', 'date_from'] as $key) {
            $value = $this->string($row, $key);
            if ('' === $value) {
                continue;
            }

            return new \DateTimeImmutable($value, new \DateTimeZone(self::SOURCE_TZ));
        }

        return new \DateTimeImmutable('@0');
    }

    private function direction(int $signedAmountMinor): TransactionDirection
    {
        return $signedAmountMinor < 0 ? TransactionDirection::OUT : TransactionDirection::IN;
    }

    private function isSaleOrReturn(string $docTypeName): bool
    {
        return $this->isSale($docTypeName) || $this->isReturn($docTypeName);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasPayoutCheckFields(array $row): bool
    {
        return 0 !== $this->minor($row, 'retailPriceWithDisc', 'retail_price_withdisc_rub')
            || 0 !== $this->minor($row, 'retailAmount', 'retail_amount')
            || 0 !== $this->minor($row, 'forPay', 'ppvz_for_pay')
            || 0 !== $this->minor($row, 'acquiringFee', 'acquiring_fee');
    }

    private function isSale(string $docTypeName): bool
    {
        return in_array(mb_strtolower(trim($docTypeName)), ['продажа', 'sale'], true);
    }

    private function isReturn(string $docTypeName): bool
    {
        return in_array(mb_strtolower(trim($docTypeName)), ['возврат', 'return'], true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function currency(array $row): string
    {
        $currency = strtoupper($this->string($row, 'currency'));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'RUB';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function minor(array $row, string ...$keys): int
    {
        $value = $this->raw($row, ...$keys);
        if (null === $value) {
            return 0;
        }

        return $this->decimalToMinor($value);
    }

    private function decimalToMinor(mixed $value): int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (!is_string($value)) {
            return 0;
        }

        $normalized = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim($value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
            return 0;
        }

        $sign = str_starts_with($normalized, '-') ? -1 : 1;
        $unsigned = ltrim($normalized, '-');
        [$rubles, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        $minor = ((int) $rubles) * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$minor;
        }

        return $sign * $minor;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string ...$keys): string
    {
        $value = $this->raw($row, ...$keys);

        return null === $value ? '' : trim((string) $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function raw(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            if (null === $value) {
                continue;
            }

            if (is_string($value) && '' === trim($value)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function normalizeComponent(string $value): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value);

        return trim((string) $normalized, '-') ?: 'unknown';
    }
}
