<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

/**
 * Настроенная среда Alfa ID (песочница или прод): адреса, пара приложения и сертификаты
 * проверки подписи `id_token`. Секрет приложения в дамп не попадает.
 */
final readonly class AlfaIdEnvironment
{
    /**
     * @param non-empty-list<\OpenSSLCertificate> $signingCertificates
     * @param non-empty-list<string> $issuers
     */
    public function __construct(
        public string $name,
        public string $baseUrl,
        public string $authorizeUrl,
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public array $signingCertificates,
        public array $issuers,
    ) {
    }

    public function tokenUrl(): string
    {
        return $this->baseUrl.'/oidc/token';
    }

    public function revokeUrl(): string
    {
        return $this->baseUrl.'/oidc/revoke';
    }

    public function consentsUrl(): string
    {
        return $this->baseUrl.'/oidc/client-consents';
    }

    /**
     * @return array{name: string, baseUrl: string, clientId: string}
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'baseUrl' => $this->baseUrl, 'clientId' => $this->clientId];
    }
}
