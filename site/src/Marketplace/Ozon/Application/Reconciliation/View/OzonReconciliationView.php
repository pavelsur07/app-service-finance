<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\View;

use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;

/**
 * Готовая к показу сверка за месяц. Суммы — decimal-строки (`1234.50`), разница знаковая «цель − источник».
 */
final readonly class OzonReconciliationView
{
    /**
     * @param list<OzonReconciliationCheckView> $checks
     */
    public function __construct(
        public string $monthValue,
        public string $monthLabel,
        public OzonReconciliationStatus $status,
        public int $mismatchCount,
        public \DateTimeImmutable $checkedAt,
        public int $rawDaysPresent,
        public int $rawDaysExpected,
        public bool $realizationPresent,
        public string $outsideRawCosts,
        public array $checks,
    ) {
    }

    public function check(OzonReconciliationCheck $check): ?OzonReconciliationCheckView
    {
        foreach ($this->checks as $view) {
            if ($view->check === $check) {
                return $view;
            }
        }

        return null;
    }
}
