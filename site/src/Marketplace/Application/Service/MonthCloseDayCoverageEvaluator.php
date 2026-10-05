<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\MonthCloseDayViolation;
use App\Marketplace\Enum\FinancialReportSyncStatus;

/**
 * Чистая политика: какие из ожидаемых дней не готовы к закрытию месяца.
 *
 * Без обращения к БД и часам — вход целиком в аргументах, поэтому покрывается юнит-тестами.
 *
 * Правила для одного ожидаемого дня:
 *  - статус «в процессе» (queued/loading/raw_loaded/processing) — всегда нарушение: закрытие
 *    не должно гоняться с обработкой, а завершённый старый документ этого не отменяет;
 *  - статус «готов» (success/empty) — норма;
 *  - failed или отсутствие строки статуса — нарушение, КРОМЕ дня, покрытого завершённым
 *    легаси-документом за период (источник данных до подневной загрузки; статус failed за те
 *    даты — это 429 подневного загрузчика). failed_final, auth_failed и conflict легаси-документом
 *    не снимаются: это отказ API или конфликт новой загрузки, а не нехватка попыток.
 */
final class MonthCloseDayCoverageEvaluator
{
    /**
     * @param list<\DateTimeImmutable> $expectedDays
     * @param array<string, FinancialReportSyncStatus> $statusByDay ключ Y-m-d
     * @param list<array{0: string, 1: string}> $legacyCoverage [from, to] завершённых легаси-документов, Y-m-d
     *
     * @return list<MonthCloseDayViolation>
     */
    public function evaluate(array $expectedDays, array $statusByDay, array $legacyCoverage): array
    {
        $violations = [];

        foreach ($expectedDays as $day) {
            $key = $day->format('Y-m-d');
            $status = $statusByDay[$key] ?? null;

            if (null !== $status && $status->isReadyForMonthClose()) {
                continue;
            }

            if (null !== $status && $status->isInProgress()) {
                $violations[] = new MonthCloseDayViolation($key, MonthCloseDayViolation::STATE_IN_PROGRESS, $status->value);

                continue;
            }

            $rescuableByLegacy = null === $status || FinancialReportSyncStatus::FAILED === $status;
            if ($rescuableByLegacy && $this->coveredByLegacy($key, $legacyCoverage)) {
                continue;
            }

            $violations[] = new MonthCloseDayViolation(
                $key,
                null === $status ? MonthCloseDayViolation::STATE_MISSING : MonthCloseDayViolation::STATE_FAILED,
                $status?->value,
            );
        }

        return $violations;
    }

    /**
     * @param list<array{0: string, 1: string}> $legacyCoverage
     */
    private function coveredByLegacy(string $day, array $legacyCoverage): bool
    {
        foreach ($legacyCoverage as [$from, $to]) {
            if ($from <= $day && $day <= $to) {
                return true;
            }
        }

        return false;
    }
}
