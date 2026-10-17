<?php

declare(strict_types=1);

namespace App\Finance\Facts;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Report\PlReportPeriod;

/**
 * Факты статей ввода, посчитанные заранее, — для предпросмотра ОПиУ без записи в регистр.
 * Компания, период и разрезы уже учтены тем, кто собрал значения, поэтому здесь не смотрятся.
 * Код сравнивается без учёта регистра, как в PlReportCalculator.
 */
final readonly class InMemoryFactsProvider implements FactsProviderInterface
{
    /** @var array<string, float> */
    private array $valuesByCode;

    /**
     * @param array<string, float> $valuesByCode
     */
    public function __construct(array $valuesByCode)
    {
        $normalized = [];
        foreach ($valuesByCode as $code => $value) {
            $key = strtoupper(trim((string) $code));
            $normalized[$key] = ($normalized[$key] ?? 0.0) + $value;
        }
        $this->valuesByCode = $normalized;
    }

    public function value(
        Company $company,
        PlReportPeriod $period,
        string $code,
        ProjectDirection|array|null $projectDirection = null,
        string|array|null $responsibilityCenterId = null,
    ): float {
        return $this->valuesByCode[strtoupper(trim($code))] ?? 0.0;
    }
}
