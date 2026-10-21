<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

/**
 * Статья компании без кода, которую можно сопоставить со статьёй шаблона.
 */
final readonly class PLTemplateMappingCandidate
{
    public function __construct(
        public string $id,
        public string $path,
    ) {
    }
}
