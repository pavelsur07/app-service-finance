<?php

namespace App\Service\AutoCategory;

use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Enum\AutoTemplateDirection;

interface AutoCategorizerInterface
{
    public function resolveCashflowCategory(Company $company, array $operation, AutoTemplateDirection $direction): ?CashflowCategory;
}
