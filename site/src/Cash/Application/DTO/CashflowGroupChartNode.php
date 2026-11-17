<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashflowFlowKind;

/**
 * Узел эталона группы. parentKey — код родителя, `sys:<systemCode>` системной группы или
 * пустая строка у обычной корневой статьи (её flowKind переносится при создании).
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
        public ?CashflowFlowKind $flowKind = null,
    ) {
    }
}
