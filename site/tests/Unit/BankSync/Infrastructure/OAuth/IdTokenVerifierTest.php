<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\OAuth;

use App\BankSync\Exception\BankIdTokenInvalidException;
use App\BankSync\Infrastructure\OAuth\IdTokenVerifier;
use App\Tests\Support\BankSync\TestIdTokenSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class IdTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = '0cee0683-85ae-49f2-a63d-29f97aad1911';
    private const ISSUERS = ['https://id.alfabank.ru/oidc', 'https://id.alfabank.ru'];
    private const NONCE = 'n-0S6_WzA2Mj';
    private const SUBJECT = '3228e68e-cc34-4b82-b43d-49112b5d9d3e';
    private const NOW = 1_800_000_000;

    private static ?TestIdTokenSigner $signer = null;
    private static ?TestIdTokenSigner $otherSigner = null;

    public function testValidTokenReturnsSubject(): void
    {
        self::assertSame(self::SUBJECT, $this->verify(self::signer()->sign(self::claims())));
    }

    public function testAudienceMayBeListAndIssuerEitherDocumentedForm(): void
    {
        $token = self::signer()->sign(self::claims(['aud' => ['other-service', self::CLIENT_ID], 'iss' => 'https://id.alfabank.ru']));

        self::assertSame(self::SUBJECT, $this->verify($token));
    }

    public function testAuthorizedPartyIsOptional(): void
    {
        $claims = self::claims();
        unset($claims['azp']);

        self::assertSame(self::SUBJECT, $this->verify(self::signer()->sign($claims)));
    }

    public function testExpiryWithinLeewayIsAccepted(): void
    {
        self::assertSame(self::SUBJECT, $this->verify(self::signer()->sign(self::claims(['exp' => self::NOW - 30]))));
    }

    public function testAnyCertificateOfTheSetMayHaveSignedDuringKeyRotation(): void
    {
        $token = self::otherSigner()->sign(self::claims());

        self::assertSame(self::SUBJECT, $this->verify($token, [self::signer()->certificate, self::otherSigner()->certificate]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidClaims(): iterable
    {
        yield 'чужая аудитория' => [['aud' => 'another-client'], 'audience'];
        yield 'аудитория — список без нас' => [['aud' => ['a', 'b']], 'audience'];
        yield 'azp не наш' => [['azp' => 'another-client'], 'authorized_party'];
        yield 'истёк' => [['exp' => self::NOW - 61], 'expired'];
        yield 'exp строкой' => [['exp' => (string) (self::NOW + 600)], 'expired'];
        yield 'выпущен в будущем' => [['iat' => self::NOW + 120], 'issued_at'];
        yield 'неверный nonce' => [['nonce' => 'other-nonce'], 'nonce'];
        yield 'nonce нет' => [['nonce' => null], 'nonce'];
        yield 'издатель не из списка' => [['iss' => 'https://id.evil.example'], 'issuer'];
        yield 'sub пустой' => [['sub' => ''], 'subject'];
        yield 'sub не строка' => [['sub' => 42], 'subject'];
        yield 'sub длиннее 255' => [['sub' => str_repeat('a', 256)], 'subject'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidClaims')]
    public function testInvalidClaimsAreRejected(array $override, string $reason): void
    {
        $claims = array_filter(self::claims($override), static fn (mixed $value): bool => null !== $value);

        $this->assertRejected(self::signer()->sign($claims), $reason);
    }

    public function testSignatureByUnknownKeyIsRejected(): void
    {
        $this->assertRejected(self::otherSigner()->sign(self::claims()), 'signature');
    }

    public function testTamperedPayloadIsRejected(): void
    {
        [$header, , $signature] = explode('.', self::signer()->sign(self::claims()));
        $forged = $header.'.'.TestIdTokenSigner::encode(self::claims(['sub' => 'someone-else'])).'.'.$signature;

        $this->assertRejected($forged, 'signature');
    }

    public function testExpiredCertificateIsNotUsed(): void
    {
        $expiredSigner = new TestIdTokenSigner(1);
        $now = time() + 2 * 86400;
        $token = $expiredSigner->sign(self::claims(['exp' => $now + 600, 'iat' => $now]));
        $clock = new MockClock((new \DateTimeImmutable())->setTimestamp($now));

        $this->expectException(BankIdTokenInvalidException::class);
        $this->expectExceptionMessage('signature');
        (new IdTokenVerifier($clock))->verify($token, [$expiredSigner->certificate], self::ISSUERS, self::CLIENT_ID, self::NONCE);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function forbiddenAlgorithms(): iterable
    {
        yield 'none' => [['alg' => 'none', 'typ' => 'JWT']];
        yield 'HS256' => [['alg' => 'HS256', 'typ' => 'JWT']];
        yield 'RS512' => [['alg' => 'RS512']];
        yield 'без alg' => [['typ' => 'JWT']];
    }

    /**
     * @param array<string, mixed> $header
     */
    #[DataProvider('forbiddenAlgorithms')]
    public function testOnlyRs256IsAccepted(array $header): void
    {
        $this->assertRejected(self::signer()->sign(self::claims(), $header), 'algorithm');
    }

    public function testUnsignedTokenIsRejected(): void
    {
        $token = TestIdTokenSigner::encode(['alg' => 'RS256']).'.'.TestIdTokenSigner::encode(self::claims()).'.';

        $this->assertRejected($token, 'format');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'пусто' => [''];
        yield 'две части' => ['a.b'];
        yield 'четыре части' => ['a.b.c.d'];
        yield 'не base64url' => ['a+b.c/d.e=f'];
        yield 'payload — не JSON-объект' => [TestIdTokenSigner::base64Url('{"alg":"RS256"}').'.'.TestIdTokenSigner::base64Url('[1,2]').'.c2ln'];
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokenIsRejected(string $token): void
    {
        $this->assertRejected($token, 'format');
    }

    public function testExceptionDoesNotCarryTokenOrClaims(): void
    {
        $token = self::otherSigner()->sign(self::claims());

        try {
            $this->verify($token);
            self::fail('expected rejection');
        } catch (BankIdTokenInvalidException $exception) {
            self::assertStringNotContainsString(self::SUBJECT, $exception->getMessage());
            self::assertStringNotContainsString(self::NONCE, $exception->getMessage());
            self::assertStringNotContainsString(substr($token, 0, 20), $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @param list<\OpenSSLCertificate>|null $certificates
     */
    private function verify(string $token, ?array $certificates = null): string
    {
        return (new IdTokenVerifier(new MockClock((new \DateTimeImmutable())->setTimestamp(self::NOW))))
            ->verify($token, $certificates ?? [self::signer()->certificate], self::ISSUERS, self::CLIENT_ID, self::NONCE);
    }

    private function assertRejected(string $token, string $reason): void
    {
        try {
            $this->verify($token);
            self::fail('expected rejection: '.$reason);
        } catch (BankIdTokenInvalidException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function claims(array $override = []): array
    {
        return array_replace([
            'sub' => self::SUBJECT,
            'aud' => self::CLIENT_ID,
            'azp' => self::CLIENT_ID,
            'acr' => 'LoA3',
            'auth_time' => self::NOW - 10,
            'iss' => 'https://id.alfabank.ru/oidc',
            'exp' => self::NOW + 3600,
            'iat' => self::NOW,
            'nonce' => self::NONCE,
        ], $override);
    }

    private static function signer(): TestIdTokenSigner
    {
        return self::$signer ??= new TestIdTokenSigner();
    }

    private static function otherSigner(): TestIdTokenSigner
    {
        return self::$otherSigner ??= new TestIdTokenSigner();
    }
}
