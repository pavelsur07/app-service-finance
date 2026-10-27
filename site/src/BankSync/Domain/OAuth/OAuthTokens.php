<?php

declare(strict_types=1);

namespace App\BankSync\Domain\OAuth;

use Webmozart\Assert\Assert;

/**
 * Пара токенов OAuth 2.0 подключения. Живёт в памяти процесса и в шифротексте
 * (`BankCredentialsCodec`); в логи и исключения не попадает.
 */
final readonly class OAuthTokens
{
    /**
     * @param string|null $refreshToken null — банк не выдал refresh (или не выдал новый при обновлении)
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        #[\SensitiveParameter]
        public ?string $refreshToken,
        public \DateTimeImmutable $accessExpiresAt,
    ) {
        Assert::notEmpty($accessToken);
        if (null !== $refreshToken) {
            Assert::notEmpty($refreshToken);
        }
    }

    public function accessExpiresWithin(\DateTimeImmutable $now, int $seconds): bool
    {
        return $this->accessExpiresAt <= $now->modify(sprintf('+%d seconds', $seconds));
    }

    /**
     * Ответ на обновление может не нести refresh: тогда прежний остаётся в силе.
     */
    public function withFallbackRefreshToken(#[\SensitiveParameter] ?string $previous): self
    {
        if (null !== $this->refreshToken || null === $previous) {
            return $this;
        }

        return new self($this->accessToken, $previous, $this->accessExpiresAt);
    }

    /**
     * @return array{accessExpiresAt: string, hasRefreshToken: bool}
     */
    public function __debugInfo(): array
    {
        return ['accessExpiresAt' => $this->accessExpiresAt->format(\DATE_ATOM), 'hasRefreshToken' => null !== $this->refreshToken];
    }
}
