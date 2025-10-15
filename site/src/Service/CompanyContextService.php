<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;

final class CompanyContextService
{
    public function __construct(private ActiveCompanyService $activeCompanyService)
    {
    }

    public function getActiveCompanyOrThrow(): Company
    {
        return $this->activeCompanyService->getActiveCompany();
    }
}
