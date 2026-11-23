<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Application\DTO;

use App\MarketplaceOperations\Enum\OperationKind;

/** Σ канона компании за день по кабинету, виду и коду источника — вход проекции ОПиУ. */
final readonly class CanonPlSum
{
    public function __construct(
        public string $channel,
        public string $connectionRef,
        public string $date,
        public OperationKind $kind,
        public string $sourceType,
        public int $amountMinor,
    ) {
    }
}
