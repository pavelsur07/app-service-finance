<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Repository\PLCategoryRepository;

/**
 * Проверка расчётом: вклад каждой статьи ввода в каждый из итогов — тот, что задан (по шаблону). Считает тем же PlReportCalculator, что и
 * отчёт, по дереву компании в текущем состоянии базы и EntityManager: по прогону на статью с фактом 1
 * у неё одной (около двадцати прогонов при применении — дёшево по сравнению с неверными итогами).
 *
 * Калькулятор собирается здесь, а не внедряется: факты — данные проверки, а не источник отчёта.
 */
final readonly class PLContributionVerifier
{
    public function __construct(private PLCategoryRepository $categories)
    {
    }

    /**
     * @param array<string, array<string, float>> $expected статья ввода → итог → ожидаемый вклад (нет итога — 0)
     * @param list<string> $totals итоги, по которым сверяется вклад
     *
     * @return list<string> статьи, вклад которых в какой-то итог не тот
     */
    public function offenders(Company $company, array $expected, array $totals): array
    {
        // По одной статье и с точным сравнением: суммарный прогон обманывается весами (3 и 0 вместо
        // 1 и 1 дают ту же сумму), а допуск пропустил бы вес 0,9.
        $offenders = [];
        foreach ($expected as $code => $want) {
            $actual = $this->totals($company, [$code => 1.0], $totals);
            foreach ($totals as $total) {
                if (abs(($actual[$total] ?? 0.0) - ($want[$total] ?? 0.0)) > 1e-9) {
                    $offenders[] = $code;

                    break;
                }
            }
        }

        return $offenders;
    }

    /**
     * @param array<string, float> $facts
     * @param list<string> $totals
     *
     * @return array<string, float>
     */
    private function totals(Company $company, array $facts, array $totals): array
    {
        $calculator = new PlReportCalculator($this->categories, new class($facts) implements FactsProviderInterface {
            /** @param array<string, float> $facts */
            public function __construct(private array $facts)
            {
            }

            public function value(Company $company, PlReportPeriod $period, string $code, ProjectDirection|array|null $projectDirection = null, string|array|null $responsibilityCenterId = null): float
            {
                return $this->facts[$code] ?? 0.0;
            }
        });
        $period = new PlReportPeriod(new \DateTimeImmutable('2000-01-01'), new \DateTimeImmutable('2000-01-31'), 'check');
        $values = [];
        foreach ($calculator->calculate($company, $period)->rows as $row) {
            if (\in_array($row->code, $totals, true)) {
                $values[(string) $row->code] = (float) $row->rawValue;
            }
        }

        return $values;
    }
}
