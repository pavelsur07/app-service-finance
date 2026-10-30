<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Application\Service;

use App\ServiceGateway\Application\Service\AiServiceAccess;
use PHPUnit\Framework\TestCase;

final class AiServiceAccessTest extends TestCase
{
    private const COMPANY = '0192f0a0-0000-7000-8000-0000000000c1';
    private const OTHER = '0192f0a0-0000-7000-8000-0000000000c2';

    public function testDisabledByFlagUrlOrToken(): void
    {
        self::assertFalse((new AiServiceAccess(false, '*', 'http://ai', 'token'))->isEnabledForCompany(self::COMPANY));
        self::assertFalse((new AiServiceAccess(true, '*', '', 'token'))->isEnabledForCompany(self::COMPANY));
        self::assertFalse((new AiServiceAccess(true, '*', 'http://ai', null))->isEnabled());
    }

    public function testEmptyAllowlistAdmitsNobody(): void
    {
        $access = new AiServiceAccess(true, '', 'http://ai', 'token');

        self::assertTrue($access->isEnabled());
        self::assertFalse($access->isEnabledForCompany(self::COMPANY));
    }

    public function testAllowlistByIdIgnoresCaseAndSpaces(): void
    {
        $access = new AiServiceAccess(true, ' '.strtoupper(self::COMPANY).' , other', 'http://ai', 'token');

        self::assertTrue($access->isEnabledForCompany(self::COMPANY));
        self::assertFalse($access->isEnabledForCompany(self::OTHER));
    }

    public function testWildcardAdmitsAll(): void
    {
        self::assertTrue((new AiServiceAccess(true, '*', 'http://ai', 'token'))->isEnabledForCompany(self::OTHER));
    }
}
