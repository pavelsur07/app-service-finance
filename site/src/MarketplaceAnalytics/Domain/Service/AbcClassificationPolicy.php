<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\Service;

use App\MarketplaceAnalytics\Enum\AbcClass;

/**
 * ABC-анализ по накопленной доле: листинги по убыванию значения, A — пока накопленная
 * доля ДО листинга меньше 80%, B — меньше 95%, остальные C. Листинг, на котором доля
 * переходит порог, попадает в старший класс.
 *
 * Значения ≤ 0 в долю не входят и получают класс $nonPositiveClass: для выручки это C
 * (вклада нет), для прибыли — D (убыток).
 */
final readonly class AbcClassificationPolicy
{
    private const A_THRESHOLD_PERCENT = 80;
    private const B_THRESHOLD_PERCENT = 95;

    /**
     * @param array<string, float> $valueById
     *
     * @return array<string, AbcClass>
     */
    public function classify(array $valueById, AbcClass $nonPositiveClass): array
    {
        $result = [];
        // Копейки целыми: граница ровно 80% не должна зависеть от ошибки float-суммы.
        $positiveCents = [];
        foreach ($valueById as $id => $value) {
            $cents = (int) round($value * 100);
            if ($cents > 0) {
                $positiveCents[(string) $id] = $cents;
            } else {
                $result[(string) $id] = $nonPositiveClass;
            }
        }

        // Равные значения — по ID: класс не должен меняться от запроса к запросу.
        uksort($positiveCents, static fn (string $a, string $b): int => [$positiveCents[$b], $a] <=> [$positiveCents[$a], $b]);

        $total = array_sum($positiveCents);
        $accumulated = 0;
        foreach ($positiveCents as $id => $cents) {
            $result[$id] = match (true) {
                $accumulated * 100 < self::A_THRESHOLD_PERCENT * $total => AbcClass::A,
                $accumulated * 100 < self::B_THRESHOLD_PERCENT * $total => AbcClass::B,
                default => AbcClass::C,
            };
            $accumulated += $cents;
        }

        return $result;
    }
}
