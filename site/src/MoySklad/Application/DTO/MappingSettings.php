<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

final readonly class MappingSettings
{
    public function __construct(public bool $wbTrailingW = false, public int $version = 0)
    {
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->wbTrailingW, $this->version], \JSON_THROW_ON_ERROR));
    }
}
