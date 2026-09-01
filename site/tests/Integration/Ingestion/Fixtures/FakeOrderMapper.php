<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Domain\Contract\OrderMapperInterface;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;

final class FakeOrderMapper implements OrderMapperInterface
{
    public const RESOURCE_TYPE = 'fake_orders';

    /**
     * @var list<MappedOrder>
     */
    private array $queued = [];

    public function queue(MappedOrder ...$orders): void
    {
        $this->queued = array_values($orders);
    }

    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    public function resourceTypes(): array
    {
        return [self::RESOURCE_TYPE];
    }

    public function map(IngestRawRecord $rawRecord, iterable $rows): array
    {
        return $this->queued;
    }
}
