<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

final readonly class ListingTagsExportRow
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $listingId,
        public string $marketplace,
        public ?string $supplierSku,
        public string $marketplaceSku,
        public string $size,
        public ?string $name,
        public array $tags,
    ) {
    }
}
