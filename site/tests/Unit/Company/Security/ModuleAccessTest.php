<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Security;

use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Company\Security\ModuleAccess;
use PHPUnit\Framework\TestCase;

final class ModuleAccessTest extends TestCase
{
    public function testParseValidAttribute(): void
    {
        self::assertSame(
            [Module::FINANCE, AccessLevel::READ],
            ModuleAccess::parse(ModuleAccess::FINANCE_READ),
        );
        self::assertSame(
            [Module::MARKETPLACE, AccessLevel::WRITE],
            ModuleAccess::parse(ModuleAccess::MARKETPLACE_WRITE),
        );
        self::assertSame(
            [Module::ADMIN, AccessLevel::WRITE],
            ModuleAccess::parse(ModuleAccess::ADMIN_WRITE),
        );
    }

    public function testParseRejectsUnknownModuleAndLevel(): void
    {
        self::assertNull(ModuleAccess::parse('module.unknown.read'));
        self::assertNull(ModuleAccess::parse('module.finance.execute'));
        self::assertNull(ModuleAccess::parse('module.finance'));
        self::assertNull(ModuleAccess::parse('ROLE_USER'));
        self::assertNull(ModuleAccess::parse(''));
    }

    public function testIsModuleAttribute(): void
    {
        self::assertTrue(ModuleAccess::isModuleAttribute(ModuleAccess::CATALOG_READ));
        self::assertFalse(ModuleAccess::isModuleAttribute('module.unknown.read'));
        self::assertFalse(ModuleAccess::isModuleAttribute('ROLE_COMPANY_OWNER'));
    }

    public function testConstantsFollowNamingConvention(): void
    {
        foreach (Module::cases() as $module) {
            self::assertSame(
                [Module::tryFrom($module->value), AccessLevel::READ],
                ModuleAccess::parse(sprintf('module.%s.read', $module->value)),
            );
        }
    }
}
