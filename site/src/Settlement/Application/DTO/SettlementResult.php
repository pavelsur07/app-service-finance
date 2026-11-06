<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

final readonly class SettlementResult
{
    public function __construct(public string $documentId, public ?string $itemId, public int $version, public bool $replayed)
    {
    }
}
