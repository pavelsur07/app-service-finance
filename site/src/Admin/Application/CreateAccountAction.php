<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;

final readonly class CreateAccountAction
{
    public function __construct(
        private CompanyFacade $companyFacade,
    ) {
    }

    public function __invoke(User $account, string $plainPassword, string $companyName): Company
    {
        return $this->companyFacade->createOwnerAccount((string) $account->getEmail(), $plainPassword, $companyName);
    }
}
