<?php

namespace App\Shared\Service;

use App\Company\Entity\Company;

class CompanyContextService
{
    public function __construct(private ActiveCompanyService $activeCompanyService)
    {
    }

    public function getCompany(): Company
    {
        return $this->activeCompanyService->getActiveCompany();
    }

    public function getCompanyId(): string
    {
        return $this->getCompany()->getId();
    }
}
