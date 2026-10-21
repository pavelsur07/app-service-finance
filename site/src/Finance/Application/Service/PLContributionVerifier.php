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
 * Проверка расчётом: каждая статья ввода из `$counted` входит в чистую прибыль (NET_PROFIT) ровно
 * один раз с весом 1, статьи из `$excluded` — ни разу. Считает тем же PlReportCalculator, что и
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
     * @param list<string> $counted
     * @param list<string> $excluded
     *
     * @return list<string> коды, вклад которых не тот
     */
    public function offenders(Company $company, array $counted, array $excluded): array
    {
        // По одной статье и с точным сравнением: суммарный прогон обманывается весами (3 и 0 вместо
        // 1 и 1 дают ту же сумму), а допуск пропустил бы вес 0,9.
        $offenders = [];
        foreach ([...array_map(static fn (string $c): array => [$c, 1.0], $counted), ...array_map(static fn (string $c): array => [$c, 0.0], $excluded)] as [$code, $want]) {
            if (abs($this->netProfit($company, [$code => 1.0]) - $want) > 1e-9) {
                $offenders[] = $code;
            }
        }

        return $offenders;
    }

    /**
     * @param array<string, float> $facts
     */
    private function netProfit(Company $company, array $facts): float
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
        foreach ($calculator->calculate($company, $period)->rows as $row) {
            if ('NET_PROFIT' === $row->code) {
                return (float) $row->rawValue;
            }
        }

        return 0.0;
    }
}
