<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Wildberries;

interface WbCredentialProviderInterface
{
    /**
     * @return array{api_key: string}
     */
    public function read(string $companyId, string $connectionRef): array;
}
