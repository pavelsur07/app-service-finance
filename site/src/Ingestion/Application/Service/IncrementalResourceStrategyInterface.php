<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Enum\IngestSource;

interface IncrementalResourceStrategyInterface
{
    public function source(): IngestSource;

    public function resourceType(): string;

    /**
     * @param array{id: string, company_id: string, marketplace: string} $connection
     */
    public function supportsConnection(array $connection): bool;

    public function ensureCursor(string $companyId, string $connectionRef): void;

    public function cursorIsDue(string $cursorValue): bool;
}
