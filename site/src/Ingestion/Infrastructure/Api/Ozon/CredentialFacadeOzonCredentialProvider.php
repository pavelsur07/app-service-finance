<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Facade\CredentialFacade;

final readonly class CredentialFacadeOzonCredentialProvider implements OzonCredentialProviderInterface
{
    public function __construct(private CredentialFacade $credentialFacade)
    {
    }

    /**
     * @return array{api_key: string, client_id: ?string}
     */
    public function read(string $companyId, string $connectionRef): array
    {
        /** @var array{api_key: string, client_id: ?string} $payload */
        $payload = $this->credentialFacade->read($companyId, $connectionRef);

        return $payload;
    }
}
