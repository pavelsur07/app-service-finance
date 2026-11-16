<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\Marketplace\DTO\ListingMetaDTO;

final readonly class AutomaticMappingPreviewItem
{
    public string $listingId;
    public ?string $targetType;
    public ?string $targetExternalId;

    public function __construct(public ListingMetaDTO $source, public string $sourceFingerprint, public string $requestId, public AutomaticMappingDecision $decision, /** @var list<string> */ public array $barcodes = [])
    {
        $this->listingId = $source->id;
        $this->targetType = $decision->target?->type;
        $this->targetExternalId = $decision->target?->externalId;
    }

    /** @return list<string> */
    public function getSourceQuantities(): array
    {
        return \App\MoySklad\Domain\AutomaticListingMappingPolicy::quantities($this->source->title);
    }
}
