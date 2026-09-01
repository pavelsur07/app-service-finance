<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use App\Ingestion\Enum\IngestOrderScheme;
use Webmozart\Assert\Assert;

/**
 * Заказ в нормализованном виде — то, что маппер отдаёт апсерту.
 *
 * Отдельный тип от {@see MappedTransaction}: тот требует type, direction, money
 * и operationGroupId, потому что описывает денежную проводку. Заказ — не
 * проводка: у него позиции, количества, статус и своя жизнь во времени.
 */
final readonly class MappedOrder
{
    /**
     * @param list<MappedOrderItem> $items
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $externalId,
        public IngestOrderScheme $scheme,
        public \DateTimeImmutable $orderedAt,
        public string $rawStatus,
        public array $items,
        public ?string $externalOrderId = null,
        public ?string $rawSubstatus = null,
        public array $attributes = [],
    ) {
        Assert::notEmpty($externalId);
        Assert::notEmpty($rawStatus);
    }
}
