<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Domain\Service\SourceDataHasher;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use Ramsey\Uuid\Uuid;

final readonly class OzonAccrualByDayPreviewMapper
{
    public function __construct(
        private OzonMoneyParser $moneyParser,
        private SourceDataHasher $sourceDataHasher,
        private ?StoredOzonAccrualTypeNameResolver $typeNameResolver = null,
        private ?OzonAccrualCategoryTaxonomyResolver $categoryResolver = null,
    ) {
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
        bool $includeSaleRefund = false,
        bool $recordUnknownCategories = false,
    ): array {
        $this->categoryResolver?->resetPerPreviewState();

        $transactions = [];

        foreach ($rows as $row) {
            $date = $this->stringValue($row['date'] ?? 'unknown');
            if (!$this->dateInWindow($date, $from, $to)) {
                continue;
            }

            $category = $this->stringValue($row['accrued_category'] ?? 'unknown');
            $accrualId = $this->accrualId($row);
            $operationGroupId = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, $accrualId))->toString();
            $unitNumber = $this->optionalString($row['unit_number'] ?? null);

            match ($category) {
                'POSTING' => $this->collectPosting($transactions, $companyId, $row, $operationGroupId, $date, $category, $accrualId, $unitNumber, $includeSaleRefund, $recordUnknownCategories),
                'ITEM' => $this->collectItemFees($transactions, $companyId, $row['item_fees'] ?? null, $operationGroupId, $date, $category, $accrualId, $unitNumber, $recordUnknownCategories),
                'NON_ITEM' => $this->collectNonItemFee($transactions, $companyId, $row['non_item_fee'] ?? null, $operationGroupId, $date, $category, $accrualId, $unitNumber, $recordUnknownCategories),
                'CONTAINER' => $this->collectContainerFees($transactions, $companyId, $row['container_fees'] ?? null, $operationGroupId, $date, $category, $accrualId, $unitNumber, recordUnknownCategories: $recordUnknownCategories),
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
        string $companyId,
        array $row,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        ?string $unitNumber,
        bool $includeSaleRefund,
        bool $recordUnknownCategories,
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

            if ($includeSaleRefund) {
                $this->collectSaleOrRefund($transactions, $product['commission'] ?? null, $operationGroupId, $date, $category, $accrualId, (int) $productIndex, $unitNumber);
            }
            $this->collectCommissionField($transactions, $product['commission'] ?? null, ['bonus'], $operationGroupId, $date, $category, $accrualId, (int) $productIndex, $unitNumber);
            $this->collectCommissionField(
                transactions: $transactions,
                commission: $product['commission'] ?? null,
                fields: ['coinvestment', 'co_investment', 'partner_program', 'partner_programs', 'partner_reward', 'partner_bonus'],
                operationGroupId: $operationGroupId,
                date: $date,
                category: $category,
                accrualId: $accrualId,
                productIndex: (int) $productIndex,
                unitNumber: $unitNumber,
                canonicalComponent: 'partner_programs',
            );
            $this->collectCommission($transactions, $product['commission'] ?? null, $operationGroupId, $date, $category, $accrualId, (int) $productIndex, $unitNumber);
            $this->collectDeliveryServices($transactions, $companyId, $product['delivery']['services'] ?? null, $operationGroupId, $date, $category, $accrualId, (int) $productIndex, $unitNumber, $recordUnknownCategories);
        }
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectSaleOrRefund(
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

        // Prefer sale_amount for sale/refund preview; seller_price is only a fallback.
        $money = $this->moneyField($commission, ['sale_amount', 'seller_price']);
        if (null === $money) {
            return;
        }

        $field = $money['field'];
        $amount = $money['amountMinor'];

        $isRefund = $amount < 0;
        $this->add(
            transactions: $transactions,
            operationGroupId: $operationGroupId,
            date: $date,
            category: $category,
            accrualId: $accrualId,
            component: sprintf('%s:product-%d', $isRefund ? 'refund' : 'sale', $productIndex),
            type: $isRefund ? TransactionType::REFUND : TransactionType::SALE,
            signedAmountMinor: $amount,
            field: $field,
            unitNumber: $unitNumber,
            ozonCategory: $this->categoryForField($field, $amount),
        );
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     * @param list<string> $fields
     */
    private function collectCommissionField(
        array &$transactions,
        mixed $commission,
        array $fields,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        int $productIndex,
        ?string $unitNumber,
        ?string $canonicalComponent = null,
    ): void {
        if (!is_array($commission)) {
            return;
        }

        foreach ($fields as $field) {
            if (!array_key_exists($field, $commission)) {
                continue;
            }

            $amount = $this->moneyObjectMinor($commission[$field]);
            if (0 === $amount) {
                continue;
            }

            $ozonCategory = $this->categoryForField($field, $amount);
            $this->add(
                transactions: $transactions,
                operationGroupId: $operationGroupId,
                date: $date,
                category: $category,
                accrualId: $accrualId,
                component: sprintf('%s:product-%d', $this->normalizeComponent($canonicalComponent ?? $field), $productIndex),
                type: $ozonCategory?->transactionType ?? TransactionType::BONUS,
                signedAmountMinor: $amount,
                field: $field,
                unitNumber: $unitNumber,
                ozonCategory: $ozonCategory,
            );

            return;
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

        $money = $this->moneyField($commission, ['commission', 'sale_commission']);
        if (null === $money) {
            return;
        }

        $field = $money['field'];
        $amount = $money['amountMinor'];

        $this->add(
            transactions: $transactions,
            operationGroupId: $operationGroupId,
            date: $date,
            category: $category,
            accrualId: $accrualId,
            component: sprintf('commission:product-%d', $productIndex),
            type: TransactionType::COMMISSION,
            signedAmountMinor: $amount,
            field: $field,
            unitNumber: $unitNumber,
            ozonCategory: $this->categoryForField($field, $amount),
        );
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectDeliveryServices(
        array &$transactions,
        string $companyId,
        mixed $services,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        int $productIndex,
        ?string $unitNumber,
        bool $recordUnknownCategories,
    ): void {
        if (!is_array($services)) {
            return;
        }

        foreach ($services as $serviceIndex => $service) {
            if (!is_array($service)) {
                continue;
            }

            $amount = $this->moneyObjectMinor($service['accrued'] ?? null);
            if (0 === $amount) {
                continue;
            }

            $typeId = $this->typeId($service);
            $ozonCategory = $this->categoryForTypedFee(
                typeId: $typeId,
                typeName: $this->resolvedTypeName($companyId, $typeId, $service),
                fallbackType: TransactionType::FEE,
                scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_DELIVERY,
                recordUnknown: $recordUnknownCategories,
            );

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
                ozonCategory: $ozonCategory,
            );
        }
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectItemFees(
        array &$transactions,
        string $companyId,
        mixed $itemFeesBlock,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        ?string $unitNumber,
        bool $recordUnknownCategories,
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
                    companyId: $companyId,
                    fee: $fee,
                    operationGroupId: $operationGroupId,
                    date: $date,
                    category: $category,
                    accrualId: $accrualId,
                    componentPrefix: sprintf('item_fee:group-%d:fee-%d', (int) $groupIndex, (int) $feeIndex),
                    type: TransactionType::FEE,
                    unitNumber: $unitNumber,
                    scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_ITEM,
                    recordUnknownCategories: $recordUnknownCategories,
                );
            }
        }
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectNonItemFee(
        array &$transactions,
        string $companyId,
        mixed $fee,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        ?string $unitNumber,
        bool $recordUnknownCategories,
    ): void {
        $this->collectTypedFee(
            transactions: $transactions,
            companyId: $companyId,
            fee: $fee,
            operationGroupId: $operationGroupId,
            date: $date,
            category: $category,
            accrualId: $accrualId,
            componentPrefix: 'non_item_fee',
            type: TransactionType::OTHER,
            unitNumber: $unitNumber,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            recordUnknownCategories: $recordUnknownCategories,
        );
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectContainerFees(
        array &$transactions,
        string $companyId,
        mixed $value,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        ?string $unitNumber,
        string $path = 'container_fee',
        string $scope = OzonAccrualCategoryTaxonomyResolver::SCOPE_CONTAINER,
        bool $recordUnknownCategories = false,
    ): void {
        if (!is_array($value)) {
            return;
        }

        $this->collectTypedFee(
            transactions: $transactions,
            companyId: $companyId,
            fee: $value,
            operationGroupId: $operationGroupId,
            date: $date,
            category: $category,
            accrualId: $accrualId,
            componentPrefix: $path,
            type: TransactionType::FEE,
            unitNumber: $unitNumber,
            scope: $scope,
            recordUnknownCategories: $recordUnknownCategories,
        );

        foreach ($value as $childKey => $child) {
            if (is_array($child)) {
                $this->collectContainerFees($transactions, $companyId, $child, $operationGroupId, $date, $category, $accrualId, $unitNumber, sprintf('%s:%s', $path, $this->normalizeComponent((string) $childKey)), $scope, $recordUnknownCategories);
            }
        }
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $transactions
     */
    private function collectTypedFee(
        array &$transactions,
        string $companyId,
        mixed $fee,
        string $operationGroupId,
        string $date,
        string $category,
        string $accrualId,
        string $componentPrefix,
        TransactionType $type,
        ?string $unitNumber,
        string $scope,
        bool $recordUnknownCategories,
    ): void {
        if (!is_array($fee) || !array_key_exists('accrued', $fee)) {
            return;
        }

        $amount = $this->moneyObjectMinor($fee['accrued']);
        if (0 === $amount) {
            return;
        }

        $typeId = $this->typeId($fee);
        $ozonCategory = $this->categoryForTypedFee(
            typeId: $typeId,
            typeName: $this->resolvedTypeName($companyId, $typeId, $fee),
            fallbackType: $type,
            scope: $scope,
            recordUnknown: $recordUnknownCategories,
        );

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
            ozonCategory: $ozonCategory,
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
        ?OzonAccrualCategory $ozonCategory = null,
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
            ozonCategoryCode: $ozonCategory?->code,
            ozonCategoryLabel: $ozonCategory?->label,
            ozonCategoryGroup: $ozonCategory?->group,
            ozonCategoryParent: $ozonCategory?->parentLabel,
            ozonCategorySortOrder: $ozonCategory?->sortOrder,
            ozonCategoryKnown: $ozonCategory?->known ?? true,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function accrualId(array $row): string
    {
        $accrualId = $this->optionalString($row['accrual_id'] ?? null);
        if (null !== $accrualId) {
            return $accrualId;
        }

        // Order-independent fallback: identical row content yields the same id
        // regardless of its position in the source response.
        return sprintf('fallback-%s', substr($this->sourceDataHasher->hash($row), 0, 16));
    }

    /**
     * @param array<string, mixed> $fee
     */
    private function typeId(array $fee): string
    {
        return $this->stringValue($fee['type_id'] ?? $fee['typeId'] ?? 'unknown');
    }

    /**
     * @param array<string, mixed> $fee
     */
    private function typeName(array $fee): ?string
    {
        foreach (['name', 'type_name', 'typeName'] as $field) {
            $name = $this->optionalString($fee[$field] ?? null);
            if (null !== $name) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fee
     */
    private function resolvedTypeName(string $companyId, string $typeId, array $fee): ?string
    {
        return $this->typeName($fee) ?? $this->typeNameResolver?->resolve($companyId, $typeId);
    }

    private function categoryForField(string $field, int $signedAmountMinor): ?OzonAccrualCategory
    {
        return $this->categoryResolver?->forField($field, $signedAmountMinor)
            ?? OzonAccrualCategory::forField($field, $signedAmountMinor);
    }

    private function categoryForTypedFee(
        ?string $typeId,
        ?string $typeName,
        TransactionType $fallbackType,
        string $scope,
        bool $recordUnknown,
    ): OzonAccrualCategory {
        return $this->categoryResolver?->forTypedFee($typeId, $typeName, $fallbackType, $scope, $recordUnknown)
            ?? OzonAccrualCategory::forTypedFee($typeId, $typeName, $fallbackType);
    }

    private function moneyObjectMinor(mixed $value): int
    {
        if (is_array($value) && array_key_exists('amount', $value)) {
            return $this->moneyParser->minor($value['amount']);
        }

        return $this->moneyParser->minor($value);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $fields
     *
     * @return array{field: string, amountMinor: int}|null
     */
    private function moneyField(array $values, array $fields): ?array
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            $amount = $this->moneyObjectMinor($values[$field]);
            if (0 !== $amount) {
                return ['field' => $field, 'amountMinor' => $amount];
            }
        }

        return null;
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
