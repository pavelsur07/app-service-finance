<?php

declare(strict_types=1);

namespace App\Api\Application\Service;

use App\Api\Exception\ApiKeyOwnerRequiredException;
use App\Company\Facade\CompanyFacade;

final readonly class ApiOwnerGuard
{
    public function __construct(private CompanyFacade $companies)
    {
    }

    public function assertOwner(string $companyId, string $userId): void
    {
        if (!$this->companies->isOwner($companyId, $userId)) {
            throw new ApiKeyOwnerRequiredException();
        }
    }
}
