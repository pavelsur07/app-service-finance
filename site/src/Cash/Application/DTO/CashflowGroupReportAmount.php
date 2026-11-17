<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/** Сумма строк разбивки компании группы по статье, валюте и ИНН контрагента; со знаком направления. */
final readonly class CashflowGroupReportAmount
{
    public function __construct(
        public string $companyId,
        public string $categoryId,
        public string $currency,
        public ?string $counterpartyInn,
        public string $amount,
    ) {
    }
}
