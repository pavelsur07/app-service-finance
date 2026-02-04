<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AuditContextProvider
{
    public function __construct(
        private readonly Security $security,
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    public function getActorUserId(): ?string
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        return $user->getId();
    }

    public function getCompanyId(): ?string
    {
        try {
            $company = $this->activeCompanyService->getActiveCompany();
        } catch (NotFoundHttpException) {
            return null;
        }

        return $company->getId();
    }
}
