<?php

declare(strict_types=1);

namespace App\Company\Security;

/**
 * Системные шаблоны ролей (company_id IS NULL в company_role).
 * UUID фиксированы: миграция Version20260808120000 вставляет те же значения.
 */
final class SystemCompanyRoles
{
    public const OWNER_ID = '00000000-0000-4000-8000-000000000001';
    public const FULL_ACCESS_ID = '00000000-0000-4000-8000-000000000002';
    public const FINANCE_ID = '00000000-0000-4000-8000-000000000003';
    public const MARKETPLACE_ID = '00000000-0000-4000-8000-000000000004';
    public const SALES_ID = '00000000-0000-4000-8000-000000000005';

    /**
     * @return array<string, array{name: string, permissions: array<string, string>}>
     */
    public static function definitions(): array
    {
        $allWrite = self::allModules(AccessLevel::WRITE);

        return [
            self::OWNER_ID => [
                'name' => 'Владелец',
                'permissions' => $allWrite,
            ],
            self::FULL_ACCESS_ID => [
                'name' => 'Полный доступ',
                'permissions' => $allWrite,
            ],
            self::FINANCE_ID => [
                'name' => 'Финансист',
                'permissions' => [
                    Module::FINANCE->value => AccessLevel::WRITE->value,
                    Module::CATALOG->value => AccessLevel::READ->value,
                ],
            ],
            self::MARKETPLACE_ID => [
                'name' => 'Менеджер маркетплейсов',
                'permissions' => [
                    Module::MARKETPLACE->value => AccessLevel::WRITE->value,
                    Module::CATALOG->value => AccessLevel::READ->value,
                ],
            ],
            self::SALES_ID => [
                'name' => 'Менеджер по продажам',
                'permissions' => [
                    Module::DEALS->value => AccessLevel::WRITE->value,
                    Module::CATALOG->value => AccessLevel::READ->value,
                    Module::MARKETPLACE->value => AccessLevel::READ->value,
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function allModules(AccessLevel $level): array
    {
        $permissions = [];
        foreach (Module::cases() as $module) {
            $permissions[$module->value] = $level->value;
        }

        return $permissions;
    }
}
