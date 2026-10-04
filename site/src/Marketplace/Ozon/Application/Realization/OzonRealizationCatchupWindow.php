<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Realization;

/**
 * Окно догоняющей обработки «Реализации»: последние N закрытых месяцев (месяцы строго до текущего по Москве).
 * Месяц окна — первый день месяца. Старше окна применяют только по слову Владельца.
 */
final readonly class OzonRealizationCatchupWindow
{
    public const DEFAULT_MONTHS_BACK = 3;
    public const MAX_MONTHS_BACK = 12;

    private function __construct(
        public \DateTimeImmutable $monthFrom,
        public \DateTimeImmutable $monthTo,
    ) {
    }

    public static function at(\DateTimeImmutable $now, int $monthsBack = self::DEFAULT_MONTHS_BACK): self
    {
        if ($monthsBack < 1 || $monthsBack > self::MAX_MONTHS_BACK) {
            throw new \InvalidArgumentException(sprintf('months-back должен быть от 1 до %d.', self::MAX_MONTHS_BACK));
        }

        $currentMonth = $now->setTimezone(new \DateTimeZone(OzonRealizationPollWindow::TIMEZONE))->modify('first day of this month')->setTime(0, 0);

        return new self(
            $currentMonth->modify(sprintf('-%d months', $monthsBack)),
            $currentMonth->modify('-1 month'),
        );
    }
}
