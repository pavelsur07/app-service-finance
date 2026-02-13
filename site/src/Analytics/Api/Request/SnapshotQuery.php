<?php

namespace App\Analytics\Api\Request;

final readonly class SnapshotQuery
{
    public function __construct(
        public ?string $preset,
        public ?string $from,
        public ?string $to,
    ) {
    }
}

