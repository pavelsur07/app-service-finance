<?php

declare(strict_types=1);

namespace App\Finance\Report;

use App\Finance\Enum\PLValueFormat;

final class PlComputedRow
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $code,
        public readonly string $name,
        public readonly int $level,
        public readonly string $type,
        public readonly PLValueFormat $format,
        public readonly float $rawValue,
        public readonly string $formatted,
    ) {
    }
}
