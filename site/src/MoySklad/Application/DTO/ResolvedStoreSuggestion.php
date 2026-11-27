<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

/** Предложение назначения склада, привязанное к счёту компании: target — id счёта или «excluded». */
final readonly class ResolvedStoreSuggestion
{
    public function __construct(
        public string $target,
        public string $label,
        public string $reason,
        public ?string $warning,
        public string $source,
    ) {
    }
}
