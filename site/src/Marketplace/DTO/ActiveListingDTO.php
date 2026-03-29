<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class ActiveListingDTO
{
    public function __construct(
        public string $id,
        public string $marketplace,
        public string $marketplaceSku,
    ) {}
}
