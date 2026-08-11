<?php

declare(strict_types=1);

namespace App\Company\Domain\Service;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;

/**
 * Единственное определение «у участника есть admin:write».
 *
 * Предикат раньше жил копией в CompanyMemberController и разъехался с ModuleAccessResolver:
 * там `OPERATOR` без шаблона считался администратором, хотя резолвер такому участнику
 * отказывает во всех правах. Из-за этого участник без доступа сходил за «другого админа»,
 * и защиту последнего admin:write можно было обойти. Определение должно быть одно.
 */
final class CompanyAdminWriteGuard
{
    public function roleGrantsAdminWrite(?CompanyRole $role): bool
    {
        if (!$role instanceof CompanyRole) {
            return false;
        }

        return $this->permissionsGrantAdminWrite($role->getPermissions());
    }

    /**
     * @param array<string, string> $permissions
     */
    public function permissionsGrantAdminWrite(array $permissions): bool
    {
        return AccessLevel::WRITE->value === ($permissions[Module::ADMIN->value] ?? AccessLevel::NONE->value);
    }

    /**
     * Считается ли участник носителем административного доступа.
     *
     * Владелец компании исключён намеренно: у него доступ безусловный, его нельзя лишить
     * административных прав через шаблон, поэтому он не участвует в проверке «остался ли
     * ещё один админ».
     */
    public function memberHasAdminWrite(CompanyMember $member, Company $company): bool
    {
        if ((string) $member->getUser()->getId() === (string) $company->getUser()->getId()) {
            return false;
        }

        if (CompanyMember::ROLE_OWNER === $member->getRole()) {
            return true;
        }

        // Нет шаблона — нет прав: то же правило, что в ModuleAccessResolver.
        return $this->roleGrantsAdminWrite($member->getAccessRole());
    }
}
