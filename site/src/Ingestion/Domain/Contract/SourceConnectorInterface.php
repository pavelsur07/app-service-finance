<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Contract;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PullResult;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\DTO\PushResult;
use App\Ingestion\Application\DTO\ShopDescriptor;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Enum\IngestSource;

interface SourceConnectorInterface
{
    public function source(): IngestSource;

    /**
     * @return list<Capability>
     */
    public function capabilities(): array;

    /**
     * @return list<ShopDescriptor>
     */
    public function discoverShops(string $companyId, string $connectionRef): array;

    public function pull(PullRequest $request): PullResult;

    public function push(PushRequest $request): PushResult;
}
