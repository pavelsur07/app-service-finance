<?php

declare(strict_types=1);

namespace App\BankSync\Domain\OAuth;

use App\BankSync\Enum\BankCode;
use Webmozart\Assert\Assert;

/**
 * Начатый вход клиента в банк: живёт в сессии от редиректа до callback, одноразово.
 * `connectionId` — переподключаемое подключение или ID будущего (UUID v7, он же ключ
 * повтора: второй callback не создаст второе подключение).
 */
final readonly class PendingAuthorization
{
    public const TTL_SECONDS = 600;

    public function __construct(
        public string $state,
        public string $companyId,
        public BankCode $bankCode,
        public string $baseUrl,
        public string $connectionId,
        public bool $isNewConnection,
        #[\SensitiveParameter]
        public string $codeVerifier,
        public string $redirectUri,
        public \DateTimeImmutable $expiresAt,
    ) {
        Assert::uuid($state);
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * @return array<string, string|bool>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'company_id' => $this->companyId,
            'bank_code' => $this->bankCode->value,
            'base_url' => $this->baseUrl,
            'connection_id' => $this->connectionId,
            'is_new' => $this->isNewConnection,
            'code_verifier' => $this->codeVerifier,
            'redirect_uri' => $this->redirectUri,
            'expires_at' => $this->expiresAt->format(\DATE_ATOM),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        try {
            return new self(
                (string) $data['state'],
                (string) $data['company_id'],
                BankCode::from((string) $data['bank_code']),
                (string) $data['base_url'],
                (string) $data['connection_id'],
                (bool) $data['is_new'],
                (string) $data['code_verifier'],
                (string) $data['redirect_uri'],
                new \DateTimeImmutable((string) $data['expires_at']),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['companyId' => $this->companyId, 'bankCode' => $this->bankCode->value, 'connectionId' => $this->connectionId];
    }
}
