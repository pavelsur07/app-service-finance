<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Domain\ApiKeyName;
use App\Api\Domain\ApiKeySecret;
use App\Api\Exception\InvalidApiKeyNameException;
use App\Tests\Builders\Api\ApiKeyBuilder;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testExpiryIsExactlyNinetyUtcDaysAcrossDstAndBoundaryIsExclusive(): void
    {
        $now = new \DateTimeImmutable('2026-03-01 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $key = ApiKeyBuilder::aKey()->createdAt($now)->build();
        self::assertSame(90 * 86400, $key->getExpiresAt()->getTimestamp() - $now->getTimestamp());
        self::assertSame('UTC', $key->getExpiresAt()->getTimezone()->getName());
        self::assertTrue($key->isUsableAt($key->getExpiresAt()->modify('-1 second')));
        self::assertFalse($key->isUsableAt($key->getExpiresAt()));
        self::assertSame(7, \Ramsey\Uuid\Uuid::fromString($key->getId())->getVersion());
    }

    public function testRenameAndIdempotentRevokeNeverExtendExpiry(): void
    {
        $key = ApiKeyBuilder::aKey()->build();
        $expiry = $key->getExpiresAt();
        $key->rename('  New name  ');
        self::assertSame('New name', $key->getName());
        $revokedAt = $key->getCreatedAt()->modify('+1 day');
        $key->revoke($revokedAt);
        $key->revoke($revokedAt->modify('+1 day'));
        self::assertSame($revokedAt, $key->getRevokedAt());
        self::assertSame($expiry, $key->getExpiresAt());
        self::assertFalse($key->isUsableAt($revokedAt));
    }

    public function testSecretsAreRandomAndOnlyHashVerifies(): void
    {
        $first = ApiKeySecret::generate();
        $second = ApiKeySecret::generate();
        self::assertNotSame($first, $second);
        $token = ApiKeySecret::format($first['publicIdentifier'], $first['secret']);
        self::assertMatchesRegularExpression('/^vfd_[a-f0-9]{32}\.[a-f0-9]{64}$/D', $token);
        self::assertSame($first, ApiKeySecret::parse($token));
        $key = ApiKeyBuilder::aKey()->withSecret($first['secret'])->build();
        self::assertTrue($key->matchesSecret($first['secret']));
        self::assertFalse($key->matchesSecret($second['secret']));
        self::assertFalse($key->matchesSecret($token));
        self::assertStringNotContainsString($first['secret'], serialize($key));
        self::assertSame('{}', json_encode($key));
        foreach (['', $token."\n", strtoupper($token), 'Bearer '.$token, 'vfd_invalid.secret'] as $invalid) {
            self::assertNull(ApiKeySecret::parse($invalid));
        }
    }

    public function testConstructorRejectsInvalidCompanyUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiKeyBuilder::aKey()->withCompanyId('invalid')->build();
    }

    public function testDebugOutputExcludesHashAndTimesMatchDatabasePrecision(): void
    {
        $key = ApiKeyBuilder::aKey()->withSecret('test-only-debug-secret')->createdAt(new \DateTimeImmutable('2026-09-13T10:00:00.123456Z'))->build();
        self::assertStringNotContainsString(ApiKeySecret::hash('test-only-debug-secret'), (string) json_encode($key->__debugInfo()));
        self::assertSame('000000', $key->getCreatedAt()->format('u'));
        self::assertSame('000000', $key->getExpiresAt()->format('u'));
    }

    public function testNameTrimsAndAcceptsUnicodeLimit(): void
    {
        self::assertSame(str_repeat('Я', 255), ApiKeyName::normalize(' '.str_repeat('Я', 255).' '));
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        ApiKeyName::normalize('  ');
    }

    public function testLongNameIsRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        ApiKeyName::normalize(str_repeat('Я', 256));
    }
}
