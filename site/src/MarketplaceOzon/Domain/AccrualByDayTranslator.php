<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Domain;

use App\MarketplaceOperations\Enum\OperationKind;
use App\Shared\Domain\ValueObject\Money;

/**
 * Начисление Ozon by-day (`/v1/finance/accrual/by-day`, запись `SourceFacts` как есть) → компоненты.
 * Ничего не сворачивает и не переворачивает: знак и код — как у Ozon (`DATA.md` §9.1).
 *
 * Сумма компонентов всегда равна `total_amount` начисления: то, что разбор не объяснил,
 * уходит компонентом `ozon:unexplained` — у него нет вида, поэтому он попадает в карантин,
 * а не теряется. Проверено на проде 14.11.2026: у POSTING `total_amount` = `sale_amount` +
 * `commission` (нет — `sale_commission`) + Σ услуг; `bonus` и `coinvestment` — справочно, они уже внутри `sale_amount`.
 */
final class AccrualByDayTranslator
{
    /** Меняется вместе с правилами разбора: дни с другой версией переводятся заново. */
    public const RULES_VERSION = 'ozon-by-day-3';

    public const UNEXPLAINED = 'ozon:unexplained';

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<AccrualComponent>
     */
    public function translate(string $sourceKey, array $payload): array
    {
        $total = $this->money($payload['total_amount'] ?? null) ?? Money::fromMinor(0, 'RUB');
        $components = [];

        match ($payload['accrued_category'] ?? null) {
            'POSTING' => $this->posting($components, $sourceKey, $payload['posting'] ?? null),
            'ITEM' => $this->itemFees($components, $sourceKey, $payload['item_fees'] ?? null),
            'NON_ITEM' => $this->fee($components, $sourceKey, $payload['non_item_fee'] ?? null, null),
            // ponytail: CONTAINER у кабинетов не встречался (14.11.2026) — весь итог уходит
            // в `ozon:unexplained` (карантин); разобрать, когда появится в данных.
            default => null,
        };

        $explained = Money::fromMinor(0, $total->currency());
        foreach ($components as $component) {
            if (!self::isInformational($component)) {
                $explained = $explained->add($component->amount);
            }
        }
        $rest = $total->subtract($explained);
        if (!$rest->isZero()) {
            $components[] = new AccrualComponent(self::UNEXPLAINED, $rest, $sourceKey);
        }

        return $components;
    }

    /**
     * Справочные компоненты (СПП, соинвестирование) внутри выручки: в итог начисления не входят.
     */
    public static function isInformational(AccrualComponent $component): bool
    {
        return \in_array($component->kind, [OperationKind::SPP, OperationKind::COINVESTMENT], true);
    }

    /**
     * @param list<AccrualComponent> $components
     */
    private function posting(array &$components, string $sourceKey, mixed $posting): void
    {
        if (!\is_array($posting) || !\is_array($posting['products'] ?? null)) {
            return;
        }

        foreach ($posting['products'] as $product) {
            if (!\is_array($product)) {
                continue;
            }
            $sku = $this->sku($product['sku'] ?? null);
            $quantity = \is_int($product['quantity'] ?? null) ? $product['quantity'] : null;
            $commission = \is_array($product['commission'] ?? null) ? $product['commission'] : [];

            $sale = $this->money($commission['sale_amount'] ?? null);
            if (null !== $sale && !$sale->isZero()) {
                $components[] = new AccrualComponent('ozon:field:sale_amount', $sale, $sourceKey, $sku, $quantity, $sale->isNegative() ? OperationKind::RETURN : OperationKind::SALE);
            }
            // Комиссия — поле `commission` (полная); `sale_commission` — только если его нет: у части
            // начислений `commission` больше на разницу цены (прод 14.11, 10 из 215 тыс.), как в легаси.
            $commissionField = null !== $this->money($commission['commission'] ?? null) ? 'commission' : 'sale_commission';
            foreach (['bonus' => OperationKind::SPP, 'coinvestment' => OperationKind::COINVESTMENT, $commissionField => OperationKind::COMMISSION] as $field => $kind) {
                $amount = $this->money($commission[$field] ?? null);
                if (null !== $amount && !$amount->isZero()) {
                    $components[] = new AccrualComponent('ozon:field:'.$field, $amount, $sourceKey, $sku, null, $kind);
                }
            }

            $services = $product['delivery']['services'] ?? null;
            foreach (\is_array($services) ? $services : [] as $service) {
                $this->fee($components, $sourceKey, $service, $sku);
            }
        }
    }

    /**
     * @param list<AccrualComponent> $components
     */
    private function itemFees(array &$components, string $sourceKey, mixed $itemFees): void
    {
        $groups = \is_array($itemFees) ? ($itemFees['fees'] ?? null) : null;
        foreach (\is_array($groups) ? $groups : [] as $group) {
            if (!\is_array($group) || !\is_array($group['fees'] ?? null)) {
                continue;
            }
            $sku = $this->sku($group['sku'] ?? null);
            foreach ($group['fees'] as $fee) {
                $this->fee($components, $sourceKey, $fee, $sku);
            }
        }
    }

    /**
     * @param list<AccrualComponent> $components
     */
    private function fee(array &$components, string $sourceKey, mixed $fee, ?string $sku): void
    {
        if (!\is_array($fee)) {
            return;
        }
        $amount = $this->money($fee['accrued'] ?? null);
        $typeId = $fee['type_id'] ?? null;
        if (null === $amount || $amount->isZero() || !(\is_int($typeId) || (\is_string($typeId) && ctype_digit($typeId)))) {
            return; // без суммы или без типа — останется в `ozon:unexplained`
        }

        $components[] = new AccrualComponent('ozon:service:'.$typeId, $amount, $sourceKey, $sku);
    }

    private function money(mixed $value): ?Money
    {
        if (\is_array($value)) {
            $currency = \is_string($value['currency'] ?? null) && '' !== $value['currency'] ? $value['currency'] : 'RUB';
            $value = $value['amount'] ?? null;
        } else {
            $currency = 'RUB';
        }
        if (\is_int($value)) {
            $value = (string) $value;
        }

        return \is_string($value) && 1 === preg_match('/^-?\d+(\.\d+)?$/', $value) ? Money::fromString($value, $currency) : null;
    }

    private function sku(mixed $value): ?string
    {
        return \is_int($value) || (\is_string($value) && '' !== $value) ? (string) $value : null;
    }
}
