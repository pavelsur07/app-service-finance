<?php

declare(strict_types=1);

namespace App\BankSync\Domain\OAuth;

/**
 * PKCE (RFC 7636), только S256: `code_verifier` уходит банку при обмене кода, в URL
 * авторизации — лишь его хеш. Перехваченный код без verifier бесполезен.
 */
final readonly class Pkce
{
    public const METHOD = 'S256';

    private function __construct(
        #[\SensitiveParameter]
        public string $verifier,
        public string $challenge,
    ) {
    }

    public static function generate(): self
    {
        // 32 случайных байта → 43 символа base64url (минимум RFC 7636).
        $verifier = self::base64Url(random_bytes(32));

        return new self($verifier, self::challengeFor($verifier));
    }

    public static function challengeFor(#[\SensitiveParameter] string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return array{challenge: string}
     */
    public function __debugInfo(): array
    {
        return ['challenge' => $this->challenge];
    }
}
