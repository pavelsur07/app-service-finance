<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

final readonly class OzonRealizationIntegrity
{
    public function __construct(
        public string $rawHash,
        public int $sourceRows,
        public int $expectedRows,
        public int $actualRows,
        public string $sales,
        public string $returns,
        public bool $matches,
        public bool $doubled,
    ) {
    }
}
