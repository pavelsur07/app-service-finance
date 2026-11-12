<?php

declare(strict_types=1);

namespace App\Shared\Security\Spa;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class SpaAdminUserChecker implements UserCheckerInterface
{
    public function __construct(private RoleHierarchyInterface $hierarchy)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!\in_array('ROLE_ADMIN', $this->hierarchy->getReachableRoleNames($user->getRoles()), true)) {
            throw new CustomUserMessageAccountStatusException('Доступ запрещён.');
        }
    }
}
