<?php

declare(strict_types=1);

namespace App\Balance\Security;

use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BalanceAccess
{
    public function __construct(private readonly Security $security, private readonly CompanyFacade $companies, private readonly Connection $db, private readonly ActiveCompanyService $activeCompany)
    {
    }

    public function actor(string $companyId, string $permission = 'read'): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            throw new AccessDeniedException();
        }
        $this->require($companyId, $user->getId(), $permission);

        return $user->getId();
    }

    /** @return array{manage:bool,prepare:bool,post:bool,manage_periods:bool,reopen_periods:bool} */
    public function permissions(string $companyId): array
    {
        $actor = $this->actor($companyId, 'read');
        $rights = $this->companies->financialAccess($companyId, $actor);
        $none = ['manage' => false, 'prepare' => false, 'post' => false, 'manage_periods' => false, 'reopen_periods' => false];
        if (!$rights['write']) {
            return $none;
        }
        if ($rights['owner']) {
            return array_fill_keys(array_keys($none), true);
        }
        $grant = $this->db->fetchAssociative('SELECT can_prepare,can_post,can_manage_periods,can_reopen_periods FROM balance_access_grants WHERE company_id=? AND user_id=?', [$companyId, $actor]);
        if (false === $grant) {
            return $none;
        }

        return ['manage' => false, 'prepare' => (bool) $grant['can_prepare'], 'post' => (bool) $grant['can_post'], 'manage_periods' => (bool) $grant['can_manage_periods'], 'reopen_periods' => (bool) $grant['can_reopen_periods']];
    }

    /** Must be called inside the mutation transaction, before taking its book lock. */
    public function lockForMutation(string $companyId, string $actorId, string $permission): void
    {
        $this->require($companyId, $actorId, $permission);
        $rights = $this->companies->financialAccess($companyId, $actorId, true);
        $this->assertPermission($companyId, $actorId, $permission, $rights);
    }

    public function require(string $companyId, string $actorId, string $permission): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || $user->getId() !== $actorId || $this->activeCompany->getActiveCompany()->getId() !== $companyId) {
            throw new AccessDeniedException('Недостаточно прав для учета баланса.');
        }
        $this->assertPermission($companyId, $actorId, $permission, $this->companies->financialAccess($companyId, $actorId));
    }

    /** @param array{owner:bool,read:bool,write:bool} $rights */
    private function assertPermission(string $companyId, string $actorId, string $permission, array $rights): void
    {
        if (!in_array($permission, ['read', 'prepare', 'post', 'manage_periods', 'reopen_periods', 'manage'], true) || !$rights['read'] || ('read' !== $permission && !$rights['write'])) {
            throw new AccessDeniedException('Недостаточно прав для учета баланса.');
        }
        if ('read' === $permission || $rights['owner']) {
            return;
        }
        $column = match ($permission) {
            'prepare' => 'can_prepare','post' => 'can_post','manage_periods' => 'can_manage_periods','reopen_periods' => 'can_reopen_periods',default => null
        };
        if (null === $column || !$this->db->fetchOne('SELECT '.$column.' FROM balance_access_grants WHERE company_id=? AND user_id=?', [$companyId, $actorId])) {
            throw new AccessDeniedException('Действие не разрешено для пользователя.');
        }
    }
}
