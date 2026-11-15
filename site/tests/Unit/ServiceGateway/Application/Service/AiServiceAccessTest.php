<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Application\Service;

use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class AiServiceAccessTest extends TestCase
{
    private const COMPANY = '0192f0a0-0000-7000-8000-0000000000c1';
    private const OTHER = '0192f0a0-0000-7000-8000-0000000000c2';

    public function testConfiguredServiceWorksWithoutRolloutFlag(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchFirstColumn')->willReturnCallback(
            static fn (string $sql, array $params): array => array_values(array_intersect($params['ids'], [self::COMPANY])),
        );
        $access = new AiServiceAccess('http://ai', 'token', $db);

        self::assertTrue($access->isEnabledForCompany(self::COMPANY));
        self::assertFalse($access->isEnabledForCompany(self::OTHER));
    }

    public function testDisabledByUrlOrToken(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchFirstColumn')->willReturn([self::COMPANY]);

        self::assertFalse((new AiServiceAccess('', 'token', $db))->isEnabledForCompany(self::COMPANY));
        self::assertFalse((new AiServiceAccess('http://ai', null, $db))->isEnabled());
        // Админка видит подключение и при выключенном сервисе.
        self::assertSame([self::COMPANY], (new AiServiceAccess('', 'token', $db))->grantedCompanyIds([self::COMPANY]));
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

    public function testPlatformNeedsOnlyServiceConfiguration(): void
    {
        $platform = AiTaskFacadeInterface::PLATFORM_COMPANY_ID;

        self::assertTrue($this->access([])->isEnabledForCompany($platform), 'платформа не подключается администратором');
        self::assertSame([self::COMPANY, $platform], $this->access([self::COMPANY])->enabledCompanyIds([$platform, self::COMPANY]));
        self::assertFalse((new AiServiceAccess('', 'token', $this->createStub(Connection::class)))->isEnabledForCompany($platform));
    }

    /** @param list<string> $granted */
    private function access(array $granted): AiServiceAccess
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchFirstColumn')->willReturnCallback(
            static fn (string $sql, array $params): array => array_values(array_intersect($params['ids'], $granted)),
        );

        return new AiServiceAccess('http://ai', 'token', $db);
    }
}
