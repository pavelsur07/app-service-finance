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
}
