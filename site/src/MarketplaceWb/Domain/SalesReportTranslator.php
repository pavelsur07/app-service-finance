<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Domain;

use App\MarketplaceOperations\Enum\CascadeLevel;
use App\MarketplaceOperations\Enum\OperationKind;
use App\Shared\Domain\ValueObject\Money;

/**
 * Строка детального отчёта WB (`/api/v5/supplier/reportDetailByPeriod`, запись `SourceFacts` как есть) →
 * компоненты. Правила и знаки — `stages/stage-8.md`, 8.3: знак поля как в источнике, без `abs()`.
 *
 * Итог строки без справочных = `s × forPay` + поля затрат и доплат (`s` = +1 продажа, −1 возврат).
 */
final class SalesReportTranslator
{
    /** Меняется вместе с правилами: дни с другой версией переводятся заново. */
    public const RULES_VERSION = 'wb-sales-report-4';
    /** В строке отчёта нет `techSize`. */
    public const UNKNOWN_SIZE = 'UNKNOWN';

    private const PVZ_OPERATION = 'возмещение за выдачу и возврат товаров на пвз';

    /** Поле затрат (вычитается) или доплаты (прибавляется) → вид; `null` — решает администратор. */
    private const COST_FIELDS = [
        'paidStorage' => OperationKind::STORAGE,
        'penalty' => OperationKind::FINE,
        'rebillLogisticCost' => null,
        'paidAcceptance' => null,
        'cashbackAmount' => OperationKind::PROMO_REVIEWS,
        'cashbackCommissionChange' => null,
    ];
    private const CREDIT_FIELDS = [
        'cashbackDiscount' => OperationKind::COMPENSATION,
        'additionalPayment' => OperationKind::COMPENSATION,
    ];

    /**
     * @param array<string, mixed> $row
     *
     * @return list<WbComponent>
     */
    public function translate(string $sourceKey, array $row): array
    {
        $currency = \is_string($row['currency'] ?? null) && 3 === \strlen($row['currency']) ? $row['currency'] : 'RUB';
        $nmId = \is_int($row['nmId'] ?? null) && $row['nmId'] > 0 ? (string) $row['nmId'] : null;
        $size = \is_string($row['techSize'] ?? null) && '' !== trim($row['techSize']) ? trim($row['techSize']) : self::UNKNOWN_SIZE;
        $operation = mb_strtolower(trim(\is_string($row['sellerOperName'] ?? null) ? $row['sellerOperName'] : ''));
        $add = function (array &$out, string $type, Money $amount, ?OperationKind $kind, ?int $quantity = null) use ($sourceKey, $nmId, $size): void {
            if (!$amount->isZero()) {
                $out[] = new WbComponent($type, $amount, $sourceKey, $nmId, null === $nmId ? null : $size, $quantity, $kind);
            }
        };
        $money = fn (string $field): Money => $this->money($row[$field] ?? null, $currency);

        $components = [];
        $doc = mb_strtolower(trim(\is_string($row['docTypeName'] ?? null) ? $row['docTypeName'] : ''));
        if ('продажа' === $doc || 'возврат' === $doc) {
            $s = 'продажа' === $doc ? 1 : -1;
            $quantity = \is_int($row['quantity'] ?? null) ? $row['quantity'] : 0;
            $revenue = $money('retailPriceWithDisc')->multiply((string) $quantity);
            $forPay = $money('forPay');
            $acquiring = $money('acquiringFee');
            $signed = static fn (Money $m): Money => 1 === $s ? $m : $m->negate();

            if (!$revenue->isZero()) {
                $add($components, 1 === $s ? 'wb:field:sale' : 'wb:field:return', $signed($revenue), 1 === $s ? OperationKind::SALE : OperationKind::RETURN, $s * $quantity);
                $add($components, 'wb:calc:spp', $signed($revenue->subtract($money('retailAmount'))), OperationKind::SPP);
            }
            $add($components, 'wb:field:acquiringFee', $signed($acquiring)->negate(), OperationKind::ACQUIRING);
            if (!$revenue->isZero()) {
                $add($components, 'wb:calc:commission', $signed($revenue->subtract($forPay)->subtract($acquiring))->negate(), OperationKind::COMMISSION);
            } else {
                // Выплата без выручки («Добровольная компенсация при возврате») — решает администратор.
                $add($components, 'wb:payout:'.$operation, $signed($forPay->add($acquiring)), null);
            }
        }

        $delivery = $money('deliveryService');
        $isReturnLogistics = 0 !== ($row['returnAmount'] ?? 0);
        $add($components, $isReturnLogistics ? 'wb:field:deliveryService:return' : 'wb:field:deliveryService', $delivery->negate(), $isReturnLogistics ? OperationKind::LOGISTICS_BACK : OperationKind::LOGISTICS_TO);
        foreach (self::COST_FIELDS as $field => $kind) {
            $add($components, 'wb:field:'.$field, $money($field)->negate(), $kind);
        }
        foreach (self::CREDIT_FIELDS as $field => $kind) {
            $add($components, 'wb:field:'.$field, $money($field), $kind);
        }
        // В строке продажи ppvzReward — внутри комиссии; как затрата — только в строке ПВЗ.
        if (self::PVZ_OPERATION === $operation) {
            $add($components, 'wb:field:ppvzReward', $money('ppvzReward')->negate(), null);
        }
        $add($components, 'wb:deduction:'.self::deductionReason($row['bonusTypeName'] ?? null), $money('deduction')->negate(), null);

        return $components;
    }

    /** Справочные компоненты (СПП, оплата рекламы удержанием) в итог строки не входят. */
    public static function isInformational(WbComponent $component): bool
    {
        return null !== $component->kind && CascadeLevel::INFO === $component->kind->level();
    }

    /** Причина удержания без номера документа и месяца: один код на вид удержания. */
    public static function deductionReason(mixed $reason): string
    {
        $reason = \is_string($reason) ? trim($reason) : '';
        $reason = (string) preg_replace('/,\s*документ\s*№.*$/u', '', $reason);
        $reason = (string) preg_replace('/\s+за\s+\p{L}+\s+\d{4}$/u', '', $reason);

        return '' === $reason ? 'без причины' : $reason;
    }

    private function money(mixed $value, string $currency): Money
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        }

        return \is_string($value) && 1 === preg_match('/^-?\d+(\.\d+)?$/', trim($value))
            ? Money::fromString(trim($value), $currency)
            : Money::fromMinor(0, $currency);
    }
}
