<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class ExternalMappingSourceVersion
{
    public function __construct(public string $fingerprint, public int $total)
    {
    }
}
