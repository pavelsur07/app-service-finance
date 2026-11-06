<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/**
 * Лимит и расход ИИ компании за текущий месяц (₽), общий лимит всех компаний, окно выгрузки.
 */
final readonly class AiCompanyBudget
{
    public function __construct(
        public float $limitRub,
        public float $spentRub,
        public float $totalLimitRub,
        public float $totalSpentRub,
        public int $lookbackDays,
    ) {
    }

    public function companyExceeded(): bool
    {
        return $this->spentRub >= $this->limitRub;
    }

    public function totalExceeded(): bool
    {
        return $this->totalSpentRub >= $this->totalLimitRub;
    }

    public function exceeded(): bool
    {
        return $this->companyExceeded() || $this->totalExceeded();
    }
}
