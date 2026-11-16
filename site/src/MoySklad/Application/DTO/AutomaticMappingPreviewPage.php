<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\Marketplace\DTO\ListingMetaPageDTO;

final readonly class AutomaticMappingPreviewPage
{
    /** @param array<string,AutomaticMappingDecision> $decisions
     * @param array<string,int> $counts
     */
    public function __construct(
        public ListingMetaPageDTO $listingsPage, public array $decisions, public array $counts,
        public string $q, public ?string $sizeCharacteristicId,
        /** @var array<string,list<string>> */
        public array $barcodes = [], public ?MappingSettings $settings = null,
    ) {
    }
}
