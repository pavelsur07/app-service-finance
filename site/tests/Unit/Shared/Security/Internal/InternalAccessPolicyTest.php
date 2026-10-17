<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security\Internal;

use App\Shared\Security\Internal\InternalAccessDecision;
use App\Shared\Security\Internal\InternalAccessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InternalAccessPolicyTest extends TestCase
{
    private const TOKEN = 'unit-internal-token-0123456789abcdef';

    #[DataProvider('decisions')]
    public function testDecide(?string $token, ?string $allowedIps, ?string $header, ?string $ip, InternalAccessDecision $expected): void
    {
        self::assertSame($expected, (new InternalAccessPolicy($token, $allowedIps))->decide($header, $ip));
    }

    /** @return iterable<string, array{?string, ?string, ?string, ?string, InternalAccessDecision}> */
    public static function decisions(): iterable
    {
        $bearer = 'Bearer '.self::TOKEN;

        yield 'granted by single ip' => [self::TOKEN, '127.0.0.1', $bearer, '127.0.0.1', InternalAccessDecision::GRANTED];
        yield 'granted by cidr among several' => [self::TOKEN, ' 192.168.0.0/16 , 10.0.0.0/8 ', $bearer, '10.1.2.3', InternalAccessDecision::GRANTED];
        yield 'scheme is case-insensitive' => [self::TOKEN, '10.0.0.0/8', 'bearer '.self::TOKEN, '10.1.2.3', InternalAccessDecision::GRANTED];

        yield 'token env missing' => [null, '10.0.0.0/8', $bearer, '10.1.2.3', InternalAccessDecision::DISABLED];
        yield 'token env blank' => ['  ', '10.0.0.0/8', 'Bearer ', '10.1.2.3', InternalAccessDecision::DISABLED];
        yield 'token too short' => ['short-token', '10.0.0.0/8', 'Bearer short-token', '10.1.2.3', InternalAccessDecision::DISABLED];
        yield 'allowlist missing' => [self::TOKEN, null, $bearer, '10.1.2.3', InternalAccessDecision::DISABLED];
        yield 'allowlist only separators' => [self::TOKEN, ' , ,', $bearer, '10.1.2.3', InternalAccessDecision::DISABLED];

        yield 'no header' => [self::TOKEN, '10.0.0.0/8', null, '10.1.2.3', InternalAccessDecision::AUTH_REQUIRED];
        yield 'wrong token' => [self::TOKEN, '10.0.0.0/8', 'Bearer '.self::TOKEN.'x', '10.1.2.3', InternalAccessDecision::AUTH_REQUIRED];
        yield 'token without scheme' => [self::TOKEN, '10.0.0.0/8', self::TOKEN, '10.1.2.3', InternalAccessDecision::AUTH_REQUIRED];
        yield 'other scheme' => [self::TOKEN, '10.0.0.0/8', 'Basic '.self::TOKEN, '10.1.2.3', InternalAccessDecision::AUTH_REQUIRED];
        yield 'trailing garbage' => [self::TOKEN, '10.0.0.0/8', $bearer.' extra', '10.1.2.3', InternalAccessDecision::AUTH_REQUIRED];

        yield 'ip outside allowlist' => [self::TOKEN, '10.0.0.0/8', $bearer, '203.0.113.5', InternalAccessDecision::IP_FORBIDDEN];
        yield 'ip unknown' => [self::TOKEN, '10.0.0.0/8', $bearer, null, InternalAccessDecision::IP_FORBIDDEN];
    }

    public function testDecisionsMapToHttpStatus(): void
    {
        self::assertSame(401, InternalAccessDecision::AUTH_REQUIRED->httpStatus());
        self::assertSame(403, InternalAccessDecision::IP_FORBIDDEN->httpStatus());
        self::assertSame(403, InternalAccessDecision::DISABLED->httpStatus());
    }
}
