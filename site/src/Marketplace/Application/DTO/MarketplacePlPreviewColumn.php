<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\PlPreviewColumnKind;

final readonly class MarketplacePlPreviewColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public PlPreviewColumnKind $kind,
    ) {
    }
}
