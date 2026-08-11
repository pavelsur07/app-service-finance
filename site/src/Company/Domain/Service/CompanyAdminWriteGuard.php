<?php

declare(strict_types=1);

namespace App\Company\Domain\Service;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;

/**
 * Единственное определение «у участника есть admin:write» и единственная проверка инварианта
 * «в компании остаётся хотя бы один делегированный администратор».
 *
 * Предикат раньше жил копией в CompanyMemberController и разъехался с ModuleAccessResolver:
 * там `OPERATOR` без шаблона считался администратором, хотя резолвер такому участнику
 * отказывает во всех правах. Из-за этого участник без доступа сходил за «другого админа»,
 * и защиту последнего admin:write можно было обойти. Определение должно быть одно.
 */
final readonly class CompanyAdminWriteGuard
{
    public function __construct(
        private CompanyMemberRepository $memberRepository,
    ) {
    }

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

    /**
     * Останется ли делегированный админ после назначения участнику другого шаблона.
     */
    public function keepsAdminWriteAfterMemberChange(
        Company $company,
        CompanyMember $targetMember,
        CompanyRole $newRole,
    ): bool {
        if ($this->roleGrantsAdminWrite($newRole)) {
            return true;
        }

        foreach ($this->memberRepository->findActiveByCompany($company) as $member) {
            if ((string) $member->getId() === (string) $targetMember->getId()) {
                continue;
            }

            if ($this->memberHasAdminWrite($member, $company)) {
                return true;
            }
        }

        // Сам владелец компании административный доступ не теряет ни при каком шаблоне.
        return (string) $company->getUser()->getId() === (string) $targetMember->getUser()->getId();
    }

    /**
     * Останется ли делегированный админ после изменения прав самого шаблона.
     *
     * Снятие admin:write у шаблона применяется сразу ко всем, кому он назначен, поэтому
     * проверка нужна и здесь, а не только при переназначении участнику.
     *
     * @param array<string, string> $newPermissions
     */
    public function keepsAdminWriteAfterRoleChange(Company $company, CompanyRole $role, array $newPermissions): bool
    {
        if ($this->permissionsGrantAdminWrite($newPermissions)) {
            return true;
        }

        if (!$this->roleGrantsAdminWrite($role)) {
            // Шаблон и раньше не давал admin:write — снимать нечего.
            return true;
        }

        $roleId = (string) $role->getId();
        $affectsActiveMember = false;

        foreach ($this->memberRepository->findActiveByCompany($company) as $member) {
            $memberRole = $member->getAccessRole();
            if (null !== $memberRole && $roleId === (string) $memberRole->getId()) {
                // Этот участник теряет admin:write вместе с шаблоном.
                $affectsActiveMember = true;

                continue;
            }

            if ($this->memberHasAdminWrite($member, $company)) {
                return true;
            }
        }

        // Считаем только активных: шаблон, назначенный лишь отключённому участнику,
        // никого административного доступа не лишает.
        return !$affectsActiveMember;
    }
}
