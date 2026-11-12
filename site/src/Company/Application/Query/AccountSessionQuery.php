<?php

declare(strict_types=1);

namespace App\Company\Application\Query;

use App\Company\Api\Response\AccountSessionResponse;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Company\Security\ModuleAccessResolver;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AccountSessionQuery
{
    public function __construct(private Security $security, private ActiveCompanyService $activeCompany, private ModuleAccessResolver $access)
    {
    }

    public function __invoke(): AccountSessionResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }
        try {
            $company = $this->activeCompany->getActiveCompany();
        } catch (NotFoundHttpException) {
            $company = null;
        }
        $permissions = [];
        foreach (Module::cases() as $module) {
            $permissions[$module->value] = null === $company ? 'none' : ($this->access->allows($module, AccessLevel::WRITE) ? 'write' : ($this->access->allows($module, AccessLevel::READ) ? 'read' : 'none'));
        }

        return new AccountSessionResponse((string) $user->getId(), (string) $user->getEmail(), null === $company ? null : ['id' => (string) $company->getId(), 'name' => (string) $company->getName()], $permissions);
    }
}
