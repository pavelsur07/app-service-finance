<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

final readonly class PnlProgressView
{
    public function __construct(
        public int $pending,
        public int $rebuilding,
        public int $done,
        public int $failed,
        public int $blockedByClose,
    ) {
    }
}
