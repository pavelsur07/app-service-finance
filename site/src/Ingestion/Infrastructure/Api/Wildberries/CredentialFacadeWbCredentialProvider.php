<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Wildberries;

use App\Ingestion\Facade\CredentialFacade;

final readonly class CredentialFacadeWbCredentialProvider implements WbCredentialProviderInterface
{
    public function __construct(private CredentialFacade $credentialFacade)
    {
    }

    /**
     * @return array{api_key: string}
     */
    public function read(string $companyId, string $connectionRef): array
    {
        $payload = $this->credentialFacade->read($companyId, $connectionRef);

        return [
            'api_key' => (string) ($payload['api_key'] ?? ''),
        ];
    }
}
