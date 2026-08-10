<?php

declare(strict_types=1);

namespace App\Company\Security;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Разрешает доступ текущего пользователя к модулям активной компании.
 * Результат мемоизируется на запрос с инвалидацией по паре (пользователь, активная компания) —
 * тем же ключом, что мемоизация ActiveCompanyService.
 */
final class ModuleAccessResolver implements ResetInterface
{
    /** @var array<string, AccessLevel>|null */
    private ?array $levels = null;
    private ?User $levelsUser = null;
    private ?string $levelsCompanyId = null;

    public function __construct(
        private readonly Security $security,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function allows(Module $module, AccessLevel $required): bool
    {
        // NONE — отсутствие права, а не запрашиваемый уровень: allows($module, NONE) никому не грантит.
        if (AccessLevel::NONE === $required) {
            return false;
        }

        $level = $this->resolveLevels()[$module->value] ?? AccessLevel::NONE;

        return $level->atLeast($required);
    }

    public function reset(): void
    {
        $this->levels = null;
        $this->levelsUser = null;
        $this->levelsCompanyId = null;
    }

    /**
     * @return array<string, AccessLevel>
     */
    private function resolveLevels(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            // Failure-путь: уровни НЕ кэшируем, чтобы при появлении пользователя/компании
            // в этом же запросе не получить spurious deny из устаревшего кэша.
            return [];
        }

        try {
            $company = $this->activeCompanyService->getActiveCompany();
        } catch (NotFoundHttpException|SessionNotFoundException) {
            return [];
        }

        $companyId = (string) $company->getId();
        if (null !== $this->levels && $this->levelsUser === $user && $this->levelsCompanyId === $companyId) {
            return $this->levels;
        }

        $levels = $this->computeLevels($user, $company);

        $this->levelsUser = $user;
        $this->levelsCompanyId = $companyId;

        return $this->levels = $levels;
    }

    /**
     * @return array<string, AccessLevel>
     */
    private function computeLevels(User $user, Company $company): array
    {
        // Владелец компании — полный доступ независимо от членства и шаблона.
        // Сравнение по id, а не по идентичности объектов (разные инстансы одного User).
        $owner = $company->getUser();
        if (null !== $owner && null !== $owner->getId() && $owner->getId() === $user->getId()) {
            return $this->allModules(AccessLevel::WRITE);
        }

        $membership = $this->activeCompanyService->getActiveMembership();
        if (null === $membership) {
            return [];
        }

        if (CompanyMember::ROLE_OWNER === $membership->getRole()) {
            return $this->allModules(AccessLevel::WRITE);
        }

        $accessRole = $membership->getAccessRole();
        if (null !== $accessRole) {
            // Шаблон чужой компании не применяем (защита от ошибочного назначения).
            $roleCompany = $accessRole->getCompany();
            if (null !== $roleCompany && (string) $roleCompany->getId() !== (string) $company->getId()) {
                $this->logger->warning('Module access: access role belongs to another company, denying.', [
                    'roleCompanyId' => (string) $roleCompany->getId(),
                    'activeCompanyId' => (string) $company->getId(),
                ]);

                return [];
            }

            return $this->levelsFromRole($accessRole->getPermissions());
        }

        // BC до полной миграции на шаблоны: участники без accessRole
        // сохраняют прежний доступ по строковой роли (OWNER/OPERATOR — полный).
        if (\in_array($membership->getRole(), [CompanyMember::ROLE_OWNER, CompanyMember::ROLE_OPERATOR], true)) {
            return $this->allModules(AccessLevel::WRITE);
        }

        return [];
    }

    /**
     * @param array<string, string> $permissions
     *
     * @return array<string, AccessLevel>
     */
    private function levelsFromRole(array $permissions): array
    {
        $levels = [];
        foreach ($permissions as $module => $level) {
            $parsed = AccessLevel::tryFrom((string) $level);
            if (null === $parsed) {
                $this->logger->warning('Module access: unknown access level in role permissions, treated as none.', [
                    'module' => (string) $module,
                    'level' => (string) $level,
                ]);
                $parsed = AccessLevel::NONE;
            }
            $levels[$module] = $parsed;
        }

        return $levels;
    }

    /**
     * @return array<string, AccessLevel>
     */
    private function allModules(AccessLevel $level): array
    {
        $levels = [];
        foreach (Module::cases() as $module) {
            $levels[$module->value] = $level;
        }

        return $levels;
    }
}
