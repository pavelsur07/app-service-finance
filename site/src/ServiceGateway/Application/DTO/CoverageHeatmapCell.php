<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class CoverageHeatmapCell
{
    public const OK = 'ok';
    public const PARTIAL = 'partial';
    public const ERR = 'err';
    public const EMPTY = 'empty';
    public const FUTURE = 'future';

    public function __construct(
        public string $state,
        public string $label,
        public string $hint,
    ) {
    }
}
