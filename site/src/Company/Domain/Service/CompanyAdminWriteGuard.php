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
     *
     * Состояние читается массивами через findActiveAdminStateByCompany(): под блокировкой
     * нужен свежий снимок из БД, а объектный запрос вернул бы managed-инстансы,
     * уже загруженные предварительной UX-проверкой.
     *
     * @param array<string, string> $newPermissions
     */
    public function keepsAdminWriteAfterMemberChange(
        Company $company,
        string $targetMemberId,
        array $newPermissions,
    ): bool {
        if ($this->permissionsGrantAdminWrite($newPermissions)) {
            return true;
        }

        $rows = $this->memberRepository->findActiveAdminStateByCompany($company);
        $ownerUserId = (string) $company->getUser()->getId();

        $targetIsActiveAdmin = false;
        $anotherAdminRemains = false;

        foreach ($rows as $row) {
            $isTarget = (string) $row['memberId'] === $targetMemberId;
            $hasAdmin = $this->rowHasAdminWrite($row, $ownerUserId);

            if ($isTarget) {
                $targetIsActiveAdmin = $hasAdmin;

                continue;
            }

            if ($hasAdmin) {
                $anotherAdminRemains = true;
            }
        }

        // Участник, который и так не был активным администратором, ничего не отнимает:
        // отклонять такое назначение — значит запрещать владельцу обычные операции.
        if (!$targetIsActiveAdmin) {
            return true;
        }

        return $anotherAdminRemains;
    }

    /**
     * Останется ли делегированный админ после изменения прав самого шаблона.
     *
     * Снятие admin:write у шаблона применяется сразу ко всем, кому он назначен, поэтому
     * проверка нужна и здесь, а не только при переназначении участнику.
     *
     * @param array<string, string> $newPermissions
     */
    public function keepsAdminWriteAfterRoleChange(Company $company, string $roleId, array $newPermissions): bool
    {
        if ($this->permissionsGrantAdminWrite($newPermissions)) {
            return true;
        }

        $rows = $this->memberRepository->findActiveAdminStateByCompany($company);
        $ownerUserId = (string) $company->getUser()->getId();

        $affectsActiveAdmin = false;
        $anotherAdminRemains = false;

        foreach ($rows as $row) {
            $hasAdmin = $this->rowHasAdminWrite($row, $ownerUserId);

            if (null !== $row['roleId'] && $roleId === (string) $row['roleId']) {
                // Этот участник теряет права вместе с изменением шаблона.
                $affectsActiveAdmin = $affectsActiveAdmin || $hasAdmin;

                continue;
            }

            if ($hasAdmin) {
                $anotherAdminRemains = true;
            }
        }

        // Шаблон, который активным администратором никого не делает, снимать безопасно.
        if (!$affectsActiveAdmin) {
            return true;
        }

        return $anotherAdminRemains;
    }

    /**
     * @param array{memberId: string, userId: string, memberRole: string, roleId: ?string, permissions: ?array<string, string>} $row
     */
    private function rowHasAdminWrite(array $row, string $ownerUserId): bool
    {
        if ((string) $row['userId'] === $ownerUserId) {
            return false;
        }

        if (CompanyMember::ROLE_OWNER === $row['memberRole']) {
            return true;
        }

        return $this->permissionsGrantAdminWrite($row['permissions'] ?? []);
    }
}
