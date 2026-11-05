<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Application\Service;

use App\ServiceGateway\Application\Service\AiServiceAccess;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class AiServiceAccessTest extends TestCase
{
    private const COMPANY = '0192f0a0-0000-7000-8000-0000000000c1';
    private const OTHER = '0192f0a0-0000-7000-8000-0000000000c2';

    public function testDisabledByFlagUrlOrToken(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchFirstColumn')->willReturn([self::COMPANY]);

        self::assertFalse((new AiServiceAccess(false, 'http://ai', 'token', $db))->isEnabledForCompany(self::COMPANY));
        self::assertFalse((new AiServiceAccess(true, '', 'token', $db))->isEnabledForCompany(self::COMPANY));
        self::assertFalse((new AiServiceAccess(true, 'http://ai', null, $db))->isEnabled());
        // Админка видит подключение и при выключенном сервисе.
        self::assertSame([self::COMPANY], (new AiServiceAccess(false, 'http://ai', 'token', $db))->grantedCompanyIds([self::COMPANY]));
    }

    public function testNoGrantAdmitsNobody(): void
    {
        $access = $this->access([]);

        self::assertTrue($access->isEnabled());
        self::assertFalse($access->isEnabledForCompany(self::COMPANY));
    }

    public function testGrantedCompanyOnlyIgnoringCase(): void
    {
        $access = $this->access([self::COMPANY]);

        self::assertTrue($access->isEnabledForCompany(strtoupper(self::COMPANY)));
        self::assertFalse($access->isEnabledForCompany(self::OTHER));
        self::assertSame([self::COMPANY], $access->enabledCompanyIds([self::OTHER, self::COMPANY]));
    }

    /** @param list<string> $granted */
    private function access(array $granted): AiServiceAccess
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchFirstColumn')->willReturnCallback(
            static fn (string $sql, array $params): array => array_values(array_intersect($params['ids'], $granted)),
        );

        return new AiServiceAccess(true, 'http://ai', 'token', $db);
    }
}
