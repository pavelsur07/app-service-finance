<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO\Template;

use App\Cash\Enum\PaymentPlan\PaymentPlanType;

/**
 * Узел шаблона ядра ДДС: группа (есть children) или статья.
 */
final readonly class CashflowCoreTemplateNode
{
    /**
     * @param list<self> $children
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public PaymentPlanType $direction,
        public int $sort,
        public array $children,
    ) {
    }
}
