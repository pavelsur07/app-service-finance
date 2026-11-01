<?php

declare(strict_types=1);

namespace App\Billing\Facade;

use App\Billing\Facade\DTO\BillingAccessDTO;

/**
 * Биллинг для других модулей.
 */
interface BillingFacadeInterface
{
    /**
     * Тариф и статус доступа компании на момент `$now` (дата берётся по Москве).
     */
    public function accessFor(string $companyId, \DateTimeImmutable $now): BillingAccessDTO;

    /**
     * Доступ нескольких кабинетов одним запросом; ключ — companyId, каждый переданный ID в ответе есть.
     *
     * @param list<string> $companyIds
     *
     * @return array<string, BillingAccessDTO>
     */
    public function accessForCompanies(array $companyIds, \DateTimeImmutable $now): array;
}
