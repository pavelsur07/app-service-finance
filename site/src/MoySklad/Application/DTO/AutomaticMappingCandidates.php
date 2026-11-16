<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use Webmozart\Assert\Assert;

final readonly class AutomaticMappingCandidates
{
    /** @param list<AutomaticMappingCandidate> $items */
    public function __construct(public array $items, public int $totalCount, public bool $truncated = false)
    {
        Assert::maxCount($items, 50);
        Assert::greaterThanEq($totalCount, count($items));
    }
}
