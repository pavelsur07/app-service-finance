<?php

declare(strict_types=1);

namespace App\Budget\Domain;

/**
 * Отклонение факта от плана в ячейке план–факт. Суммы — в знаке значения отчёта ОПиУ
 * (доход − расход), поэтому `diff` = факт − план одинаково читается для доходов и расходов:
 * минус — хуже для прибыли (недобор выручки или перерасход).
 *
 * `percent` = diff / |план| × 100 с одним знаком; при нулевом плане его нет. Значимо: |percent|
 * больше порога (решение Владельца 04.11 — 10%) либо факт без плана. Показатели (KPI, проценты,
 * количества) — только разница, без процента и пометки.
 */
final readonly class BudgetVariance
{
    public const int THRESHOLD_PERCENT = 10;

    private function __construct(
        public string $plan,
        public string $fact,
        public string $diff,
        public ?string $percent,
        public bool $significant,
    ) {
    }

    public static function of(string $plan, string $fact, bool $money): self
    {
        $scale = $money ? 2 : 4;
        $diff = bcsub($fact, $plan, $scale);
        if (!$money) {
            return new self($plan, $fact, $diff, null, false);
        }

        if (0 === bccomp($plan, '0', $scale)) {
            return new self($plan, $fact, $diff, null, 0 !== bccomp($fact, '0', $scale));
        }

        $absPlan = ltrim($plan, '-');
        $raw = bcdiv(bcmul($diff, '100', 6), $absPlan, 6);
        $percent = self::roundHalfAwayFromZero($raw, 1);

        return new self($plan, $fact, $diff, $percent, 1 === bccomp(ltrim($raw, '-'), (string) self::THRESHOLD_PERCENT, 6));
    }

    private static function roundHalfAwayFromZero(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, str_starts_with($value, '-') ? '-'.$half : $half, $scale);
    }
}
