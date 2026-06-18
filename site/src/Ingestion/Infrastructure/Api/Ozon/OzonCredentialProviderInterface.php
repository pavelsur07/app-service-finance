<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

interface OzonCredentialProviderInterface
{
    /**
     * @return array{api_key: string, client_id: ?string}
     */
    public function read(string $companyId, string $connectionRef): array;
}
