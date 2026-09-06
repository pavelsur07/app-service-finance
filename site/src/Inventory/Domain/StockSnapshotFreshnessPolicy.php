<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

/**
 * Единственное определение понятия «снапшот остатков протух».
 *
 * Переиспользуется запросом остатков и гейтом свежести намеренно: три копии
 * предиката в трёх запросах рано или поздно разойдутся и дадут взаимоисключающие
 * показания о том, свежие данные или нет.
 *
 * Порог считается в днях от даты отчёта. При пороге 2 и ежедневной загрузке
 * (04:05 Ozon, 04:15 WB) отчёт переживает два подряд пропущенных прогона:
 * для отчёта на 06.09 принимаются снапшоты за 04.09 и новее.
 */
final readonly class StockSnapshotFreshnessPolicy
{
    public const DEFAULT_MAX_AGE_DAYS = 2;

    public function __construct(
        private int $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS,
    ) {
        if ($maxAgeDays < 0) {
            throw new \InvalidArgumentException('maxAgeDays must be greater than or equal to 0.');
        }
    }

    public function maxAgeDays(): int
    {
        return $this->maxAgeDays;
    }

    /**
     * Самая ранняя дата снапшота, ещё пригодная для отчёта на указанную дату.
     */
    public function earliestAcceptableDate(\DateTimeImmutable $reportDate): \DateTimeImmutable
    {
        return $reportDate->setTime(0, 0)->modify(sprintf('-%d days', $this->maxAgeDays));
    }

    public function isStale(\DateTimeImmutable $snapshotDate, \DateTimeImmutable $reportDate): bool
    {
        return $snapshotDate->setTime(0, 0) < $this->earliestAcceptableDate($reportDate);
    }
}
