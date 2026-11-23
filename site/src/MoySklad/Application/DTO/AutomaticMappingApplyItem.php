<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\MoySklad\Enum\AutomaticMappingApplyStatus;

final readonly class AutomaticMappingApplyItem
{
    public function __construct(public string $listingId, public AutomaticMappingApplyStatus $status, public ?string $reason = null, public ?string $revisionId = null)
    {
    }
}
