<?php

namespace App\Service;

use App\Entity\Company;

class CompanyContextService
{
    public function __construct(private readonly ActiveCompanyService $activeCompanyService)
    {
    }

    public function getCompany(): Company
    {
        return $this->activeCompanyService->getActiveCompany();
    }
}
