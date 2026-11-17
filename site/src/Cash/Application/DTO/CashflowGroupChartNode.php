<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\PaymentPlan\PaymentPlanType;

/**
 * Узел эталона группы. parentKey — код родителя либо `sys:<systemCode>` системной группы.
 */
final readonly class CashflowGroupChartNode
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public ?PaymentPlanType $direction,
        public int $sort,
        public string $parentKey,
    ) {
    }
}
