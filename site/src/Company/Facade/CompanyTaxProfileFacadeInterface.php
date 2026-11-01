<?php

declare(strict_types=1);

namespace App\Company\Facade;

use App\Company\Facade\DTO\CompanyTaxProfileDTO;

/**
 * Налоговый профиль компании для других модулей.
 */
interface CompanyTaxProfileFacadeInterface
{
    /**
     * Профиль, действующий на календарную дату (в часовом поясе переданной даты);
     * null — режим не задан.
     */
    public function taxProfileOn(string $companyId, \DateTimeImmutable $date): ?CompanyTaxProfileDTO;
}
