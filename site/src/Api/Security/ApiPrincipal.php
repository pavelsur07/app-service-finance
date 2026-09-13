<?php

declare(strict_types=1);

namespace App\Api\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/** Authenticated company/key identity, never the user who created the key. */
final readonly class ApiPrincipal implements UserInterface
{
    /**
     * @param list<string> $preparedScopes
     * @param list<string> $effectiveScopes
     */
    public function __construct(
        public string $companyId,
        public int $publicCompanyId,
        public string $keyId,
        public \DateTimeImmutable $expiresAt,
        public array $preparedScopes = [],
        public array $effectiveScopes = [],
    ) {
    }

    public function getUserIdentifier(): string
    {
        return 'api-key:'.$this->keyId;
    }

    public function getRoles(): array
    {
        return ['ROLE_EXTERNAL_API'];
    }

    public function eraseCredentials(): void
    {
    }

    /** @return array{company_id: int, key_id: string, expires_at: string, prepared_scopes: list<string>, effective_scopes: list<string>} */
    public function connectionDetails(): array
    {
        return [
            'company_id' => $this->publicCompanyId,
            'key_id' => $this->keyId,
            'expires_at' => $this->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'prepared_scopes' => $this->preparedScopes,
            'effective_scopes' => $this->effectiveScopes,
        ];
    }
}
