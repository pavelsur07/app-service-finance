<?php

declare(strict_types=1);

namespace App\Company\Security;

/**
 * Атрибуты авторизации вида module.<group>.<level> для IsGranted и ModuleAccessVoter.
 */
final class ModuleAccess
{
    public const FINANCE_READ = 'module.finance.read';
    public const FINANCE_WRITE = 'module.finance.write';
    public const MARKETPLACE_READ = 'module.marketplace.read';
    public const MARKETPLACE_WRITE = 'module.marketplace.write';
    public const DEALS_READ = 'module.deals.read';
    public const DEALS_WRITE = 'module.deals.write';
    public const CATALOG_READ = 'module.catalog.read';
    public const CATALOG_WRITE = 'module.catalog.write';
    public const ADMIN_READ = 'module.admin.read';
    public const ADMIN_WRITE = 'module.admin.write';

    private const PATTERN = '/^module\.([a-z]+)\.(read|write)$/';

    public static function isModuleAttribute(string $attribute): bool
    {
        return null !== self::parse($attribute);
    }

    /**
     * @return array{0: Module, 1: AccessLevel}|null
     */
    public static function parse(string $attribute): ?array
    {
        if (1 !== preg_match(self::PATTERN, $attribute, $matches)) {
            return null;
        }

        $module = Module::tryFrom($matches[1]);
        $level = AccessLevel::tryFrom($matches[2]);

        if (null === $module || null === $level) {
            return null;
        }

        return [$module, $level];
    }
}
