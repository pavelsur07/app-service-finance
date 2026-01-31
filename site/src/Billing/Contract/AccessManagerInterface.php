<?php

declare(strict_types=1);

namespace App\Billing\Contract;

use App\Billing\Dto\LimitState;
use App\Company\Entity\Company;

interface AccessManagerInterface
{
    public function can(string $permission, ?Company $company = null): bool;

    public function denyUnlessCan(string $permission, ?Company $company = null): void;

    public function integrationEnabled(string $integrationCode, ?Company $company = null): bool;

    public function limit(string $metric, ?Company $company = null): LimitState;
}
