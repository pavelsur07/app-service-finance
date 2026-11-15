<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

final readonly class AutomaticMappingCandidate
{
    public function __construct(public CatalogTargetDTO $target, public ?string $parentArticle = null, public bool $leaf = true)
    {
    }
}
