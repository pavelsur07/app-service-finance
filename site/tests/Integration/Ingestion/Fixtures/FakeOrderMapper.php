<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Application\DTO\MappedOrderBatch;
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

    /**
     * @var list<array{reason: string, hint: ?string, value?: ?string}>
     */
    private array $skipped = [];

    public function queue(MappedOrder ...$orders): void
    {
        $this->queued = array_values($orders);
    }

    /**
     * @param list<array{reason: string, hint: ?string, value?: ?string}> $skipped
     */
    public function queueSkipped(array $skipped): void
    {
        $this->skipped = $skipped;
    }

    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    public function resourceTypes(): array
    {
        return [self::RESOURCE_TYPE];
    }

    public function map(IngestRawRecord $rawRecord, iterable $rows): MappedOrderBatch
    {
        return new MappedOrderBatch($this->queued, $this->skipped);
    }
}
