<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\OAuth;

use App\BankSync\Exception\BankIdTokenInvalidException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Проверка `id_token` OpenID Connect (JWS compact, только RS256) по сертификатам банка из
 * файла: JWKS у банка может не быть, поэтому подпись проверяется каждым действующим
 * сертификатом набора — на время смены ключа банком в файле лежат оба.
 *
 * Claims: `iss` из списка среды, `aud` содержит наш `client_id`, `azp` (если есть) равен
 * ему, `exp` в будущем и `iat` не в будущем (допуск 60 с), `nonce` совпадает, `sub`
 * непустой. Отказ — `BankIdTokenInvalidException` с машинной причиной, без токена.
 */
final readonly class IdTokenVerifier
{
    private const ALGORITHM = 'RS256';
    private const LEEWAY_SECONDS = 60;
    private const MAX_SUBJECT_LENGTH = 255;

    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * @param list<\OpenSSLCertificate> $certificates
     * @param list<string>              $allowedIssuers
     *
     * @return string `sub` — идентификатор пользователя у банка
     *
     * @throws BankIdTokenInvalidException
     */
    public function verify(
        #[\SensitiveParameter] string $idToken,
        array $certificates,
        array $allowedIssuers,
        string $clientId,
        #[\SensitiveParameter] string $nonce,
    ): string {
        $parts = explode('.', $idToken);
        if (3 !== \count($parts)) {
            throw new BankIdTokenInvalidException('format');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->decodeJson($encodedHeader);
        $payload = $this->decodeJson($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);
        if (null === $header || null === $payload || null === $signature || '' === $signature) {
            throw new BankIdTokenInvalidException('format');
        }
        // Алгоритм — до подписи: `none` и HS256 с открытым ключом вместо секрета не проходят.
        if (self::ALGORITHM !== ($header['alg'] ?? null)) {
            throw new BankIdTokenInvalidException('algorithm');
        }
        if (!$this->signatureMatches($encodedHeader.'.'.$encodedPayload, $signature, $certificates)) {
            throw new BankIdTokenInvalidException('signature');
        }

        $this->assertClaims($payload, $allowedIssuers, $clientId, $nonce);

        return $payload['sub'];
    }

    /**
     * @param array<mixed>  $payload
     * @param list<string>  $allowedIssuers
     *
     * @phpstan-assert array{sub: non-empty-string} $payload
     */
    private function assertClaims(array $payload, array $allowedIssuers, string $clientId, #[\SensitiveParameter] string $nonce): void
    {
        if (!\in_array($payload['iss'] ?? null, $allowedIssuers, true)) {
            throw new BankIdTokenInvalidException('issuer');
        }

        $audience = $payload['aud'] ?? null;
        $audiences = \is_string($audience) ? [$audience] : (\is_array($audience) ? $audience : []);
        if (!\in_array($clientId, $audiences, true)) {
            throw new BankIdTokenInvalidException('audience');
        }
        if (\array_key_exists('azp', $payload) && $payload['azp'] !== $clientId) {
            throw new BankIdTokenInvalidException('authorized_party');
        }

        $now = $this->clock->now()->getTimestamp();
        $expiresAt = $payload['exp'] ?? null;
        if (!\is_int($expiresAt) || $expiresAt <= $now - self::LEEWAY_SECONDS) {
            throw new BankIdTokenInvalidException('expired');
        }
        $issuedAt = $payload['iat'] ?? null;
        if (!\is_int($issuedAt) || $issuedAt > $now + self::LEEWAY_SECONDS) {
            throw new BankIdTokenInvalidException('issued_at');
        }

        $tokenNonce = $payload['nonce'] ?? null;
        if (!\is_string($tokenNonce) || '' === $nonce || !hash_equals($nonce, $tokenNonce)) {
            throw new BankIdTokenInvalidException('nonce');
        }

        $subject = $payload['sub'] ?? null;
        if (!\is_string($subject) || '' === $subject || \strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new BankIdTokenInvalidException('subject');
        }
    }

    /**
     * @param list<\OpenSSLCertificate> $certificates
     */
    private function signatureMatches(string $signedData, string $signature, array $certificates): bool
    {
        $now = $this->clock->now()->getTimestamp();
        foreach ($certificates as $certificate) {
            $info = openssl_x509_parse($certificate);
            $validTo = false === $info ? null : ($info['validTo_time_t'] ?? null);
            if (!\is_int($validTo) || $validTo < $now) {
                continue;
            }
            if (1 === openssl_verify($signedData, $signature, $certificate, \OPENSSL_ALGO_SHA256)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeJson(string $encoded): ?array
    {
        $json = $this->base64UrlDecode($encoded);
        if (null === $json) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    private function base64UrlDecode(string $encoded): ?string
    {
        if ('' === $encoded || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            return null;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
