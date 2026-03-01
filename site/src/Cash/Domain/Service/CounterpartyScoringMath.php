<?php

declare(strict_types=1);

namespace App\Cash\Domain\Service;

final class CounterpartyScoringMath
{
    /** @var int Допустимая задержка платежа в днях (банковский клиринг, выходные) */
    public const GRACE_PERIOD_DAYS = 2;

    /**
     * Вычисляет медианную задержку (исключает экстремальные выбросы форс-мажоров)
     * * @param list<int> $delays массив задержек в днях
     */
    public function calculateMedianDelay(array $delays): ?int
    {
        if (empty($delays)) {
            return null;
        }

        sort($delays, SORT_NUMERIC);
        $count = count($delays);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return (int) round(($delays[$middle - 1] + $delays[$middle]) / 2);
        }

        return $delays[$middle];
    }

    /**
     * Вычисляет индекс надежности от 0 до 100%
     * * @param list<int> $delays массив задержек в днях
     */
    public function calculateReliabilityScore(array $delays): int
    {
        if (empty($delays)) {
            return 100; // По умолчанию новый клиент считается надежным
        }

        $onTimeCount = 0;
        foreach ($delays as $delay) {
            if ($delay <= self::GRACE_PERIOD_DAYS) {
                $onTimeCount++;
            }
        }

        return (int) round(($onTimeCount / count($delays)) * 100);
    }
}
