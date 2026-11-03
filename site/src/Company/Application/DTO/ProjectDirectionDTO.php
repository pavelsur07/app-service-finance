<?php

declare(strict_types=1);

namespace App\Company\Application\DTO;

/**
 * Проект ОПиУ компании для чужих модулей: level — 1 у корня.
 */
final readonly class ProjectDirectionDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public int $level,
    ) {
    }
}
