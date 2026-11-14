<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Domain;

use App\MarketplaceOperations\Application\DTO\OperationRecord;
use App\MarketplaceOperations\Enum\CascadeLevel;
use App\MarketplaceOperations\Enum\OperationKind;

/**
 * Витрина дня кабинета из операций канона (`stages/stage-5.md` §2.2). Чистая функция.
 *
 * - строки канона — по листингу × виду, уровень из `OperationKind::level()`;
 * - себестоимость (D5) — (продано − возвращено) × цена на дату;
 * - реклама без SKU (итог канона) — каждый вид по долям SKU в статистике легаси своего вида
 *   (клики — `legacy_clicks_share`, оплата за заказ — `legacy_order_share`); нет статистики — без SKU;
 * - хранение без SKU — по остатку на дату (`stock_share`), нет остатков — вместе с общими;
 * - общие и прямые без SKU — по выручке SKU за день (`revenue_share`, D3).
 *
 * Разнесение пишется парой: доля на SKU и обратная строка без SKU, поэтому Σ строк (без
 * себестоимости и справочных) = Σ канона, а строки без SKU — нераспределённый остаток.
 */
final class DayEconomicsCalculator
{
    public const COST_PRICE = 'cost_price';
    public const ADS_ALLOCATED = 'ads_allocated';
    public const STORAGE_SHARE = 'storage_share';
    public const OVERHEAD_SHARE = 'overhead_share';

    public const KEY_ADS = [
        'ads_cpc' => 'legacy_clicks_share',
        'ads_pay_per_order' => 'legacy_order_share',
    ];
    public const KEY_STOCK = 'stock_share';
    public const KEY_REVENUE = 'revenue_share';

    /**
     * @param list<OperationRecord> $operations
     * @param array<string, string> $costPrices listingId => цена за штуку, десятичная строка
     * @param array<string, array<string, string>> $adSpend вид канона (`ads_cpc`, `ads_pay_per_order`) => listingId => реклама SKU в статистике легаси (только доли)
     * @param array<string, float> $stock listingId => остаток, шт
     */
    public function calculate(array $operations, array $costPrices, array $adSpend, array $stock): DayEconomics
    {
        $currency = 'RUB';
        /** @var array<string, array{listing: ?string, kind: OperationKind, amount: int, qty: ?int}> $groups */
        $groups = [];
        $revenue = [];
        $soldQty = [];
        $canon = $storagePool = $overheadPool = 0;
        $adsPools = [];

        foreach ($operations as $op) {
            // ponytail: витрина в рублях — у кабинетов РФ другой валюты нет; чужая валюта роняет день, а не считает неверно
            if ($op->currency !== $currency) {
                throw new \DomainException(sprintf('MarketplaceEconomics: валюта %s не поддерживается', $op->currency));
            }
            $level = $op->kind->level();
            $key = ($op->listingId ?? '').'|'.$op->kind->value;
            $group = $groups[$key] ?? ['listing' => $op->listingId, 'kind' => $op->kind, 'amount' => 0, 'qty' => null];
            $group['amount'] += $op->amountMinor;
            if (null !== $op->quantity) {
                $group['qty'] = ($group['qty'] ?? 0) + abs($op->quantity);
            }
            $groups[$key] = $group;

            if (CascadeLevel::INFO !== $level) {
                $canon += $op->amountMinor;
            }
            if (null !== $op->listingId) {
                if (CascadeLevel::REVENUE === $level) {
                    $revenue[$op->listingId] = ($revenue[$op->listingId] ?? 0) + $op->amountMinor;
                    $sign = OperationKind::RETURN === $op->kind ? -1 : 1;
                    $soldQty[$op->listingId] = ($soldQty[$op->listingId] ?? 0) + $sign * abs($op->quantity ?? 0);
                }
                continue;
            }
            match (true) {
                CascadeLevel::MD2 === $level => $adsPools[$op->kind->value] = ($adsPools[$op->kind->value] ?? 0) + $op->amountMinor,
                OperationKind::STORAGE === $op->kind => $storagePool += $op->amountMinor,
                CascadeLevel::MD1 === $level, CascadeLevel::OVERHEAD === $level => $overheadPool += $op->amountMinor,
                // выручка без листинга (SKU не нашёлся) и справочные остаются без SKU
                default => null,
            };
        }

        $lines = [];
        foreach ($groups as $group) {
            $lines[] = new EconomicsLine($group['listing'], $group['kind']->value, EconomicsLevel::fromCascade($group['kind']->level()), $group['amount'], $group['qty']);
        }

        $costPriceMinor = $withoutCostPrice = 0;
        ksort($soldQty);
        foreach ($soldQty as $listingId => $qty) {
            if (0 === $qty) {
                continue;
            }
            $price = $costPrices[$listingId] ?? null;
            if (null === $price) {
                ++$withoutCostPrice;
                continue;
            }
            $amount = -(int) bcmul(bcmul($price, '100', 2), (string) $qty, 0);
            $costPriceMinor += $amount;
            $lines[] = new EconomicsLine($listingId, self::COST_PRICE, EconomicsLevel::COST_PRICE, $amount, $qty);
        }

        $stockWeights = array_filter($stock, static fn (float $q): bool => $q > 0);
        if ([] === $stockWeights) {
            $overheadPool += $storagePool;
            $storagePool = 0;
        }

        ksort($adsPools);
        foreach ($adsPools as $kind => $pool) {
            $key = self::KEY_ADS[$kind] ?? null;
            if (null !== $key) {
                $weights = array_filter($adSpend[$kind] ?? [], static fn (string $v): bool => 1 === bccomp($v, '0', 2));
                array_push($lines, ...$this->allocate($pool, $weights, self::ADS_ALLOCATED, EconomicsLevel::MD2, $key));
            }
        }
        array_push(
            $lines,
            ...$this->allocate($storagePool, array_map(static fn (float $q): string => (string) $q, $stockWeights), self::STORAGE_SHARE, EconomicsLevel::OVERHEAD, self::KEY_STOCK),
            ...$this->allocate($overheadPool, array_map('strval', array_filter($revenue, static fn (int $r): bool => $r > 0)), self::OVERHEAD_SHARE, EconomicsLevel::OVERHEAD, self::KEY_REVENUE),
        );

        $unallocated = 0;
        foreach ($lines as $line) {
            if (null === $line->listingId && EconomicsLevel::INFO !== $line->level) {
                $unallocated += $line->amountMinor;
            }
        }

        return new DayEconomics($lines, $canon, $unallocated, $costPriceMinor, $withoutCostPrice, $currency);
    }

    /**
     * Сумма по весам методом наибольшего остатка: Σ долей = сумма до копейки, порядок по листингу.
     * Пара — доли на SKU и обратная строка без SKU. Нет весов или суммы — ничего.
     *
     * @param array<string, string> $weights listingId => вес > 0
     *
     * @return list<EconomicsLine>
     */
    private function allocate(int $total, array $weights, string $measure, EconomicsLevel $level, string $key): array
    {
        if (0 === $total || [] === $weights) {
            return [];
        }
        ksort($weights, \SORT_STRING);
        $sum = '0';
        foreach ($weights as $w) {
            $sum = bcadd($sum, $w, 6);
        }

        $shares = $fractions = [];
        $assigned = 0;
        foreach ($weights as $listingId => $w) {
            $exact = bcdiv(bcmul((string) $total, $w, 6), $sum, 6);
            $base = (int) bcadd($exact, '0', 0); // усечение к нулю
            $shares[$listingId] = $base;
            $fractions[$listingId] = abs((float) bcsub($exact, (string) $base, 6));
            $assigned += $base;
        }
        $left = $total - $assigned;
        arsort($fractions);
        foreach (array_keys($fractions) as $listingId) {
            if (0 === $left) {
                break;
            }
            $unit = $left > 0 ? 1 : -1;
            $shares[$listingId] += $unit;
            $left -= $unit;
        }

        $lines = [];
        foreach ($shares as $listingId => $amount) {
            if (0 !== $amount) {
                $lines[] = new EconomicsLine((string) $listingId, $measure, $level, $amount, null, $key);
            }
        }
        $lines[] = new EconomicsLine(null, $measure, $level, -$total, null, $key);

        return $lines;
    }
}
