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
 * Порог считается в днях от ОПОРНОЙ даты, а не от даты отчёта напрямую. Опорная —
 * это `min(дата отчёта, сегодня)`: см. referenceDate().
 *
 * При пороге 2 и ежедневной загрузке (04:05 Ozon, 04:15 WB) отчёт переживает два
 * подряд пропущенных прогона: для опорной даты 06.09 принимаются снапшоты за 04.09
 * и новее.
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
     * Опорная дата для оценки свежести: `min(дата отчёта, сегодня)`.
     *
     * Свежесть отвечает на вопрос «жива ли загрузка», а не «насколько снапшот близок
     * к концу отчётного периода». Период может заканчиваться в будущем — пресет
     * «текущий месяц» отдаёт последний день месяца, — и тогда сегодняшний снапшот
     * оказался бы просроченным на три недели, а отчёт остался бы вовсе без остатков.
     * Для периода в прошлом опорной остаётся дата отчёта: там уместен снапшот того
     * времени, а не сегодняшний.
     */
    public function referenceDate(\DateTimeImmutable $reportDate, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $today = self::calendarDate($now ?? new \DateTimeImmutable('today'));
        $report = self::calendarDate($reportDate);

        return $report < $today ? $report : $today;
    }

    /**
     * Самая ранняя дата снапшота, ещё пригодная для указанной опорной даты.
     */
    public function earliestAcceptableDate(\DateTimeImmutable $referenceDate): \DateTimeImmutable
    {
        return self::calendarDate($referenceDate)->modify(sprintf('-%d days', $this->maxAgeDays));
    }

    public function isStale(\DateTimeImmutable $snapshotDate, \DateTimeImmutable $referenceDate): bool
    {
        return self::calendarDate($snapshotDate) < $this->earliestAcceptableDate($referenceDate);
    }

    /**
     * Приводит значение к календарной дате в UTC.
     *
     * Сравнивать `setTime(0, 0)` недостаточно: у значений в разных часовых поясах
     * полночь приходится на разные моменты, и календарно более поздняя дата может
     * оказаться «меньше». Политика оперирует днями, а не моментами времени.
     */
    private static function calendarDate(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
