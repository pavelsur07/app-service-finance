<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Contract\AccessManagerInterface;
use App\Billing\Dto\LimitState;
use App\Company\Entity\Company;

final class AccessManager implements AccessManagerInterface
{
    public function can(string $permission, ?Company $company = null): bool
    {
        return true;
    }

    public function denyUnlessCan(string $permission, ?Company $company = null): void
    {
    }

    public function integrationEnabled(string $integrationCode, ?Company $company = null): bool
    {
        return true;
    }

    public function limit(string $metric, ?Company $company = null): LimitState
    {
        return new LimitState($metric, 0, null, null, null, false, false);
    }
}
