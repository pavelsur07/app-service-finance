<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Domain\OAuth;

use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Domain\OAuth\Pkce;
use PHPUnit\Framework\TestCase;

final class PkceAndTokensTest extends TestCase
{
    public function testChallengeMatchesRfc7636AppendixB(): void
    {
        // RFC 7636, Appendix B.
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function testGeneratedVerifierIsUrlSafeAndUnique(): void
    {
        $first = Pkce::generate();
        $second = Pkce::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43,128}$/', $first->verifier);
        self::assertNotSame($first->verifier, $second->verifier);
        self::assertSame(Pkce::challengeFor($first->verifier), $first->challenge);
        self::assertStringNotContainsString($first->verifier, json_encode($first->__debugInfo(), \JSON_THROW_ON_ERROR));
    }

    public function testAccessExpiresWithinWindow(): void
    {
        $now = new \DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $tokens = new OAuthTokens('a', 'r', $now->modify('+299 seconds'));

        self::assertTrue($tokens->accessExpiresWithin($now, 300));
        self::assertFalse((new OAuthTokens('a', 'r', $now->modify('+301 seconds')))->accessExpiresWithin($now, 300));
    }

    public function testFallbackRefreshTokenKeepsPreviousOnlyWhenNoneIssued(): void
    {
        $expires = new \DateTimeImmutable('2026-10-26T12:00:00+00:00');

        self::assertSame('old', (new OAuthTokens('a', null, $expires))->withFallbackRefreshToken('old')->refreshToken);
        self::assertSame('new', (new OAuthTokens('a', 'new', $expires))->withFallbackRefreshToken('old')->refreshToken);
    }

    public function testDebugOutputHasNoTokens(): void
    {
        $dump = json_encode((new OAuthTokens('access-secret', 'refresh-secret', new \DateTimeImmutable()))->__debugInfo(), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('access-secret', $dump);
        self::assertStringNotContainsString('refresh-secret', $dump);
    }
}
