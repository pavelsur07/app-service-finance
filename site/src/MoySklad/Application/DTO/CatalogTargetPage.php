<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

final readonly class CatalogTargetPage
{
    /** @param list<CatalogTargetDTO> $items */
    public function __construct(public array $items, public int $page, public int $totalItems, public int $perPage = 20)
    {
    }
}
