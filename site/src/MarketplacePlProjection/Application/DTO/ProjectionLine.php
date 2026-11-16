<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Application\DTO;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplacePlProjection\Enum\ProjectionStatus;

/** Σ канона за период по каналу, виду, коду источника и статье, на которую её ведёт правило. */
final readonly class ProjectionLine
{
    public function __construct(
        public string $channel,
        public OperationKind $kind,
        public string $sourceType,
        public ProjectionStatus $status,
        public ?string $plCategoryId,
        public int $amountMinor,
    ) {
    }
}
