<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Security\ApiPrincipal;
use PHPUnit\Framework\TestCase;

final class ApiPrincipalTest extends TestCase
{
    public function testPrincipalContainsNoUserIdentityOrSecret(): void
    {
        $principal = new ApiPrincipal('company-uuid', 100025, 'key-uuid', new \DateTimeImmutable('2026-12-01T00:00:00Z'));
        self::assertSame(['ROLE_EXTERNAL_API'], $principal->getRoles());
        self::assertSame('api-key:key-uuid', $principal->getUserIdentifier());
        self::assertSame('company-uuid', $principal->companyId);
        self::assertSame([
            'company_id' => 100025,
            'key_id' => 'key-uuid',
            'expires_at' => '2026-12-01T00:00:00+00:00',
            'prepared_scopes' => [],
            'effective_scopes' => [],
        ], $principal->connectionDetails());
    }
}
